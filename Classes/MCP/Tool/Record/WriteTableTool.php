<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Doctrine\DBAL\ParameterType;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

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
        
        if (!$this->tableExists($table)) {
            return $this->createErrorResult('Table "' . $table . '" does not exist in TCA');
        }
        
        if ($this->isTableReadOnly($table)) {
            return $this->createErrorResult('Table "' . $table . '" is read-only');
        }
        
        // Check workspace capability
        $workspaceInfo = $this->getWorkspaceCapabilityInfo($table);
        if (!$workspaceInfo['workspace_capable']) {
            return $this->createErrorResult(
                'Table "' . $table . '" is not workspace-capable and cannot be modified via MCP. ' .
                'Reason: ' . $workspaceInfo['reason'] . '. ' .
                'This table requires direct database access or TYPO3 backend administration.'
            );
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
            $moveDataHandler->start([], $cmdMap);
            $moveDataHandler->process_cmdmap();
            
            // Check for errors in the move operation
            if (!empty($moveDataHandler->errorLog)) {
                // The record was created but positioning failed
                return $this->createJsonResult([
                    'action' => 'create',
                    'table' => $table,
                    'uid' => $newUid,
                    'data' => $data,
                    'warning' => 'Record created but positioning failed: ' . implode(', ', $moveDataHandler->errorLog)
                ]);
            }
        }
        
        // Return the result
        return $this->createJsonResult([
            'action' => 'create',
            'table' => $table,
            'uid' => $newUid,
            'data' => $data,
        ]);
    }
    
    /**
     * Update an existing record
     */
    protected function updateRecord(string $table, int $uid, array $data): CallToolResult
    {
        // Validate the data
        $validationResult = $this->validateRecordData($table, $data, 'update');
        if ($validationResult !== true) {
            return $this->createErrorResult('Validation error: ' . $validationResult);
        }
        
        // Convert data for storage
        $data = $this->convertDataForStorage($table, $data);
        
        // Update the record using DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $data]], []);
        $dataHandler->process_datamap();
        
        // Check for errors
        if ($dataHandler->errorLog) {
            return $this->createErrorResult('Error updating record: ' . implode(', ', $dataHandler->errorLog));
        }
        
        // Return the result
        return $this->createJsonResult([
            'action' => 'update',
            'table' => $table,
            'uid' => $uid,
            'data' => $data,
        ]);
    }
    
    /**
     * Delete a record
     */
    protected function deleteRecord(string $table, int $uid): CallToolResult
    {
        // Delete the record using DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();
        
        // Check for errors
        if ($dataHandler->errorLog) {
            return $this->createErrorResult('Error deleting record: ' . implode(', ', $dataHandler->errorLog));
        }
        
        return $this->createJsonResult([
            'action' => 'delete',
            'table' => $table,
            'uid' => $uid,
        ]);
    }
    
    /**
     * Validate record data against TCA
     * 
     * @return true|string True if valid, error message if invalid
     */
    protected function validateRecordData(string $table, array $data, string $action)
    {
        if (!isset($GLOBALS['TCA'][$table])) {
            return 'Table "' . $table . '" does not exist in TCA';
        }
        
        $tca = $GLOBALS['TCA'][$table];
        
        // Check required fields
        foreach ($tca['columns'] as $fieldName => $fieldConfig) {
            // Skip if field is not in the data
            if (!isset($data[$fieldName])) {
                // For create actions, check if the field is required
                if ($action === 'create' && !empty($fieldConfig['config']['eval']) && strpos($fieldConfig['config']['eval'], 'required') !== false) {
                    // Check if there's a default value
                    if (!isset($fieldConfig['config']['default'])) {
                        return 'Required field "' . $fieldName . '" is missing';
                    }
                }
                
                continue;
            }
            
            $value = $data[$fieldName];
            
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
        
        return true;
    }
    
    /**
     * Check if a table is read-only
     */
    protected function isTableReadOnly(string $table): bool
    {
        // Check if the table is in the list of read-only tables
        $readOnlyTables = [
            'sys_log',
            'sys_history',
            'sys_refindex',
            'sys_registry',
            'sys_lockedrecords',
            'sys_filemounts',
            'sys_file_processedfile',
            'sys_file_reference',
            'sys_file_metadata',
            'sys_file_storage',
            'sys_file',
            'sys_collection',
            'sys_category',
            'sys_action',
            'sys_domain',
            'sys_template',
            'be_users',
            'be_groups',
        ];
        
        return in_array($table, $readOnlyTables);
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
}
