<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for getting detailed schema information for a specific table
 */
class GetTableTypeTool extends AbstractRecordTool
{
    /**
     * Get the tool type
     */
    public function getToolType(): string
    {
        return 'schema';
    }

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Get detailed schema information for a specific table type, including fields, relations, and validation',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table name to get schema information for',
                    ],
                ],
                'required' => ['table'],
            ],
            'examples' => [
                [
                    'description' => 'Get schema for content elements',
                    'parameters' => [
                        'table' => 'tt_content'
                    ]
                ],
                [
                    'description' => 'Get schema for pages',
                    'parameters' => [
                        'table' => 'pages',
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
        
        if (empty($table)) {
            return $this->createErrorResult('Table name is required');
        }
        
        if (!$this->tableExists($table)) {
            return $this->createErrorResult('Table "' . $table . '" does not exist in TCA');
        }
        
        try {
            // Get table schema
            $schema = $this->getTableSchema($table);
            
            return $this->createSuccessResult($this->formatSchemaAsText($schema));
        } catch (\Throwable $e) {
            return $this->createErrorResult('Error getting table schema: ' . $e->getMessage());
        }
    }
    
    /**
     * Get the schema for a table
     */
    protected function getTableSchema(string $table): array
    {
        if (!isset($GLOBALS['TCA'][$table])) {
            throw new \InvalidArgumentException('Table "' . $table . '" does not exist in TCA');
        }
        
        $tca = $GLOBALS['TCA'][$table];
        $schema = [
            'name' => $table,
            'label' => $this->getTableLabel($table),
            'description' => $this->getTableDescription($table),
            'type' => $this->getTableType($table),
            'readOnly' => $this->isTableReadOnly($table),
            'fields' => [],
            'typeField' => $tca['ctrl']['type'] ?? null,
            'types' => [],
            'palettes' => [],
        ];
        
        // Add control fields
        if (isset($tca['ctrl'])) {
            $ctrl = $tca['ctrl'];
            $schema['ctrl'] = [
                'title' => $ctrl['title'] ?? '',
                'label' => $ctrl['label'] ?? '',
                'tstamp' => $ctrl['tstamp'] ?? '',
                'crdate' => $ctrl['crdate'] ?? '',
                'cruser_id' => $ctrl['cruser_id'] ?? '',
                'delete' => $ctrl['delete'] ?? '',
                'enablecolumns' => $ctrl['enablecolumns'] ?? [],
                'sortby' => $ctrl['sortby'] ?? '',
                'type' => $ctrl['type'] ?? '',
                'languageField' => $ctrl['languageField'] ?? '',
                'transOrigPointerField' => $ctrl['transOrigPointerField'] ?? '',
            ];
        }
        
        // Process fields
        if (isset($tca['columns'])) {
            foreach ($tca['columns'] as $fieldName => $fieldConfig) {
                $schema['fields'][$fieldName] = $this->processFieldConfig($fieldName, $fieldConfig, $table);
            }
        }
        
        // Process types (record types like CType for tt_content)
        if (isset($tca['types'])) {
            foreach ($tca['types'] as $typeName => $typeConfig) {
                $schema['types'][(string)$typeName] = $this->processTypeConfig((string)$typeName, $typeConfig);
            }
        }
        
        // Process palettes
        if (isset($tca['palettes'])) {
            foreach ($tca['palettes'] as $paletteName => $paletteConfig) {
                $schema['palettes'][(string)$paletteName] = $this->processPaletteConfig((string)$paletteName, $paletteConfig);
            }
        }
        
        return $schema;
    }
    
    /**
     * Process a field configuration
     */
    protected function processFieldConfig(string $fieldName, array $fieldConfig, string $table): array
    {
        $result = [
            'name' => $fieldName,
            'label' => $this->translateLabel($fieldConfig['label'] ?? $fieldName),
            'type' => $fieldConfig['config']['type'] ?? 'unknown',
            'required' => !empty($fieldConfig['config']['eval']) && strpos($fieldConfig['config']['eval'], 'required') !== false,
            'default' => $fieldConfig['config']['default'] ?? null,
        ];
        
        // Add field-type specific information
        switch ($result['type']) {
            case 'input':
                $result['maxLength'] = $fieldConfig['config']['max'] ?? null;
                $result['eval'] = $fieldConfig['config']['eval'] ?? '';
                $result['renderType'] = $fieldConfig['config']['renderType'] ?? 'default';
                break;
                
            case 'text':
                $result['cols'] = $fieldConfig['config']['cols'] ?? 30;
                $result['rows'] = $fieldConfig['config']['rows'] ?? 5;
                $result['eval'] = $fieldConfig['config']['eval'] ?? '';
                break;
                
            case 'check':
                $result['items'] = $this->processItems($fieldConfig['config']['items'] ?? []);
                break;
                
            case 'radio':
                $result['items'] = $this->processItems($fieldConfig['config']['items'] ?? []);
                break;
                
            case 'select':
                $result['items'] = $this->processItems($fieldConfig['config']['items'] ?? []);
                $result['foreign_table'] = $fieldConfig['config']['foreign_table'] ?? null;
                $result['multiple'] = !empty($fieldConfig['config']['multiple']);
                break;
                
            case 'group':
                $result['allowed'] = $fieldConfig['config']['allowed'] ?? '';
                $result['internal_type'] = $fieldConfig['config']['internal_type'] ?? '';
                $result['maxitems'] = $fieldConfig['config']['maxitems'] ?? 0;
                break;
                
            case 'inline':
                $result['foreign_table'] = $fieldConfig['config']['foreign_table'] ?? null;
                $result['foreign_field'] = $fieldConfig['config']['foreign_field'] ?? null;
                $result['maxitems'] = $fieldConfig['config']['maxitems'] ?? 0;
                
                // Add nested schema for inline relations
                if (!empty($result['foreign_table']) && $this->tableExists($result['foreign_table'])) {
                    // Avoid infinite recursion
                    if ($result['foreign_table'] !== $table) {
                        // Get a simplified schema for the foreign table
                        $foreignSchema = $this->getSimplifiedTableSchema($result['foreign_table']);
                        $result['foreign_table_schema'] = $foreignSchema;
                    } else {
                        $result['foreign_table_schema'] = [
                            'name' => $result['foreign_table'],
                            'label' => $this->getTableLabel($result['foreign_table']),
                            'note' => 'Schema omitted to avoid recursion',
                        ];
                    }
                }
                break;
        }
        
        // Check if this is a language field
        if (isset($GLOBALS['TCA'][$table]['ctrl']['languageField']) && $GLOBALS['TCA'][$table]['ctrl']['languageField'] === $fieldName) {
            $result['isLanguageField'] = true;
        }
        
        // Check if this is a type field
        if (isset($GLOBALS['TCA'][$table]['ctrl']['type']) && $GLOBALS['TCA'][$table]['ctrl']['type'] === $fieldName) {
            $result['isTypeField'] = true;
        }
        
        return $result;
    }
    
    /**
     * Process TCA items to handle both old and new formats
     */
    protected function processItems(array $items): array
    {
        $result = [];
        
        foreach ($items as $item) {
            // Handle new associative array format (TYPO3 v12+)
            if (is_array($item) && isset($item['value'])) {
                $itemValue = $item['value'];
                
                // Skip divider items
                if ($itemValue === '--div--') {
                    continue;
                }
                
                $processedItem = [
                    'value' => $itemValue,
                    'label' => $item['label'] ?? $itemValue
                ];
                
                if (isset($item['icon'])) {
                    $processedItem['icon'] = $item['icon'];
                }
                
                if (isset($item['group'])) {
                    $processedItem['group'] = $item['group'];
                }
                
                // Translate label if it's a language reference
                if (is_string($processedItem['label']) && strpos($processedItem['label'], 'LLL:') === 0) {
                    $processedItem['label'] = $this->translateLabel($processedItem['label']);
                }
                
                $result[] = $processedItem;
            }
            // Handle old indexed array format
            else if (is_array($item) && isset($item[1])) {
                $itemValue = $item[1];
                
                // Skip divider items
                if ($itemValue === '--div--') {
                    continue;
                }
                
                $processedItem = [
                    'value' => $itemValue,
                    'label' => $item[0] ?? $itemValue
                ];
                
                if (isset($item[2])) {
                    $processedItem['icon'] = $item[2];
                }
                
                // Translate label if it's a language reference
                if (is_string($processedItem['label']) && strpos($processedItem['label'], 'LLL:') === 0) {
                    $processedItem['label'] = $this->translateLabel($processedItem['label']);
                }
                
                $result[] = $processedItem;
            }
            // Skip invalid items
            else {
                continue;
            }
        }
        
        return $result;
    }
    
    /**
     * Process a type configuration
     */
    protected function processTypeConfig(string $typeName, array $typeConfig): array
    {
        $result = [
            'name' => (string)$typeName, // Ensure typeName is a string
            'showitem' => $typeConfig['showitem'] ?? '',
        ];
        
        // Process showitem to extract fields and palettes
        if (!empty($result['showitem'])) {
            $items = GeneralUtility::trimExplode(',', $result['showitem'], true);
            $result['fields'] = [];
            
            foreach ($items as $item) {
                $itemParts = GeneralUtility::trimExplode(';', $item, true);
                $itemName = $itemParts[0];
                
                if (strpos($itemName, '--palette--') === 0) {
                    // This is a palette reference
                    $paletteName = $itemParts[1] ?? '';
                    $result['fields'][] = [
                        'type' => 'palette',
                        'name' => $paletteName,
                    ];
                } else {
                    // This is a regular field
                    $result['fields'][] = [
                        'type' => 'field',
                        'name' => $itemName,
                        'label' => $itemParts[1] ?? '',
                    ];
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Process a palette configuration
     */
    protected function processPaletteConfig(string $paletteName, array $paletteConfig): array
    {
        $result = [
            'name' => $paletteName,
            'label' => $this->translateLabel($paletteConfig['label'] ?? $paletteName),
            'showitem' => $paletteConfig['showitem'] ?? '',
        ];
        
        // Process showitem to extract fields
        if (!empty($result['showitem'])) {
            $items = GeneralUtility::trimExplode(',', $result['showitem'], true);
            $result['fields'] = [];
            
            foreach ($items as $item) {
                $itemParts = GeneralUtility::trimExplode(';', $item, true);
                $itemName = $itemParts[0];
                
                $result['fields'][] = [
                    'name' => $itemName,
                    'label' => $itemParts[1] ?? '',
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * Get a simplified schema for a table (to avoid deep recursion)
     */
    protected function getSimplifiedTableSchema(string $table): array
    {
        if (!isset($GLOBALS['TCA'][$table])) {
            return [
                'name' => $table,
                'error' => 'Table does not exist in TCA',
            ];
        }
        
        $tca = $GLOBALS['TCA'][$table];
        $schema = [
            'name' => $table,
            'label' => $this->getTableLabel($table),
            'fields' => [],
        ];
        
        // Add only basic field information
        if (isset($tca['columns'])) {
            foreach ($tca['columns'] as $fieldName => $fieldConfig) {
                $schema['fields'][$fieldName] = [
                    'name' => $fieldName,
                    'label' => $this->translateLabel($fieldConfig['label'] ?? $fieldName),
                    'type' => $fieldConfig['config']['type'] ?? 'unknown',
                ];
            }
        }
        
        return $schema;
    }
    
    /**
     * Get an example record for a table
     */
    protected function getExampleRecord(string $table): ?array
    {
        try {
            // Skip for certain system tables
            if (in_array($table, ['sys_registry', 'sys_log', 'be_users', 'be_groups'])) {
                return null;
            }
            
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $queryBuilder = $connectionPool->getQueryBuilderForTable($table);
            
            // Get a single record
            $record = $queryBuilder->select('*')
                ->from($table)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            
            if ($record) {
                // Process the record to make it more readable
                foreach ($record as $field => $value) {
                    // Convert binary data to a placeholder
                    if (is_resource($value)) {
                        $record[$field] = '[BINARY DATA]';
                    }
                    
                    // Truncate long text
                    if (is_string($value) && strlen($value) > 100) {
                        $record[$field] = substr($value, 0, 100) . '...';
                    }
                }
                
                return $record;
            }
            
            return null;
        } catch (\Throwable $e) {
            // If we can't get an example, just return null
            return null;
        }
    }
    
    /**
     * Format schema as text
     */
    protected function formatSchemaAsText(array $schema): string
    {
        $result = "TABLE SCHEMA: " . $schema['name'] . "\n";
        $result .= "=======================" . str_repeat("=", strlen($schema['name'])) . "\n\n";
        
        $result .= "Label: " . $schema['label'] . "\n";
        $result .= "Type: " . $schema['type'] . "\n";
        
        if ($schema['readOnly']) {
            $result .= "Status: READ-ONLY\n";
        }
        
        if (!empty($schema['description'])) {
            $result .= "Description: " . $schema['description'] . "\n";
        }
        
        $result .= "\nCONTROL FIELDS:\n";
        $result .= "-------------\n";
        
        if (isset($schema['ctrl'])) {
            foreach ($schema['ctrl'] as $key => $value) {
                if (empty($value)) {
                    continue;
                }
                
                if (is_array($value)) {
                    $result .= $key . ": " . json_encode($value) . "\n";
                } else {
                    $result .= $key . ": " . $value . "\n";
                }
            }
        }
        
        // If there's a type field, highlight it
        $typeField = $schema['typeField'] ?? null;
        if ($typeField) {
            $result .= "\nTYPE FIELD: " . $typeField . " (determines which fields are shown)\n";
        }
        
        $result .= "\nFIELDS:\n";
        $result .= "------\n";
        
        if (!empty($schema['fields'])) {
            foreach ($schema['fields'] as $fieldName => $fieldConfig) {
                $result .= $fieldName . " (" . $fieldConfig['label'] . ")\n";
                $result .= "  Type: " . $fieldConfig['type'];
                
                if ($fieldConfig['required']) {
                    $result .= " [REQUIRED]";
                }
                
                if (isset($fieldConfig['isLanguageField']) && $fieldConfig['isLanguageField']) {
                    $result .= " [LANGUAGE FIELD]";
                }
                
                if (isset($fieldConfig['isTypeField']) && $fieldConfig['isTypeField']) {
                    $result .= " [TYPE FIELD]";
                }
                
                $result .= "\n";
                
                // Add type-specific details
                switch ($fieldConfig['type']) {
                    case 'select':
                        if (!empty($fieldConfig['items'])) {
                            $result .= "  Options:\n";
                            foreach ($fieldConfig['items'] as $item) {
                                $itemLabel = $item['label'] ?? '';
                                $itemValue = $item['value'] ?? '';
                                if (is_string($itemLabel) && strpos($itemLabel, 'LLL:') === 0) {
                                    $itemLabel = $this->translateLabel($itemLabel);
                                }
                                $result .= "    - " . $itemValue . " (" . $itemLabel . ")\n";
                            }
                        }
                        
                        if (!empty($fieldConfig['foreign_table'])) {
                            $result .= "  Foreign Table: " . $fieldConfig['foreign_table'] . "\n";
                        }
                        break;
                        
                    case 'inline':
                        if (!empty($fieldConfig['foreign_table'])) {
                            $result .= "  Foreign Table: " . $fieldConfig['foreign_table'] . "\n";
                            $result .= "  Foreign Field: " . $fieldConfig['foreign_field'] . "\n";
                            
                            if (!empty($fieldConfig['foreign_table_schema'])) {
                                $result .= "  Foreign Table Fields:\n";
                                foreach ($fieldConfig['foreign_table_schema']['fields'] as $foreignField) {
                                    $result .= "    - " . $foreignField['name'] . " (" . $foreignField['label'] . "): " . $foreignField['type'] . "\n";
                                }
                            }
                        }
                        break;
                }
                
                $result .= "\n";
            }
        }
        
        // Add record types if available
        if (!empty($schema['types']) && !empty($typeField)) {
            $result .= "\nRECORD TYPES (based on " . $typeField . " field):\n";
            $result .= "-------------------------------------\n";
            
            foreach ($schema['types'] as $typeName => $typeConfig) {
                $result .= "Type: " . $typeName . "\n";
                $result .= "  Fields:\n";
                
                if (!empty($typeConfig['fields'])) {
                    foreach ($typeConfig['fields'] as $field) {
                        if ($field['type'] === 'palette') {
                            $result .= "    - Palette: " . $field['name'] . "\n";
                        } else {
                            $result .= "    - " . $field['name'] . (!empty($field['label']) ? " (" . $field['label'] . ")" : "") . "\n";
                        }
                    }
                }
                
                $result .= "\n";
            }
        }
        
        // Add example record if available
        if (!empty($schema['example'])) {
            $result .= "\nEXAMPLE RECORD:\n";
            $result .= "--------------\n";
            
            foreach ($schema['example'] as $field => $value) {
                $result .= $field . ": " . (is_array($value) ? json_encode($value) : $value) . "\n";
            }
        }
        
        return $result;
    }
    
    /**
     * Get a description for a table
     */
    protected function getTableDescription(string $table): string
    {
        // For now, we don't have a good way to get descriptions
        return '';
    }
    
    /**
     * Get the type of a table (content, system, etc.)
     */
    protected function getTableType(string $table): string
    {
        // Core content tables
        if (in_array($table, ['tt_content', 'pages', 'sys_category'])) {
            return 'content';
        }
        
        // File-related tables
        if (in_array($table, ['sys_file', 'sys_file_reference', 'sys_file_metadata'])) {
            return 'file';
        }
        
        // System tables
        if (strpos($table, 'sys_') === 0) {
            return 'system';
        }
        
        // Backend tables
        if (strpos($table, 'be_') === 0) {
            return 'backend';
        }
        
        // Frontend tables
        if (strpos($table, 'fe_') === 0) {
            return 'frontend';
        }
        
        // Extension content tables (most tx_ tables)
        if (strpos($table, 'tx_') === 0) {
            // Domain model tables are usually content
            if (strpos($table, '_domain_model_') !== false) {
                return 'content';
            }
            return 'extension';
        }
        
        return 'other';
    }
    
    /**
     * Check if a table is read-only
     */
    protected function isTableReadOnly(string $table): bool
    {
        if (!$this->tableExists($table)) {
            return true;
        }
        
        // Tables without a TCA ctrl section are considered read-only
        if (empty($GLOBALS['TCA'][$table]['ctrl'])) {
            return true;
        }
        
        // Tables with readOnly flag are read-only
        if (!empty($GLOBALS['TCA'][$table]['ctrl']['readOnly'])) {
            return true;
        }
        
        // Tables without a label field are typically not editable
        if (empty($GLOBALS['TCA'][$table]['ctrl']['label'])) {
            return true;
        }
        
        return false;
    }
}
