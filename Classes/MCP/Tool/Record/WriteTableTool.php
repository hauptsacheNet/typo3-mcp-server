<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Doctrine\DBAL\ParameterType;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

/**
 * Tool for writing records to TYPO3 tables
 */
class WriteTableTool extends AbstractRecordTool
{
    /**
     * Get the tool type
     */
    public function getToolType(): string
    {
        return 'write';
    }

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Create, update, or delete records in workspace-capable TYPO3 tables. All changes are made in workspace context and require publishing to become live. ' .
                'Before creating or updating content, always use GetPage to understand the page structure, existing content, and writing style. ' .
                'Check existing content elements with ReadTable to ensure new content fits the page\'s tone and doesn\'t duplicate existing elements. ' .
                'For content creation, verify the appropriate colPos by examining existing content layout.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform: "create", "update", or "delete"',
                        'enum' => ['create', 'update', 'delete'],
                    ],
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table name to write records to',
                    ],
                    'pid' => [
                        'type' => 'integer',
                        'description' => 'Page ID for new records (required for "create" action)',
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'Record UID (required for "update" and "delete" actions)',
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Record data (required for "create" and "update" actions). Inline relations can be specified as arrays - UIDs for independent tables, record data for embedded tables.',
                    ],
                    'position' => [
                        'type' => 'string',
                        'description' => 'Position for new records: "top", "bottom", "after:UID", or "before:UID"',
                        'default' => 'bottom',
                    ],
                ],
                'required' => ['action', 'table'],
            ],
            'examples' => [
                [
                    'description' => 'Create a new content element at the bottom of the page',
                    'parameters' => [
                        'action' => 'create',
                        'table' => 'tt_content',
                        'pid' => 123,
                        'position' => 'bottom',
                        'data' => [
                            'CType' => 'textmedia',
                            'header' => 'New Content Element',
                            'bodytext' => 'This is a new content element'
                        ]
                    ]
                ],
                [
                    'description' => 'Create a new content element after a specific element',
                    'parameters' => [
                        'action' => 'create',
                        'table' => 'tt_content',
                        'pid' => 123,
                        'position' => 'after:456',
                        'data' => [
                            'CType' => 'textmedia',
                            'header' => 'New Content Element',
                            'bodytext' => 'This is a new content element'
                        ]
                    ]
                ],
                [
                    'description' => 'Update an existing content element',
                    'parameters' => [
                        'action' => 'update',
                        'table' => 'tt_content',
                        'uid' => 456,
                        'data' => [
                            'header' => 'Updated Header',
                            'bodytext' => 'This content has been updated'
                        ]
                    ]
                ],
                [
                    'description' => 'Delete a content element',
                    'parameters' => [
                        'action' => 'delete',
                        'table' => 'tt_content',
                        'uid' => 456
                    ]
                ],
                [
                    'description' => 'Create content element with inline relation to news',
                    'parameters' => [
                        'action' => 'create',
                        'table' => 'tt_content',
                        'pid' => 123,
                        'data' => [
                            'header' => 'Content for news',
                            'CType' => 'text',
                            'tx_news_related_news' => 789  // Foreign field sets the relation
                        ]
                    ]
                ],
                [
                    'description' => 'Remove inline relation by updating foreign field',
                    'parameters' => [
                        'action' => 'update',
                        'table' => 'tt_content',
                        'uid' => 456,
                        'data' => [
                            'tx_news_related_news' => 0  // Set to 0 to remove relation
                        ]
                    ]
                ],
                [
                    'description' => 'Update news with content elements using UIDs (independent inline)',
                    'parameters' => [
                        'action' => 'update',
                        'table' => 'tx_news_domain_model_news',
                        'uid' => 123,
                        'data' => [
                            'content_elements' => [456, 789, 1011]  // Array of content element UIDs
                        ]
                    ]
                ],
                [
                    'description' => 'Create news with embedded links (dependent inline)',
                    'parameters' => [
                        'action' => 'create',
                        'table' => 'tx_news_domain_model_news',
                        'pid' => 123,
                        'data' => [
                            'title' => 'News with links',
                            'related_links' => [
                                ['title' => 'External link', 'uri' => 'https://example.com', 'description' => 'Company website'],
                                ['title' => 'Internal page', 'uri' => 't3://page?uid=42']
                            ]
                        ]
                    ]
                ]
            ],
        ];
    }

    /**
     * Execute the tool
     */
    public function execute(array $params): CallToolResult
    {
        // Initialize workspace context
        $this->initializeWorkspaceContext();
        
        // Get parameters
        $action = $params['action'] ?? '';
        $table = $params['table'] ?? '';
        $pid = isset($params['pid']) ? (int)$params['pid'] : null;
        $uid = isset($params['uid']) ? (int)$params['uid'] : null;
        $data = $params['data'] ?? [];
        $position = $params['position'] ?? 'bottom';
        
        // Validate parameters
        if (empty($action)) {
            return $this->createErrorResult('Action is required (create, update, or delete)');
        }
        
        if (empty($table)) {
            return $this->createErrorResult('Table name is required');
        }
        
        // Validate table access using TableAccessService
        try {
            $this->ensureTableAccess($table, $action === 'delete' ? 'delete' : 'write');
        } catch (\InvalidArgumentException $e) {
            return $this->createErrorResult($e->getMessage());
        }
        
        // Validate action-specific parameters
        switch ($action) {
            case 'create':
                if ($pid === null) {
                    return $this->createErrorResult('Page ID (pid) is required for create action');
                }
                
                if (empty($data)) {
                    return $this->createErrorResult('Data is required for create action');
                }
                break;
                
            case 'update':
                if ($uid === null) {
                    return $this->createErrorResult('Record UID is required for update action');
                }
                
                if (empty($data)) {
                    return $this->createErrorResult('Data is required for update action');
                }
                break;
                
            case 'delete':
                if ($uid === null) {
                    return $this->createErrorResult('Record UID is required for delete action');
                }
                break;
                
            default:
                return $this->createErrorResult('Invalid action: ' . $action);
        }
        
        // Execute the action
        try {
            switch ($action) {
                case 'create':
                    return $this->createRecord($table, $pid, $data, $position);
                    
                case 'update':
                    return $this->updateRecord($table, $uid, $data);
                    
                case 'delete':
                    return $this->deleteRecord($table, $uid);
            }
        } catch (\Throwable $e) {
            return $this->createErrorResult('Error executing action: ' . $e->getMessage());
        }
        
        // This should never happen
        return $this->createErrorResult('Unknown error');
    }
    
    /**
     * Create a new record
     */
    protected function createRecord(string $table, int $pid, array $data, string $position): CallToolResult
    {
        // Validate the data
        $validationResult = $this->validateRecordData($table, $data, 'create');
        if ($validationResult !== true) {
            return $this->createErrorResult('Validation error: ' . $validationResult);
        }
        
        // Extract inline relations before converting data
        $inlineRelations = $this->extractInlineRelations($table, $data);
        
        // Convert data for storage
        $data = $this->convertDataForStorage($table, $data);
        
        // Prepare the data array
        $newRecordData = $data;
        $newRecordData['pid'] = $pid;
        
        // Handle sorting for bottom position
        // Only set sorting if not explicitly provided in the data
        if ($position === 'bottom' && !isset($data['sorting'])) {
            // Get the maximum sorting value and add some space
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);
            
            $maxSorting = $queryBuilder
                ->select('sorting')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER))
                )
                ->orderBy('sorting', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
            
            if ($maxSorting !== false) {
                $newRecordData['sorting'] = (int)$maxSorting + 128; // Add some space for future insertions
            }
        }
        
        // Create a unique ID for this new record
        $newId = 'NEW' . uniqid();
        
        // Initialize DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        
        // First, create the parent record without inline relations
        $dataMap = [];
        $dataMap[$table][$newId] = $newRecordData;
        
        // Process the parent record first
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();
        
        // Check for errors in parent creation
        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error creating record: ' . implode(', ', $dataHandler->errorLog));
        }
        
        // Get the UID of the newly created parent record
        $parentUid = $dataHandler->substNEWwithIDs[$newId] ?? null;
        
        if (!$parentUid) {
            return $this->createErrorResult('Error creating record: No UID returned');
        }
        
        // Get the live UID for inline relations if we're in a workspace
        $liveParentUid = $this->getLiveUid($table, $parentUid);
        
        // Now process inline relations with the resolved parent UID
        if (!empty($inlineRelations)) {
            $childDataMap = [];
            $this->processInlineRelations($childDataMap, $table, $parentUid, $pid, $inlineRelations);
            
            
            if (!empty($childDataMap)) {
                // Create a new DataHandler instance for child records
                $childDataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $childDataHandler->BE_USER = $GLOBALS['BE_USER'];
                $childDataHandler->start($childDataMap, []);
                $childDataHandler->process_datamap();
                
                
                // Check for errors in child creation
                if (!empty($childDataHandler->errorLog)) {
                    // Parent was created but children failed
                    return $this->createErrorResult(
                        'Parent record created but error creating child records: ' . 
                        implode(', ', $childDataHandler->errorLog)
                    );
                }
                
                // Update foreign fields for embedded relations
                foreach ($inlineRelations as $fieldName => $relationData) {
                    $config = $relationData['config'];
                    $foreignTable = $config['foreign_table'] ?? '';
                    $foreignField = $config['foreign_field'] ?? '';
                    
                    if (empty($foreignTable) || empty($foreignField)) {
                        continue;
                    }
                    
                    // Check if this is an embedded table
                    $foreignTableTCA = $GLOBALS['TCA'][$foreignTable] ?? [];
                    $isHiddenTable = ($foreignTableTCA['ctrl']['hideTable'] ?? false) === true;
                    
                    if ($isHiddenTable) {
                        // Collect the UIDs of created child records
                        $childUids = [];
                        foreach ($childDataHandler->substNEWwithIDs as $newId => $realId) {
                            if (strpos($newId, 'NEW') === 0 && isset($childDataMap[$foreignTable][$newId])) {
                                $childUids[] = $realId;
                            }
                        }
                        
                        if (!empty($childUids)) {
                            // Update foreign field directly in database
                            // RelationHandler's writeForeignField is for MM relations, not direct foreign fields
                            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                                ->getConnectionForTable($foreignTable);
                            
                            foreach ($childUids as $childUid) {
                                $connection->update(
                                    $foreignTable,
                                    [$foreignField => $liveParentUid],
                                    ['uid' => $childUid]
                                );
                            }
                        }
                    }
                }
            }
        }
        
        
        // Handle after/before positioning if needed
        if (strpos($position, 'after:') === 0 || strpos($position, 'before:') === 0) {
            $positionType = substr($position, 0, strpos($position, ':'));
            $referenceUid = (int)substr($position, strpos($position, ':') + 1);
            
            // Set up the command map for moving the record
            $cmdMap = [];
            $cmdMap[$table][$parentUid]['move'] = [
                'action' => $positionType,
                'target' => $referenceUid,
            ];
            
            // Initialize a new DataHandler for the move operation
            $moveDataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $moveDataHandler->BE_USER = $GLOBALS['BE_USER'];
            $moveDataHandler->start([], $cmdMap);
            $moveDataHandler->process_cmdmap();
            
            // Check for errors in the move operation
            if (!empty($moveDataHandler->errorLog)) {
                // The record was created but positioning failed
                $liveUid = $this->getLiveUid($table, $parentUid);
                return $this->createJsonResult([
                    'action' => 'create',
                    'table' => $table,
                    'uid' => $liveUid,
                    'data' => $data,
                    'warning' => 'Record created but positioning failed: ' . implode(', ', $moveDataHandler->errorLog)
                ]);
            }
        }
        
        // Get the live UID for workspace transparency
        $liveUid = $this->getLiveUid($table, $parentUid);
        
        // Return the result with live UID
        return $this->createJsonResult([
            'action' => 'create',
            'table' => $table,
            'uid' => $liveUid,
            'data' => $data,
        ]);
    }
    
    /**
     * Update an existing record
     */
    protected function updateRecord(string $table, int $uid, array $data): CallToolResult
    {
        // Validate the data
        $validationResult = $this->validateRecordData($table, $data, 'update', $uid);
        if ($validationResult !== true) {
            return $this->createErrorResult('Validation error: ' . $validationResult);
        }
        
        // Extract inline relations before converting data
        $inlineRelations = $this->extractInlineRelations($table, $data);
        
        // Convert data for storage
        $data = $this->convertDataForStorage($table, $data);
        
        // Resolve the live UID to workspace UID
        $workspaceUid = $this->resolveToWorkspaceUid($table, $uid);
        
        // First, update the parent record without inline relations
        $dataMap = [$table => [$workspaceUid => $data]];
        
        // Update the record using DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();
        
        // Check for errors in parent update
        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error updating record: ' . implode(', ', $dataHandler->errorLog));
        }
        
        // Now process inline relations with the resolved parent UID
        if (!empty($inlineRelations)) {
            // Get record's pid for creating new inline records
            $record = BackendUtility::getRecord($table, $workspaceUid, 'pid');
            $pid = $record['pid'] ?? 0;
            
            $childDataMap = [];
            $this->processInlineRelations($childDataMap, $table, $workspaceUid, $pid, $inlineRelations, $uid);
            
            if (!empty($childDataMap)) {
                // Create a new DataHandler instance for child records
                $childDataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $childDataHandler->BE_USER = $GLOBALS['BE_USER'];
                $childDataHandler->start($childDataMap, []);
                $childDataHandler->process_datamap();
                
                // Check for errors in child processing
                if (!empty($childDataHandler->errorLog)) {
                    return $this->createErrorResult('Error processing inline relations: ' . implode(', ', $childDataHandler->errorLog));
                }
                
                // Update foreign fields for embedded relations
                foreach ($inlineRelations as $fieldName => $relationData) {
                    $config = $relationData['config'];
                    $foreignTable = $config['foreign_table'] ?? '';
                    $foreignField = $config['foreign_field'] ?? '';
                    
                    if (empty($foreignTable) || empty($foreignField)) {
                        continue;
                    }
                    
                    // Check if this is an embedded table
                    $foreignTableTCA = $GLOBALS['TCA'][$foreignTable] ?? [];
                    $isHiddenTable = ($foreignTableTCA['ctrl']['hideTable'] ?? false) === true;
                    
                    if ($isHiddenTable) {
                        // Collect the UIDs of created child records
                        $childUids = [];
                        foreach ($childDataHandler->substNEWwithIDs as $newId => $realId) {
                            if (strpos($newId, 'NEW') === 0 && isset($childDataMap[$foreignTable][$newId])) {
                                $childUids[] = $realId;
                            }
                        }
                        
                        if (!empty($childUids)) {
                            // Update foreign field directly in database
                            // RelationHandler's writeForeignField is for MM relations, not direct foreign fields
                            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                                ->getConnectionForTable($foreignTable);
                            
                            // In update context, $uid is already the live UID
                            foreach ($childUids as $childUid) {
                                $connection->update(
                                    $foreignTable,
                                    [$foreignField => $uid],
                                    ['uid' => $childUid]
                                );
                            }
                        }
                    }
                }
            }
        }
        
        // Return the result with the original live UID
        return $this->createJsonResult([
            'action' => 'update',
            'table' => $table,
            'uid' => $uid, // Return the live UID that was passed in
            'data' => $data,
        ]);
    }
    
    /**
     * Delete a record
     */
    protected function deleteRecord(string $table, int $uid): CallToolResult
    {
        // Resolve the live UID to workspace UID
        $workspaceUid = $this->resolveToWorkspaceUid($table, $uid);
        
        // Delete the record using DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start([], [$table => [$workspaceUid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();
        
        // Check for errors
        if ($dataHandler->errorLog) {
            return $this->createErrorResult('Error deleting record: ' . implode(', ', $dataHandler->errorLog));
        }
        
        return $this->createJsonResult([
            'action' => 'delete',
            'table' => $table,
            'uid' => $uid, // Return the live UID that was passed in
        ]);
    }
    
    /**
     * Validate record data against TCA
     * 
     * @param int|null $uid Record UID (required for update actions)
     * @return true|string True if valid, error message if invalid
     */
    protected function validateRecordData(string $table, array &$data, string $action, ?int $uid = null)
    {
        // Table access has already been validated by ensureTableAccess() before this method is called
        // No need to re-check table existence here
        
        // Special handling for uid and pid
        if (isset($data['uid'])) {
            return "Field 'uid' cannot be modified directly";
        }
        if (isset($data['pid']) && $action !== 'create') {
            return "Field 'pid' can only be set during record creation";
        }
        
        // Validate and convert field values
        foreach ($data as $fieldName => $value) {
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            if (!$fieldConfig) {
                continue;
            }
            
            // Validate field value
            $validationError = $this->tableAccessService->validateFieldValue($table, $fieldName, $value);
            if ($validationError !== null) {
                return $validationError;
            }
            
            // Handle date/time fields - convert ISO 8601 to timestamp for TYPO3
            if (!empty($fieldConfig['config']['eval'])) {
                $evalRules = GeneralUtility::trimExplode(',', $fieldConfig['config']['eval'], true);
                if (array_intersect(['date', 'datetime', 'time'], $evalRules)) {
                    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
                        try {
                            $dateTime = new \DateTime($value);
                            $data[$fieldName] = $dateTime->getTimestamp();
                        } catch (\Exception $e) {
                            // Let DataHandler handle the invalid date
                        }
                    }
                }
            }
            
            // Validate inline field type
            if ($fieldConfig['config']['type'] === 'inline') {
                // Validate inline relation data
                $validationError = $this->validateInlineRelationData($fieldConfig, $value);
                if ($validationError !== null) {
                    return "Field '{$fieldName}': " . $validationError;
                }
                continue;
            }
            // Convert arrays to comma-separated strings for multi-value fields
            elseif (is_array($value)) {
                $fieldType = $fieldConfig['config']['type'] ?? '';
                if (in_array($fieldType, ['select', 'category']) || 
                    ($fieldType === 'group' && !empty($fieldConfig['config']['multiple']))) {
                    $data[$fieldName] = implode(',', array_map('strval', $value));
                }
            }
        }
        
        // After validating all field values, check field availability based on record type
        // This ensures type field validation happens first
        $recordType = '';
        $typeField = $this->tableAccessService->getTypeFieldName($table);
        if ($typeField) {
            if ($action === 'update' && $uid !== null) {
                // For updates, fetch the current record type
                $currentRecord = BackendUtility::getRecord($table, $uid, $typeField);
                if ($currentRecord && isset($currentRecord[$typeField])) {
                    $recordType = (string)$currentRecord[$typeField];
                }
                // If type is being changed in the update, use the new type
                if (isset($data[$typeField])) {
                    $recordType = (string)$data[$typeField];
                }
            } else {
                // For creates, get type from data
                $recordType = isset($data[$typeField]) ? (string)$data[$typeField] : '';
            }
        }
        
        // Get available fields for this record type
        $availableFields = $this->tableAccessService->getAvailableFields($table, $recordType);
        
        // The type field itself should always be available if it exists
        if ($typeField) {
            $typeFieldConfig = $this->tableAccessService->getFieldConfig($table, $typeField);
            if ($typeFieldConfig) {
                $availableFields[$typeField] = $typeFieldConfig;
            }
        }
        
        // If we have type-specific configuration, validate field availability
        if (!empty($availableFields) || !empty($typeField)) {
            // Check each field in data is available
            foreach ($data as $fieldName => $value) {
                // Skip fields that don't exist in TCA (already validated above)
                if (!$this->tableAccessService->getFieldConfig($table, $fieldName)) {
                    continue;
                }
                
                // Special handling for FlexForm fields which are dynamically added
                if ($this->isFlexFormField($table, $fieldName)) {
                    // FlexForm fields are valid if they exist in TCA, even if not in showitem
                    continue;
                }
                
                // Special handling for passthrough fields (often used for inline relations)
                $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
                if ($fieldConfig && isset($fieldConfig['config']['type']) && $fieldConfig['config']['type'] === 'passthrough') {
                    // Passthrough fields are valid if they exist in TCA, even if not in showitem
                    // Example: tx_news_related_news stores the foreign key for inline relations
                    continue;
                }
                
                
                // If we have available fields configured and this field is not in the list
                if (!empty($availableFields) && !isset($availableFields[$fieldName])) {
                    return "Field '{$fieldName}' is not available for this record type";
                }
            }
        }
        
        return true;
    }
    
    
    /**
     * Extract inline relations from data array
     */
    protected function extractInlineRelations(string $table, array &$data): array
    {
        $inlineRelations = [];
        
        if (!isset($GLOBALS['TCA'][$table]['columns'])) {
            return $inlineRelations;
        }
        
        foreach ($data as $fieldName => $value) {
            $fieldConfig = $this->tableAccessService->getFieldConfig($table, $fieldName);
            if ($fieldConfig && ($fieldConfig['config']['type'] ?? '') === 'inline') {
                $inlineRelations[$fieldName] = [
                    'config' => $fieldConfig['config'],
                    'value' => $value
                ];
                // Remove from data array as we'll process it separately
                unset($data[$fieldName]);
            }
        }
        
        return $inlineRelations;
    }
    
    /**
     * Process inline relations for DataHandler
     */
    protected function processInlineRelations(
        array &$dataMap,
        string $parentTable,
        $parentUid,
        int $pid,
        array $inlineRelations,
        ?int $liveUid = null
    ): void {
        foreach ($inlineRelations as $fieldName => $relationData) {
            $config = $relationData['config'];
            $value = $relationData['value'];
            $foreignTable = $config['foreign_table'] ?? '';
            $foreignField = $config['foreign_field'] ?? '';
            
            if (empty($foreignTable) || empty($foreignField)) {
                continue;
            }
            
            // Check if foreign table is hidden (embedded records)
            $foreignTableTCA = $GLOBALS['TCA'][$foreignTable] ?? [];
            $isHiddenTable = ($foreignTableTCA['ctrl']['hideTable'] ?? false) === true;
            
            if ($isHiddenTable) {
                // Process embedded inline relations (e.g., tx_news_domain_model_link)
                $this->processEmbeddedInlineRelations($dataMap, $foreignTable, $foreignField, $parentUid, $pid, $value, $config, $liveUid);
            } else {
                // Process independent inline relations (e.g., tt_content)
                $this->processIndependentInlineRelations($foreignTable, $foreignField, $parentUid, $value, $liveUid);
            }
        }
    }
    
    /**
     * Process embedded inline relations (hideTable=true)
     */
    protected function processEmbeddedInlineRelations(
        array &$dataMap,
        string $foreignTable,
        string $foreignField,
        $parentUid,
        int $pid,
        array $records,
        array $config,
        ?int $liveUid = null
    ): void {
        // If we're updating, handle existing relations
        if ($liveUid !== null) {
            $this->handleExistingEmbeddedRelations($foreignTable, $foreignField, $liveUid, $records);
        }
        
        foreach ($records as $index => $recordData) {
            if (!is_array($recordData)) {
                continue;
            }
            
            // Create new ID for the inline record
            $newId = 'NEW' . uniqid() . '_' . $index;
            
            // Don't set the foreign field here - it will be handled by RelationHandler
            // Remove it if it was accidentally included
            unset($recordData[$foreignField]);
            $recordData['pid'] = $pid;
            
            // If we have a sorting field, set it
            if (isset($config['foreign_sortby'])) {
                $recordData[$config['foreign_sortby']] = ($index + 1) * 256;
            }
            
            // Add to data map
            if (!isset($dataMap[$foreignTable])) {
                $dataMap[$foreignTable] = [];
            }
            $dataMap[$foreignTable][$newId] = $recordData;
        }
    }
    
    /**
     * Process independent inline relations (UIDs only)
     */
    protected function processIndependentInlineRelations(
        string $foreignTable,
        string $foreignField,
        $parentUid,
        array $uids,
        ?int $liveUid = null
    ): void {
        // For updates, we need to handle existing relations
        if ($liveUid !== null) {
            // First, clear existing relations
            $this->clearExistingInlineRelations($foreignTable, $foreignField, $liveUid);
        }
        
        // Update foreign field on specified records
        if (!empty($uids)) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->BE_USER = $GLOBALS['BE_USER'];
            
            $updateMap = [];
            foreach ($uids as $uid) {
                if (is_numeric($uid) && $uid > 0) {
                    $updateMap[$foreignTable][$uid] = [
                        $foreignField => $liveUid ?? $parentUid
                    ];
                }
            }
            
            if (!empty($updateMap)) {
                $dataHandler->start($updateMap, []);
                $dataHandler->process_datamap();
            }
        }
    }
    
    /**
     * Clear existing inline relations
     */
    protected function clearExistingInlineRelations(string $foreignTable, string $foreignField, int $parentUid): void
    {
        // Get all records that currently have this parent
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($foreignTable);
        
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));
        
        $existingRecords = $queryBuilder
            ->select('uid')
            ->from($foreignTable)
            ->where(
                $queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($parentUid, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();
        
        if (!empty($existingRecords)) {
            // Use DataHandler to clear relations to respect workspaces
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->BE_USER = $GLOBALS['BE_USER'];
            
            $updateMap = [];
            foreach ($existingRecords as $record) {
                $updateMap[$foreignTable][$record['uid']] = [
                    $foreignField => 0
                ];
            }
            
            if (!empty($updateMap)) {
                $dataHandler->start($updateMap, []);
                $dataHandler->process_datamap();
            }
        }
    }
    
    /**
     * Handle existing embedded relations during updates
     */
    protected function handleExistingEmbeddedRelations(
        string $foreignTable,
        string $foreignField,
        int $parentUid,
        array $newRecords
    ): void {
        // Get all existing child records for this parent
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($foreignTable);
        
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));
        
        $existingRecords = $queryBuilder
            ->select('uid')
            ->from($foreignTable)
            ->where(
                $queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($parentUid, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();
        
        if (!empty($existingRecords)) {
            // Extract UIDs of new records that have UIDs (updates)
            $keepUids = [];
            foreach ($newRecords as $record) {
                if (is_array($record) && isset($record['uid']) && is_numeric($record['uid'])) {
                    $keepUids[] = (int)$record['uid'];
                }
            }
            
            // Delete records that are not in the new set
            $deleteUids = [];
            foreach ($existingRecords as $existingRecord) {
                if (!in_array((int)$existingRecord['uid'], $keepUids, true)) {
                    $deleteUids[] = (int)$existingRecord['uid'];
                }
            }
            
            if (!empty($deleteUids)) {
                // Use DataHandler to delete records (respects workspaces)
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $dataHandler->BE_USER = $GLOBALS['BE_USER'];
                
                $cmdMap = [];
                foreach ($deleteUids as $deleteUid) {
                    $cmdMap[$foreignTable][$deleteUid]['delete'] = 1;
                }
                
                $dataHandler->start([], $cmdMap);
                $dataHandler->process_cmdmap();
            }
        }
    }
    
    /**
     * Validate inline relation data
     */
    protected function validateInlineRelationData(array $fieldConfig, $value): ?string
    {
        // Check if value is an array
        if (!is_array($value)) {
            return 'Inline relation field must be an array of UIDs or record data';
        }
        
        // Get foreign table
        $foreignTable = $fieldConfig['config']['foreign_table'] ?? '';
        if (empty($foreignTable)) {
            return 'Invalid inline relation configuration: missing foreign_table';
        }
        
        // Check if foreign table is hidden (embedded records)
        $foreignTableTCA = $GLOBALS['TCA'][$foreignTable] ?? [];
        $isHiddenTable = ($foreignTableTCA['ctrl']['hideTable'] ?? false) === true;
        
        // Validate each item
        foreach ($value as $index => $item) {
            if ($isHiddenTable) {
                // For hidden tables, expect record data arrays
                if (!is_array($item)) {
                    return 'Embedded inline relations must contain record data arrays';
                }
                // Basic validation - must have at least one field
                if (empty($item)) {
                    return 'Embedded inline relation record at index ' . $index . ' is empty';
                }
            } else {
                // For independent tables, expect UIDs
                if (!is_numeric($item) || $item <= 0) {
                    return 'Independent inline relations must contain only positive integer UIDs';
                }
            }
        }
        
        return null;
    }
    
    /**
     * Check if a field is a FlexForm field
     */
    protected function isFlexFormField(string $table, string $fieldName): bool
    {
        return $this->tableAccessService->isFlexFormField($table, $fieldName);
    }
    
    /**
     * Convert data for storage
     */
    protected function convertDataForStorage(string $table, array $data): array
    {
        // Process each field
        foreach ($data as $fieldName => $value) {
            // Skip null values
            if ($value === null) {
                continue;
            }
            
            // Handle FlexForm fields
            if ($this->isFlexFormField($table, $fieldName)) {
                // If the value is already a string (XML), keep it as is
                if (is_string($value) && strpos($value, '<?xml') === 0) {
                    continue;
                }
                
                // If the value is an array or JSON string, convert it to XML
                $flexFormArray = is_array($value) ? $value : (is_string($value) && strpos($value, '{') === 0 ? json_decode($value, true) : null);
                
                if (is_array($flexFormArray)) {
                    // Prepare the data structure for TYPO3's XML conversion
                    $flexFormData = [
                        'data' => [
                            'sDEF' => [
                                'lDEF' => []
                            ]
                        ]
                    ];
                    
                    // Process settings fields
                    if (isset($flexFormArray['settings']) && is_array($flexFormArray['settings'])) {
                        foreach ($flexFormArray['settings'] as $settingKey => $settingValue) {
                            $flexFormData['data']['sDEF']['lDEF']['settings.' . $settingKey]['vDEF'] = $settingValue;
                        }
                    }
                    
                    // Process other fields
                    foreach ($flexFormArray as $key => $val) {
                        if ($key !== 'settings' && !is_array($val)) {
                            $flexFormData['data']['sDEF']['lDEF'][$key]['vDEF'] = $val;
                        }
                    }
                    
                    // Use TYPO3's GeneralUtility::array2xml to convert the array to XML
                    $xml = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>' . "\n";
                    $xml .= GeneralUtility::array2xml($flexFormData, '', 0, 'T3FlexForms');
                    
                    $data[$fieldName] = $xml;
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Get the live UID for a workspace record
     * For workspace records, this returns the t3ver_oid (original/live UID)
     * For new records (placeholders), this returns the placeholder UID
     */
    protected function getLiveUid(string $table, int $workspaceUid): int
    {
        // If we're in live workspace, the UID is already the live UID
        $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
        if ($currentWorkspace === 0) {
            return $workspaceUid;
        }
        
        // Look up the record to get its t3ver_oid
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        
        $queryBuilder->getRestrictions()->removeAll();
        
        $record = $queryBuilder
            ->select('t3ver_oid', 't3ver_state', 't3ver_wsid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($workspaceUid, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        if (!$record) {
            // Record not found, return the original UID
            return $workspaceUid;
        }
        
        // If this is a workspace record with an original, return the original UID
        if ($record['t3ver_oid'] > 0) {
            return (int)$record['t3ver_oid'];
        }
        
        // For new records (t3ver_state = 1), the workspace UID IS the UID we should use
        // New records don't have a live counterpart until published
        if ($record['t3ver_state'] == 1) {
            return $workspaceUid;
        }
        
        // Default: return the workspace UID
        return $workspaceUid;
    }
    
    /**
     * Resolve a live UID to its workspace version
     * Used for update/delete operations where we receive a live UID but need the workspace version
     */
    protected function resolveToWorkspaceUid(string $table, int $liveUid): int
    {
        $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
        
        // If we're in live workspace, no resolution needed
        if ($currentWorkspace === 0) {
            return $liveUid;
        }
        
        // Use BackendUtility to get the workspace version
        $record = BackendUtility::getRecord($table, $liveUid);
        if (!$record) {
            return $liveUid;
        }
        
        // Let BackendUtility handle the workspace overlay
        BackendUtility::workspaceOL($table, $record);
        
        // If we got a different UID, that's the workspace version
        if (isset($record['_ORIG_uid']) && $record['_ORIG_uid'] != $liveUid) {
            return (int)$record['uid'];
        }
        
        return $liveUid;
    }
}
