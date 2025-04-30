<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
            'description' => 'Create, update, or delete records in TYPO3 tables with TCA validation',
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
                        'description' => 'Record data (required for "create" and "update" actions)',
                    ],
                ],
            ],
            'examples' => [
                [
                    'description' => 'Create a new content element',
                    'parameters' => [
                        'action' => 'create',
                        'table' => 'tt_content',
                        'pid' => 123,
                        'data' => [
                            'CType' => 'text',
                            'header' => 'New content element',
                            'bodytext' => '<p>This is a new content element</p>'
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
                            'header' => 'Updated header',
                            'bodytext' => '<p>Updated content</p>'
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
            ]
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
                    return $this->createRecord($table, $pid, $data);
                    
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
    protected function createRecord(string $table, int $pid, array $data): CallToolResult
    {
        // Ensure pid is set
        $data['pid'] = $pid;
        
        // Validate data against TCA
        $validationResult = $this->validateRecordData($table, $data, 'create');
        if ($validationResult !== true) {
            return $this->createErrorResult('Validation error: ' . $validationResult);
        }
        
        // Create the record using DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->bypassWorkspaceRestrictions = true;
        $dataHandler->start([$table => ['NEW' => $data]], []);
        $dataHandler->process_datamap();
        
        // Check for errors
        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error creating record: ' . implode(', ', $dataHandler->errorLog));
        }
        
        // Get the UID of the newly created record
        $newUid = $dataHandler->substNEWwithIDs['NEW'] ?? null;
        if (!$newUid) {
            return $this->createErrorResult('Failed to create record');
        }
        
        return $this->createJsonResult([
            'status' => 'success',
            'action' => 'create',
            'table' => $table,
            'uid' => $newUid,
        ]);
    }
    
    /**
     * Update an existing record
     */
    protected function updateRecord(string $table, int $uid, array $data): CallToolResult
    {
        // Validate data against TCA
        $validationResult = $this->validateRecordData($table, $data, 'update');
        if ($validationResult !== true) {
            return $this->createErrorResult('Validation error: ' . $validationResult);
        }
        
        // Update the record using DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->bypassWorkspaceRestrictions = true;
        $dataHandler->start([$table => [$uid => $data]], []);
        $dataHandler->process_datamap();
        
        // Check for errors
        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error updating record: ' . implode(', ', $dataHandler->errorLog));
        }
        
        return $this->createJsonResult([
            'status' => 'success',
            'action' => 'update',
            'table' => $table,
            'uid' => $uid,
        ]);
    }
    
    /**
     * Delete a record
     */
    protected function deleteRecord(string $table, int $uid): CallToolResult
    {
        // Delete the record using DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->bypassWorkspaceRestrictions = true;
        $dataHandler->start([], [$table => [$uid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();
        
        // Check for errors
        if (!empty($dataHandler->errorLog)) {
            return $this->createErrorResult('Error deleting record: ' . implode(', ', $dataHandler->errorLog));
        }
        
        return $this->createJsonResult([
            'status' => 'success',
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
}
