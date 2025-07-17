<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Doctrine\DBAL\ParameterType;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
                ],
            ],
            'examples' => [
                [
                    'description' => 'Read all content elements on a page',
                    'parameters' => [
                        'table' => 'tt_content',
                        'pid' => 123
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
        $limit = isset($params['limit']) ? (int)$params['limit'] : 20;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;

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
                $limit,
                $offset
            );

            // Include related records
            $result = $this->includeRelations($result, $table);

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

        // Always include hidden records (like the TYPO3 backend does)

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
        
        // Check if this is an integer field based on TCA eval rules or select field with integer values
        $fieldConfig = $this->tableAccessService->getFieldConfig($table, $field);
        if ($fieldConfig) {
            // Check eval rules for int
            if (isset($fieldConfig['config']['eval']) && strpos($fieldConfig['config']['eval'], 'int') !== false) {
                return (int)$value;
            }
            
            // Check if it's a select field with numeric string that should be integer
            if (isset($fieldConfig['config']['type']) && $fieldConfig['config']['type'] === 'select') {
                // If the value is numeric, check if this field typically uses integers
                if (is_numeric($value)) {
                    // Special handling for common integer fields
                    if (in_array($field, ['type', 'sys_language_uid', 'colPos', 'layout', 'frame_class', 'space_before_class', 'space_after_class', 'header_layout'])) {
                        return (int)$value;
                    }
                    
                    // Check if ALL items use integer values (not just one)
                    if (!empty($fieldConfig['config']['items'])) {
                        $allIntegers = true;
                        $hasItems = false;
                        
                        foreach ($fieldConfig['config']['items'] as $item) {
                            $itemValue = null;
                            if (isset($item['value'])) {
                                $itemValue = $item['value'];
                            } elseif (isset($item[1])) {
                                $itemValue = $item[1];
                            }
                            
                            if ($itemValue !== null && $itemValue !== '--div--') {
                                $hasItems = true;
                                if (!is_int($itemValue) && !ctype_digit((string)$itemValue)) {
                                    $allIntegers = false;
                                    break;
                                }
                            }
                        }
                        
                        // Only convert if all items are integers
                        if ($hasItems && $allIntegers) {
                            return (int)$value;
                        }
                    }
                }
            }
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
        if (is_numeric($value) && $this->tableAccessService->isDateField($table, $field)) {
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

            match ($fieldType) {
                'select', 'category' => $this->includeSelectRelations($result['records'], $fieldName, $fieldConfig, $table),
                'inline' => $this->includeInlineRelations($result['records'], $fieldName, $fieldConfig, $recordUids),
                default => null,
            };
        }

        return $result;
    }

    /**
     * Include select and category field relations
     */
    protected function includeSelectRelations(array &$records, string $fieldName, array $fieldConfig, string $table): void
    {
        // Check if this is a foreign table relation
        if (!empty($fieldConfig['config']['foreign_table'])) {
            $foreignTable = $fieldConfig['config']['foreign_table'];

            // Skip if the foreign table doesn't exist or isn't accessible
            if (!$this->tableAccessService->canAccessTable($foreignTable)) {
                return;
            }

            // Check if this uses MM relations
            if (!empty($fieldConfig['config']['MM'])) {
                $this->includeMmRelations($records, $fieldName, $fieldConfig, $table);
                return;
            }

            // Regular foreign table relation without MM
            $this->includeRegularRelations($records, $fieldName);
            return;
        }

        // Handle static items (options from TCA, not from a foreign table)
        if (!empty($fieldConfig['config']['items'])) {
            $this->includeStaticItems($records, $fieldName, $fieldConfig);
        }
    }

    /**
     * Include MM relations for a field
     */
    protected function includeMmRelations(array &$records, string $fieldName, array $fieldConfig, string $table): void
    {
        $mmTable = $fieldConfig['config']['MM'];
        
        // Get MM values for all records
        foreach ($records as &$record) {
            if (isset($record['uid'])) {
                $mmValues = $this->getMmRelationValues(
                    $mmTable,
                    $table,
                    $record['uid'],
                    $fieldName,
                    $fieldConfig['config']
                );
                $record[$fieldName] = $mmValues;
            }
        }
    }

    /**
     * Include regular (non-MM) relations for a field
     */
    protected function includeRegularRelations(array &$records, string $fieldName): void
    {
        // Convert comma-separated values to array for each record
        foreach ($records as &$record) {
            if (isset($record[$fieldName])) {
                // Always convert to array for consistency
                if (empty($record[$fieldName]) || $record[$fieldName] === 0 || $record[$fieldName] === '0') {
                    $record[$fieldName] = [];
                } elseif (is_int($record[$fieldName])) {
                    $record[$fieldName] = [$record[$fieldName]];
                } else {
                    $values = GeneralUtility::intExplode(',', (string)$record[$fieldName], true);
                    $record[$fieldName] = $values;
                }
            }
        }
    }

    /**
     * Include static items for a field
     */
    protected function includeStaticItems(array &$records, string $fieldName, array $fieldConfig): void
    {
        // Convert comma-separated values to array for each record
        foreach ($records as &$record) {
            if (isset($record[$fieldName]) && $record[$fieldName] !== '' && $record[$fieldName] !== null) {
                // Convert to array if it's a multi-select field
                if (!empty($fieldConfig['config']['multiple'])) {
                    $values = GeneralUtility::trimExplode(',', (string)$record[$fieldName], true);
                    $record[$fieldName] = $values;
                }
                // Single select fields remain as single values
            }
        }
    }

    /**
     * Include inline field relations
     */
    protected function includeInlineRelations(array &$records, string $fieldName, array $fieldConfig, array $recordUids): void
    {
        if (empty($fieldConfig['config']['foreign_table'])) {
            return;
        }

        $foreignTable = $fieldConfig['config']['foreign_table'];
        $foreignField = $fieldConfig['config']['foreign_field'] ?? '';

        // Skip if the foreign table isn't accessible or no foreign field
        if (!$this->tableAccessService->canAccessTable($foreignTable) || empty($foreignField)) {
            return;
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
        foreach ($records as &$record) {
            $uid = $record['uid'] ?? null;
            if ($uid !== null && isset($groupedRecords[$uid])) {
                $record[$fieldName] = $groupedRecords[$uid];
            }
        }
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
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));

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
     * Get MM relation values for a field
     * 
     * NOTE: This method provides basic MM relation support. It does NOT:
     * - Apply foreign_table_where conditions
     * - Resolve placeholders in foreign_table_where
     * - Handle complex TYPO3 relation scenarios
     * 
     * For complex scenarios, use TYPO3 Backend or DataHandler which handle 
     * these complexities properly.
     * 
     * @param string $mmTable The MM table name
     * @param string $localTable The local table name
     * @param int $localUid The local record UID
     * @param string $fieldName The field name (for documentation)
     * @param array $fieldConfig The full field configuration from TCA
     * @return array Array of related UIDs (not full records)
     */
    protected function getMmRelationValues(string $mmTable, string $localTable, int $localUid, string $fieldName, array $fieldConfig): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($mmTable);
        
        // Determine if this is an opposite/reverse relation
        $isOppositeRelation = !empty($fieldConfig['MM_opposite_field']);
        
        // Set column names based on relation direction
        if ($isOppositeRelation) {
            // For opposite relations (like categories), local record is in uid_foreign
            $localColumn = 'uid_foreign';
            $foreignColumn = 'uid_local';
            $sortingColumn = 'sorting_foreign';
        } else {
            // For standard relations (like tags), local record is in uid_local
            $localColumn = 'uid_local';
            $foreignColumn = 'uid_foreign';
            $sortingColumn = 'sorting';
        }
        
        // Basic constraints
        $constraints = [
            $queryBuilder->expr()->eq($localColumn, $queryBuilder->createNamedParameter($localUid, ParameterType::INTEGER))
        ];
        
        // Add match fields if specified (e.g., for shared MM tables like sys_category_record_mm)
        $matchFields = $fieldConfig['MM_match_fields'] ?? [];
        foreach ($matchFields as $field => $value) {
            $constraints[] = $queryBuilder->expr()->eq(
                $field,
                $queryBuilder->createNamedParameter($value)
            );
        }
        
        // Execute query
        $result = $queryBuilder
            ->select($foreignColumn)
            ->from($mmTable)
            ->where(...$constraints)
            ->orderBy($sortingColumn, 'ASC')
            ->executeQuery();
        
        $values = [];
        while ($row = $result->fetchAssociative()) {
            $values[] = (int)$row[$foreignColumn];
        }
        
        return $values;
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
