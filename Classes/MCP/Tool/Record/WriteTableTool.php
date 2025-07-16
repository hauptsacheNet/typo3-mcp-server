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
            'description' => 'Create, update, or delete records in workspace-capable TYPO3 tables. All changes are made in workspace context and require publishing to become live.',
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
                        'description' => 'Record data (required for "create" and "update" actions).',
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
                ]
            ],
        ];
    }

    /**
     * Execute the tool
     */
    public function execute(array $params): CallToolResult
    {
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
        
        // Convert data for storage
        $data = $this->convertDataForStorage($table, $data);
        
        // Prepare the data array
        $newRecordData = $data;
        $newRecordData['pid'] = $pid;
        
        // Handle sorting for bottom position
        if ($position === 'bottom') {
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
        
        // Set up the data map
        $dataMap = [];
        $dataMap[$table][$newId] = $newRecordData;
        
        // Process the data map
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();
        
        // Check for errors
        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error creating record: ' . implode(', ', $dataHandler->errorLog));
        }
        
        // Get the UID of the newly created record
        $newUid = $dataHandler->substNEWwithIDs[$newId] ?? null;
        
        if (!$newUid) {
            return $this->createErrorResult('Error creating record: No UID returned');
        }
        
        // Handle after/before positioning if needed
        if (strpos($position, 'after:') === 0 || strpos($position, 'before:') === 0) {
            $positionType = substr($position, 0, strpos($position, ':'));
            $referenceUid = (int)substr($position, strpos($position, ':') + 1);
            
            // Set up the command map for moving the record
            $cmdMap = [];
            $cmdMap[$table][$newUid]['move'] = [
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
                $liveUid = $this->getLiveUid($table, $newUid);
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
        $liveUid = $this->getLiveUid($table, $newUid);
        
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
        
        // Convert data for storage
        $data = $this->convertDataForStorage($table, $data);
        
        // Resolve the live UID to workspace UID
        $workspaceUid = $this->resolveToWorkspaceUid($table, $uid);
        
        // Update the record using DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start([$table => [$workspaceUid => $data]], []);
        $dataHandler->process_datamap();
        
        // Check for errors
        if ($dataHandler->errorLog) {
            return $this->createErrorResult('Error updating record: ' . implode(', ', $dataHandler->errorLog));
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
    protected function validateRecordData(string $table, array $data, string $action, ?int $uid = null)
    {
        // Table access has already been validated by ensureTableAccess() before this method is called
        // No need to re-check table existence here
        
        $tca = $GLOBALS['TCA'][$table];
        
        // Special handling for uid and pid
        if (isset($data['uid'])) {
            return "Field 'uid' cannot be modified directly";
        }
        if (isset($data['pid']) && $action !== 'create') {
            return "Field 'pid' can only be set during record creation";
        }
        
        
        // Check required fields and validate field types
        foreach ($data as $fieldName => $value) {
            // Skip fields that don't exist in TCA
            if (!isset($tca['columns'][$fieldName])) {
                continue;
            }
            
            $fieldConfig = $tca['columns'][$fieldName];
            
            // Check field type
            switch ($fieldConfig['config']['type'] ?? '') {
                case 'input':
                    // Check max length
                    if (isset($fieldConfig['config']['max']) && is_string($value) && mb_strlen($value) > $fieldConfig['config']['max']) {
                        return 'Field "' . $fieldName . '" exceeds maximum length of ' . $fieldConfig['config']['max'];
                    }
                    
                    // Check eval rules
                    if (!empty($fieldConfig['config']['eval'])) {
                        $evalRules = GeneralUtility::trimExplode(',', $fieldConfig['config']['eval'], true);
                        
                        foreach ($evalRules as $rule) {
                            switch ($rule) {
                                case 'required':
                                    if (empty($value) && $value !== 0 && $value !== '0') {
                                        return 'Field "' . $fieldName . '" is required';
                                    }
                                    break;
                                    
                                case 'int':
                                    if (!is_int($value) && (!is_string($value) || !ctype_digit($value))) {
                                        return 'Field "' . $fieldName . '" must be an integer';
                                    }
                                    break;
                                    
                                case 'double2':
                                case 'float':
                                    if (!is_numeric($value)) {
                                        return 'Field "' . $fieldName . '" must be a number';
                                    }
                                    break;
                                    
                                case 'email':
                                    if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                                        return 'Field "' . $fieldName . '" must be a valid email address';
                                    }
                                    break;
                                    
                                case 'date':
                                case 'datetime':
                                case 'time':
                                    // Handle ISO 8601 date strings
                                    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
                                        try {
                                            $dateTime = new \DateTime($value);
                                            // Convert to timestamp for TYPO3
                                            $data[$fieldName] = $dateTime->getTimestamp();
                                        } catch (\Exception $e) {
                                            return 'Field "' . $fieldName . '" contains an invalid date format';
                                        }
                                    } elseif (!is_int($value) && (!is_string($value) || !ctype_digit($value))) {
                                        return 'Field "' . $fieldName . '" must be a valid date (timestamp or ISO 8601 format)';
                                    }
                                    break;
                            }
                        }
                    }
                    break;
                    
                case 'text':
                    // No specific validation for text fields
                    break;
                    
                case 'check':
                    // Check should be 0 or 1
                    if ($value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
                        return 'Field "' . $fieldName . '" must be 0 or 1';
                    }
                    break;
                    
                case 'radio':
                    // Radio should be one of the defined items
                    $validValues = [];
                    foreach (($fieldConfig['config']['items'] ?? []) as $item) {
                        // Handle both old and new TCA item formats
                        if (is_array($item) && isset($item['value'])) {
                            $validValues[] = $item['value'];
                        } elseif (is_array($item) && isset($item[1])) {
                            $validValues[] = $item[1];
                        }
                    }
                    
                    if (!empty($validValues) && !in_array($value, $validValues)) {
                        return 'Field "' . $fieldName . '" must be one of: ' . implode(', ', $validValues);
                    }
                    break;
                    
                case 'select':
                    // For single select, value should be one of the defined items or a relation
                    if (empty($fieldConfig['config']['foreign_table']) && empty($fieldConfig['config']['MM'])) {
                        // Not a relation, so check against items
                        if (empty($fieldConfig['config']['multiple'])) {
                            // Single select
                            $validValues = [];
                            foreach (($fieldConfig['config']['items'] ?? []) as $item) {
                                // Handle both old and new TCA item formats
                                if (is_array($item) && isset($item['value'])) {
                                    // Skip dividers
                                    if ($item['value'] === '--div--') {
                                        continue;
                                    }
                                    $validValues[] = $item['value'];
                                } elseif (is_array($item) && isset($item[1])) {
                                    // Skip dividers
                                    if ($item[1] === '--div--') {
                                        continue;
                                    }
                                    $validValues[] = $item[1];
                                }
                            }
                            
                            if (!empty($validValues) && !in_array($value, $validValues)) {
                                return 'Field "' . $fieldName . '" must be one of: ' . implode(', ', $validValues);
                            }
                        } else {
                            // Multiple select - value should be a comma-separated list
                            if (!is_array($value) && !is_string($value)) {
                                return 'Field "' . $fieldName . '" must be an array or a comma-separated string';
                            }
                            
                            $values = is_array($value) ? $value : GeneralUtility::trimExplode(',', $value, true);
                            $validValues = [];
                            
                            foreach (($fieldConfig['config']['items'] ?? []) as $item) {
                                // Handle both old and new TCA item formats
                                if (is_array($item) && isset($item['value'])) {
                                    // Skip dividers
                                    if ($item['value'] === '--div--') {
                                        continue;
                                    }
                                    $validValues[] = $item['value'];
                                } elseif (is_array($item) && isset($item[1])) {
                                    // Skip dividers
                                    if ($item[1] === '--div--') {
                                        continue;
                                    }
                                    $validValues[] = $item[1];
                                }
                            }
                            
                            foreach ($values as $singleValue) {
                                if (!empty($validValues) && !in_array($singleValue, $validValues)) {
                                    return 'Field "' . $fieldName . '" contains an invalid value: ' . $singleValue;
                                }
                            }
                            
                            // Convert array to comma-separated string for TYPO3
                            if (is_array($value)) {
                                $data[$fieldName] = implode(',', $value);
                            }
                        }
                    }
                    break;
            }
        }
        
        // Check for required fields that are missing
        foreach ($tca['columns'] as $fieldName => $fieldConfig) {
            // Skip if field is in the data
            if (isset($data[$fieldName])) {
                continue;
            }
            
            // For create actions, check if the field is required
            if ($action === 'create' && !empty($fieldConfig['config']['eval']) && strpos($fieldConfig['config']['eval'], 'required') !== false) {
                // Check if there's a default value
                if (!isset($fieldConfig['config']['default'])) {
                    return 'Required field "' . $fieldName . '" is missing';
                }
            }
        }
        
        // After validating all field values, check field availability based on record type
        // This ensures type field validation happens first
        $recordType = '';
        $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'] ?? null;
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
        if ($typeField && isset($GLOBALS['TCA'][$table]['columns'][$typeField])) {
            $availableFields[$typeField] = $GLOBALS['TCA'][$table]['columns'][$typeField];
        }
        
        // If we have type-specific configuration, validate field availability
        if (!empty($availableFields) || !empty($typeField)) {
            // Check each field in data is available
            foreach ($data as $fieldName => $value) {
                // Skip fields that don't exist in TCA (already validated above)
                if (!isset($GLOBALS['TCA'][$table]['columns'][$fieldName])) {
                    continue;
                }
                
                // Special handling for FlexForm fields which are dynamically added
                if ($this->isFlexFormField($table, $fieldName)) {
                    // FlexForm fields are valid if they exist in TCA, even if not in showitem
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
     * Check if a field is a FlexForm field
     */
    protected function isFlexFormField(string $table, string $fieldName): bool
    {
        return !empty($GLOBALS['TCA'][$table]['columns'][$fieldName]['config']['type']) && $GLOBALS['TCA'][$table]['columns'][$fieldName]['config']['type'] === 'flex';
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
