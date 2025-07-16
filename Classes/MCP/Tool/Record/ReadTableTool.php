<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Tool for reading records from TYPO3 tables
 */
class ReadTableTool extends AbstractRecordTool
{
    /**
     * Get the tool type
     */
    public function getToolType(): string
    {
        return 'read';
    }

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Read records from TYPO3 tables with filtering, pagination, and relation embedding. For page content, use pid filter instead of individual record lookups.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table name to read records from',
                    ],
                    'pid' => [
                        'type' => 'integer',
                        'description' => 'Filter by page ID (recommended - use this instead of individual record lookups)',
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'Filter by record UID (use pid filter instead to read multiple records of a page)',
                    ],
                    'where' => [
                        'type' => 'string',
                        'description' => 'SQL WHERE condition for filtering (without the WHERE keyword)',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of records to return (default: 20)',
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'Offset for pagination',
                    ],
                    'includeRelations' => [
                        'type' => 'boolean',
                        'description' => 'Whether to include related records',
                    ],
                ],
            ],
            'examples' => [
                [
                    'description' => 'Read all content elements on a page',
                    'parameters' => [
                        'table' => 'tt_content',
                        'pid' => 123,
                        'includeRelations' => true
                    ]
                ],
                [
                    'description' => 'Read a specific record',
                    'parameters' => [
                        'table' => 'pages',
                        'uid' => 1
                    ]
                ]
            ]
        ];
    }

    /**
     * Execute the tool
     */
    public function execute(array $params): CallToolResult
    {
        $table = $params['table'] ?? '';
        $pid = isset($params['pid']) ? (int)$params['pid'] : null;
        $uid = isset($params['uid']) ? (int)$params['uid'] : null;
        $condition = $params['where'] ?? '';
        $includeHidden = $params['includeHidden'] ?? false;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 20;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
        $includeRelations = $params['includeRelations'] ?? true;

        if (empty($table)) {
            return $this->createErrorResult('Table name is required');
        }

        // Validate table access using TableAccessService
        try {
            $this->ensureTableAccess($table, 'read');
        } catch (\InvalidArgumentException $e) {
            return $this->createErrorResult($e->getMessage());
        }

        try {
            // Get records from the table
            $result = $this->getRecords(
                $table,
                $pid,
                $uid,
                $condition,
                $includeHidden,
                $limit,
                $offset
            );

            // Include related records if requested
            if ($includeRelations) {
                $result = $this->includeRelations($result, $table);
            }

            // Return the result as JSON
            return $this->createJsonResult($result);
        } catch (\Throwable $e) {
            return $this->createErrorResult('Error reading records: ' . $e->getMessage());
        }
    }

    /**
     * Get records from a table
     */
    protected function getRecords(
        string $table,
        ?int $pid,
        ?int $uid,
        string $condition,
        bool $includeHidden,
        int $limit,
        int $offset
    ): array {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));

        // Don't include hidden records unless explicitly requested
        if (!$includeHidden) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }

        // Select all fields
        $queryBuilder->select('*')
            ->from($table);

        // Filter by pid if specified
        if ($pid !== null && $this->tableHasPidField($table)) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER))
            );
        }

        // Filter by uid if specified
        if ($uid !== null) {
            // For workspace transparency, we need to handle both cases:
            // 1. The UID is a workspace UID (for new records)
            // 2. The UID is a live UID (for existing records with workspace versions)

            $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
            if ($currentWorkspace > 0) {
                // In workspace context, check both live and workspace UIDs
                // The WorkspaceDeletePlaceholderRestriction will handle delete placeholders automatically
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
                    )
                );
            } else {
                // In live workspace, just filter by UID
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
                );
            }
        }

        // Apply custom condition if specified
        if (!empty($condition)) {
            // Basic SQL injection protection
            $disallowedKeywords = ['DROP', 'DELETE', 'UPDATE', 'INSERT', 'TRUNCATE', 'ALTER', 'CREATE'];
            $containsDisallowed = false;

            foreach ($disallowedKeywords as $keyword) {
                if (stripos($condition, $keyword) !== false) {
                    $containsDisallowed = true;
                    break;
                }
            }

            if ($containsDisallowed) {
                throw new \InvalidArgumentException('The condition contains disallowed SQL keywords');
            }

            // Add the condition directly
            $queryBuilder->andWhere($condition);
        }

        // Apply default sorting from TCA
        $this->applyDefaultSorting($queryBuilder, $table);

        // Apply pagination
        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);

            if ($offset > 0) {
                $queryBuilder->setFirstResult($offset);
            }
        }

        // Get total count (without pagination)
        $countQueryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $countQueryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));
        
        if (!$includeHidden) {
            $countQueryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }
        
        $countQueryBuilder->count('uid')->from($table);
        
        // Apply the same WHERE conditions as the main query
        if ($pid !== null && $this->tableHasPidField($table)) {
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pid, ParameterType::INTEGER))
            );
        }
        
        if ($uid !== null) {
            // Apply the same UID filtering logic for count query
            $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
            if ($currentWorkspace > 0) {
                // In workspace context, check both live and workspace UIDs
                // The WorkspaceDeletePlaceholderRestriction will handle delete placeholders automatically
                $countQueryBuilder->andWhere(
                    $countQueryBuilder->expr()->or(
                        $countQueryBuilder->expr()->eq('uid', $countQueryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                        $countQueryBuilder->expr()->eq('t3ver_oid', $countQueryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
                    )
                );
            } else {
                // In live workspace, just filter by UID
                $countQueryBuilder->andWhere(
                    $countQueryBuilder->expr()->eq('uid', $countQueryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
                );
            }
        }
        
        if (!empty($condition)) {
            $countQueryBuilder->andWhere($condition);
        }
        
        $totalCount = $countQueryBuilder->executeQuery()->fetchOne();

        // Execute the query
        $records = $queryBuilder->executeQuery()->fetchAllAssociative();

        // Process records to handle binary data, convert types, and filter default values
        $processedRecords = [];
        foreach ($records as $record) {
            $processedRecord = $this->processRecord($record, $table);
            $processedRecords[] = $processedRecord;
        }

        // Return the result with metadata
        return [
            'table' => $table,
            'tableLabel' => $this->getTableLabel($table),
            'records' => $processedRecords,
            'total' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + count($records)) < $totalCount,
        ];
    }

    /**
     * Process a record
     */
    protected function processRecord(array $record, string $table, array $fields = []): array
    {
        $processedRecord = [];
        
        // For workspace transparency, replace workspace UID with live UID
        if (isset($record['t3ver_oid']) && $record['t3ver_oid'] > 0) {
            // This is a workspace version of an existing record - use the live UID instead
            $record['uid'] = $record['t3ver_oid'];
        } elseif (isset($record['t3ver_state']) && $record['t3ver_state'] == 1) {
            // This is a new record in workspace - keep its UID as is
            // New records don't have a live counterpart until published
            // No change needed
        }
        
        // Get essential fields using TableAccessService
        $essentialFields = $this->tableAccessService->getEssentialFields($table);
        
        // Get type-specific fields if a type field exists
        $typeField = $this->tableAccessService->getTypeFieldName($table);
        $typeSpecificFields = [];
        $hasValidTypeConfig = false;

        if ($typeField && isset($record[$typeField])) {
            $recordType = (string)$record[$typeField];
            $typeSpecificFields = $this->tableAccessService->getFieldNamesForType($table, $recordType);

            // The TcaSchemaFactory will handle type validation internally
            // If the type is valid, we'll get the appropriate fields
            // If not, we'll get a reasonable fallback
            $hasValidTypeConfig = !empty($typeSpecificFields);
        }
        
        // Process each field
        foreach ($record as $field => $value) {
            // Always include essential fields
            if (in_array($field, $essentialFields)) {
                $processedRecord[$field] = $this->convertFieldValue($table, $field, $value);
                continue;
            }
            
            // If specific fields were requested, only include those
            if (!empty($fields) && !in_array($field, $fields)) {
                continue;
            }
            
            // Skip fields not relevant to this record type (only if we have a valid type configuration)
            if ($hasValidTypeConfig && !empty($typeSpecificFields) && !in_array($field, $typeSpecificFields)) {
                continue;
            }
            
            // Include the field
            $processedRecord[$field] = $this->convertFieldValue($table, $field, $value);
        }
        
        return $processedRecord;
    }

    /**
     * Convert a field value to the appropriate type
     */
    protected function convertFieldValue(string $table, string $field, $value)
    {
        // Skip null values
        if ($value === null) {
            return null;
        }
        
        // Convert FlexForm XML to JSON
        if ($this->tableAccessService->isFlexFormField($table, $field) && is_string($value) && !empty($value) && strpos($value, '<?xml') === 0) {
            try {
                // Use TYPO3's FlexFormService to convert XML to array
                $flexFormService = GeneralUtility::makeInstance(FlexFormService::class);
                $flexFormArray = $flexFormService->convertFlexFormContentToArray($value);
                
                // Simplify the structure for easier use in LLMs
                $result = [];
                $settings = [];
                
                // Process each field and organize settings
                foreach ($flexFormArray as $key => $val) {
                    // Check if this is a settings field (key starts with "settings")
                    if (strpos($key, 'settings') === 0 && strlen($key) > 8) {
                        // Extract the setting name (remove "settings" prefix)
                        $settingName = substr($key, 8);
                        // Convert first character to lowercase if it's uppercase
                        if (ctype_upper($settingName[0])) {
                            $settingName = lcfirst($settingName);
                        }
                        $settings[$settingName] = $val;
                    } else {
                        $result[$key] = $val;
                    }
                }
                
                // Add settings to result if any were found
                if (!empty($settings)) {
                    $result['settings'] = $settings;
                }
                
                return $result;
            } catch (\Exception $e) {
                // If parsing fails, return an empty array
                return [];
            }
        }
        
        // Convert JSON strings to arrays
        if (is_string($value) && strpos($value, '{') === 0) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        
        // Convert timestamps to ISO 8601 dates
        if (is_numeric($value) && $this->isDateField($table, $field)) {
            if ($value > 0) {
                $dateTime = new \DateTime('@' . $value);
                $dateTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                return $dateTime->format('c');
            }
            return null;
        }
        
        return $value;
    }
    
    /**
     * Check if a field is a date field
     */
    protected function isDateField(string $table, string $field): bool
    {
        return $this->tableAccessService->isDateField($table, $field);
    }


    /**
     * Check if a value is the default value for a field
     */
    protected function isDefaultValue(string $table, string $field, $value): bool
    {
        // Skip if TCA doesn't exist for this table
        if (!isset($GLOBALS['TCA'][$table]['columns'][$field])) {
            return false;
        }

        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];
        $defaultValue = $fieldConfig['default'] ?? null;

        // Check for empty values
        if (($value === '' || $value === null || $value === 0 || $value === '0') &&
            ($defaultValue === '' || $defaultValue === null || $defaultValue === 0 || $defaultValue === '0')) {
            return true;
        }

        // Check for explicit default values
        if ($value === $defaultValue) {
            return true;
        }

        // Special handling for different field types
        switch ($fieldConfig['type'] ?? '') {
            case 'check':
                // For checkbox fields, 0 is usually the default
                if ($value === 0 || $value === '0') {
                    return true;
                }
                break;

            case 'select':
            case 'group':
            case 'inline':
                // For relation fields, empty string or 0 usually means "no relation"
                if ($value === '' || $value === 0 || $value === '0') {
                    return true;
                }
                break;
        }

        return false;
    }

    /**
     * Apply default sorting from TCA
     */
    protected function applyDefaultSorting(QueryBuilder $queryBuilder, string $table): void
    {
        // Check for sortby field
        $sortbyField = $this->tableAccessService->getSortingFieldName($table);
        if ($sortbyField) {
            $queryBuilder->orderBy($sortbyField, 'ASC');
            return;
        }

        // Check for default_sortby
        $defaultSorting = $this->tableAccessService->parseDefaultSorting($table);
        if (!empty($defaultSorting)) {
            foreach ($defaultSorting as $sortConfig) {
                $queryBuilder->addOrderBy($sortConfig['field'], $sortConfig['direction']);
            }
            return;
        }

        // Default to ordering by UID
        $queryBuilder->orderBy('uid', 'ASC');
    }

    /**
     * Include related records in the result
     */
    protected function includeRelations(array $result, string $table): array
    {
        if (empty($result['records'])) {
            return $result;
        }

        $tca = $GLOBALS['TCA'][$table] ?? [];
        if (empty($tca['columns'])) {
            return $result;
        }

        // Get all record UIDs
        $recordUids = array_column($result['records'], 'uid');

        // Process each field that might contain relations
        foreach ($tca['columns'] as $fieldName => $fieldConfig) {
            $fieldType = $fieldConfig['config']['type'] ?? '';

            // Handle select fields with foreign_table
            if ($fieldType === 'select') {
                // Check if this is a foreign table relation
                if (!empty($fieldConfig['config']['foreign_table'])) {
                    $foreignTable = $fieldConfig['config']['foreign_table'];

                    // Skip if the foreign table doesn't exist or isn't accessible
                    if (!$this->tableAccessService->canAccessTable($foreignTable)) {
                        continue;
                    }

                    // Get all values for this field across all records
                    $fieldValues = [];
                    foreach ($result['records'] as $record) {
                        if (!empty($record[$fieldName])) {
                            $values = GeneralUtility::intExplode(',', $record[$fieldName], true);
                            $fieldValues = array_merge($fieldValues, $values);
                        }
                    }

                    // Skip if no values
                    if (empty($fieldValues)) {
                        continue;
                    }

                    // Get related records
                    $relatedRecords = $this->getRelatedRecords($foreignTable, $fieldValues);

                    // Add related records to each record
                    foreach ($result['records'] as &$record) {
                        if (!empty($record[$fieldName])) {
                            $values = GeneralUtility::intExplode(',', $record[$fieldName], true);
                            $recordRelations = [];

                            foreach ($values as $value) {
                                if (isset($relatedRecords[$value])) {
                                    $recordRelations[] = $relatedRecords[$value];
                                }
                            }

                            // Replace the field value with the related records
                            if (!empty($recordRelations)) {
                                $record[$fieldName] = $recordRelations;
                            }
                        }
                    }
                }
                // Handle static items (options from TCA, not from a foreign table)
                else if (!empty($fieldConfig['config']['items'])) {
                    // Process each record
                    foreach ($result['records'] as &$record) {
                        if (isset($record[$fieldName]) && $record[$fieldName] !== '' && $record[$fieldName] !== null) {
                            $values = GeneralUtility::trimExplode(',', (string)$record[$fieldName], true);
                            $selectedItems = [];

                            // Find the selected items
                            foreach ($fieldConfig['config']['items'] as $item) {
                                // Handle new associative array format (TYPO3 v12+)
                                if (is_array($item) && isset($item['value'])) {
                                    $itemValue = $item['value'];

                                    // Skip divider items
                                    if ($itemValue === '--div--') {
                                        continue;
                                    }

                                    if (in_array($itemValue, $values)) {
                                        $itemLabel = $item['label'] ?? $itemValue;
                                        if (is_string($itemLabel) && strpos($itemLabel, 'LLL:') === 0) {
                                            $itemLabel = $this->translateLabel($itemLabel);
                                        }

                                        $selectedItems[] = [
                                            'value' => $itemValue,
                                            'label' => $itemLabel,
                                            'icon' => $item['icon'] ?? null,
                                            'group' => $item['group'] ?? null
                                        ];
                                    }
                                }
                                // Handle old indexed array format
                                else if (is_array($item) && isset($item[1])) {
                                    $itemValue = $item[1];

                                    // Skip divider items
                                    if ($itemValue === '--div--') {
                                        continue;
                                    }

                                    if (in_array($itemValue, $values)) {
                                        $itemLabel = $item[0] ?? $itemValue;
                                        if (is_string($itemLabel) && strpos($itemLabel, 'LLL:') === 0) {
                                            $itemLabel = $this->translateLabel($itemLabel);
                                        }

                                        $selectedItems[] = [
                                            'value' => $itemValue,
                                            'label' => $itemLabel,
                                            'icon' => $item[2] ?? null
                                        ];
                                    }
                                }
                            }

                            // Replace the field value with the selected items
                            if (!empty($selectedItems)) {
                                $record[$fieldName] = $selectedItems;
                            }
                        }
                    }
                }
            }

            // Handle inline fields
            if ($fieldType === 'inline' && !empty($fieldConfig['config']['foreign_table'])) {
                $foreignTable = $fieldConfig['config']['foreign_table'];
                $foreignField = $fieldConfig['config']['foreign_field'] ?? '';

                // Skip if the foreign table isn't accessible or no foreign field
                if (!$this->tableAccessService->canAccessTable($foreignTable) || empty($foreignField)) {
                    continue;
                }

                // Get all related records
                $relatedRecords = $this->getInlineRelatedRecords($foreignTable, $foreignField, $recordUids);

                // Group related records by parent record
                $groupedRecords = [];
                foreach ($relatedRecords as $relatedRecord) {
                    $parentUid = $relatedRecord[$foreignField] ?? null;
                    if ($parentUid !== null) {
                        if (!isset($groupedRecords[$parentUid])) {
                            $groupedRecords[$parentUid] = [];
                        }
                        $groupedRecords[$parentUid][] = $relatedRecord;
                    }
                }

                // Add related records to each record
                foreach ($result['records'] as &$record) {
                    $uid = $record['uid'] ?? null;
                    if ($uid !== null && isset($groupedRecords[$uid])) {
                        $record[$fieldName] = $groupedRecords[$uid];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get related records for a select field
     */
    protected function getRelatedRecords(string $table, array $uids): array
    {
        if (empty($uids)) {
            return [];
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0))
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class));

        // Select all fields
        $records = $queryBuilder->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($uids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Index records by UID and process for workspace transparency
        $indexedRecords = [];
        foreach ($records as $record) {
            // Process the record to use live UIDs
            $processedRecord = $this->processRecord($record, $table);
            // Index by the (potentially modified) UID
            $indexedRecords[$processedRecord['uid']] = $processedRecord;
        }

        return $indexedRecords;
    }

    /**
     * Get inline related records
     */
    protected function getInlineRelatedRecords(string $table, string $foreignField, array $parentUids): array
    {
        if (empty($parentUids)) {
            return [];
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0))
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class));

        // Select all fields
        $records = $queryBuilder->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in(
                    $foreignField,
                    $queryBuilder->createNamedParameter($parentUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Process records for workspace transparency
        $processedRecords = [];
        foreach ($records as $record) {
            $processedRecords[] = $this->processRecord($record, $table);
        }

        return $processedRecords;
    }

    /**
     * Check if a table has a pid field
     */
    protected function tableHasPidField(string $table): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }

        // Most tables in TYPO3 have a pid field, but some system tables don't
        // We could check the actual database schema, but for simplicity we'll use a heuristic

        // These tables definitely don't have a pid field
        $tablesWithoutPid = [
            'sys_registry', 'sys_log', 'sys_history', 'sys_file', 'be_sessions', 'fe_sessions'
        ];

        if (in_array($table, $tablesWithoutPid)) {
            return false;
        }

        return true;
    }

}
