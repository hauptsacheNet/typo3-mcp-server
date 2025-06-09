<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use Hn\McpServer\Utility\TcaFormattingUtility;

/**
 * Tool for getting detailed schema information for a specific table
 */
class GetTableSchemaTool extends AbstractRecordTool
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
                        'description' => 'The table to get schema information for (e.g., "tt_content", "pages")',
                        'required' => true
                    ],
                    'type' => [
                        'type' => 'string',
                        'description' => 'Optional specific type to include (e.g., "text" for tt_content). If not provided, will show the first available type and a summary of all types.',
                        'required' => false
                    ],
                ],
                'required' => ['table'],
            ],
            'examples' => [
                [
                    'description' => 'Get schema for content elements',
                    'parameters' => [
                        'table' => 'tt_content',
                    ]
                ],
                [
                    'description' => 'Get schema for a specific content element type',
                    'parameters' => [
                        'table' => 'tt_content',
                        'type' => 'textmedia'
                    ]
                ],
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
            return $this->createErrorResult('Table parameter is required');
        }
        
        if (!isset($GLOBALS['TCA'][$table])) {
            return $this->createErrorResult('Table not found in TCA: ' . $table);
        }
        
        try {
            $filterType = $params['type'] ?? '';
            
            $result = $this->generateTableSchema($table, $filterType);
            return $this->createSuccessResult($result);
        } catch (\Throwable $e) {
            return $this->createErrorResult('Error getting table schema: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate a table schema as text
     */
    protected function generateTableSchema(string $table, string $filterType = ''): string
    {
        $result = "";
        
        // Basic table info
        $tableLabel = '';
        if (!empty($GLOBALS['TCA'][$table]['ctrl']['title'])) {
            $tableLabel = $this->translateLabel($GLOBALS['TCA'][$table]['ctrl']['title']);
        }
        
        $result .= "TABLE SCHEMA: " . $table . " (" . $tableLabel . ")\n";
        $result .= "=======================================\n\n";
        
        // Check if the table is read-only
        $readOnly = !empty($GLOBALS['TCA'][$table]['ctrl']['readOnly']);
        
        // Get workspace capability info
        $workspaceInfo = $this->getWorkspaceCapabilityInfo($table);
        
        $result .= "Type: content\n";
        $result .= "Read-Only: " . ($readOnly ? "Yes" : "No") . "\n";
        $result .= "Workspace-Capable: " . ($workspaceInfo['workspace_capable'] ? "Yes" : "No") . "\n";
        if (!$workspaceInfo['workspace_capable']) {
            $result .= "Workspace Info: " . $workspaceInfo['reason'] . "\n";
        }
        $result .= "\n";
        
        // Add control fields section - only the most important ones
        $result .= "CONTROL FIELDS:\n";
        $result .= "--------------\n";
        
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $importantFields = [
            'label', 'label_alt', 'descriptionColumn', 
            'title', 'type', 'languageField', 'transOrigPointerField', 
            'translationSource', 'searchFields'
        ];
        
        foreach ($importantFields as $key) {
            if (isset($ctrl[$key])) {
                $value = $ctrl[$key];
                // Format arrays as JSON
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $result .= $key . ": " . $value . "\n";
            }
        }
        
        $result .= "\n\n";
        
        // Get the type field
        $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'] ?? '';
        $excludeTypes = $this->getRemovedTypesByTSconfig($table, $typeField);
        
        // Get available types
        $types = [];
        
        if (!empty($typeField) && isset($GLOBALS['TCA'][$table]['columns'][$typeField])) {
            $typeConfig = $GLOBALS['TCA'][$table]['columns'][$typeField]['config'] ?? [];
            $items = $typeConfig['items'] ?? [];
            
            foreach ($items as $item) {
                if (is_array($item)) {
                    $value = '';
                    $label = '';
                    
                    if (isset($item['value']) && isset($item['label'])) {
                        $value = $item['value'];
                        $label = $this->translateLabel($item['label']);
                    } elseif (isset($item[0]) && isset($item[1])) {
                        $value = $item[1];
                        $label = $this->translateLabel($item[0]);
                    }
                    
                    // Skip excluded types
                    if (in_array($value, $excludeTypes)) {
                        continue;
                    }
                    
                    $types[$value] = $label;
                }
            }
        } else {
            // If no type field, use the default type
            $types['1'] = 'Default';
        }
        
        // If no types are available after filtering, show an error
        if (empty($types)) {
            return "ERROR: No valid types available for this table after applying excludeTypes filter.";
        }
        
        // If a specific type is requested, check if it exists
        if (!empty($filterType) && !isset($types[$filterType])) {
            return "ERROR: The requested type '$filterType' does not exist or has been excluded. Available types are: " . implode(', ', array_keys($types));
        }
        
        // If no specific type is requested, use the first available type
        if (empty($filterType)) {
            // Skip dividers when selecting the default type
            foreach ($types as $typeValue => $typeLabel) {
                if ($typeValue !== '--div--') {
                    $filterType = $typeValue;
                    break;
                }
            }
        }
        
        // Get the type label
        $typeLabel = $types[$filterType] ?? '';
        
        // Add current record type section
        $result .= "CURRENT RECORD TYPE:\n";
        $result .= "-------------------\n";
        $result .= "Type: " . $filterType . " (" . $typeLabel . ")\n\n";
        
        // Add fields section
        $result .= "FIELDS:\n";
        $result .= "-------\n";
        
        // Get the columns for this type
        $typeConfig = $GLOBALS['TCA'][$table]['types'][$filterType] ?? [];
        $showitem = $typeConfig['showitem'] ?? '';
        
        if (empty($showitem)) {
            $result .= "No fields defined for this type.\n";
            return $result;
        }
        
        // Parse the showitem string
        $fields = GeneralUtility::trimExplode(',', $showitem, true);
        
        // Group fields by tab
        $tabFields = [];
        $currentTab = 'General';
        
        foreach ($fields as $item) {
            $itemParts = GeneralUtility::trimExplode(';', $item, true);
            $fieldName = $itemParts[0];
            
            // Check if this is a tab
            if ($fieldName === '--div--') {
                $tabLabel = $itemParts[1] ?? 'Tab';
                $currentTab = $tabLabel; // Store the original label for later translation
                $tabFields[$currentTab] = [];
            } else {
                $tabFields[$currentTab][] = $item;
            }
        }
        
        // Process each tab's fields
        $processedFields = [];
        
        foreach ($tabFields as $tabName => $tabFieldsList) {
            // Translate the tab name
            $translatedTabName = $this->translateLabel($tabName);
            $result .= "  (" . $translatedTabName . "):\n";
            
            foreach ($tabFieldsList as $item) {
                $itemParts = GeneralUtility::trimExplode(';', $item, true);
                $fieldName = $itemParts[0];
                
                // Check if this is a palette
                if ($fieldName === '--palette--' || strpos($fieldName, '--palette--') === 0) {
                    // Extract palette name from the parts
                    $paletteParts = explode(';', $item);
                    $paletteName = $paletteParts[2] ?? '';
                    $paletteLabel = $paletteParts[1] ?? '';
                    
                    // Translate the palette label if it's a language reference
                    if (!empty($paletteLabel)) {
                        $paletteLabel = $this->translateLabel($paletteLabel);
                    }
                    
                    // Use palette name as fallback if label is empty
                    if (empty($paletteLabel)) {
                        $paletteLabel = ucfirst(str_replace('_', ' ', $paletteName));
                    }
                    
                    if (!empty($paletteName) && isset($GLOBALS['TCA'][$table]['palettes'][$paletteName])) {
                        // Add the palette to the current tab's fields
                        $result .= "    ┌─ (" . $paletteLabel . ")\n";
                        
                        // Get the palette fields
                        $paletteFields = $GLOBALS['TCA'][$table]['palettes'][$paletteName]['showitem'] ?? '';
                        $paletteFieldsList = GeneralUtility::trimExplode(',', $paletteFields, true);
                        
                        // Process each palette field
                        $lastPaletteField = end($paletteFieldsList);
                        reset($paletteFieldsList);
                        
                        foreach ($paletteFieldsList as $paletteItem) {
                            $paletteItemParts = GeneralUtility::trimExplode(';', $paletteItem, true);
                            $paletteFieldName = $paletteItemParts[0];
                            
                            // Skip special fields
                            if ($paletteFieldName === '--linebreak--') {
                                continue;
                            }
                            
                            // Add the field to the result if it exists in the TCA
                            if (isset($GLOBALS['TCA'][$table]['columns'][$paletteFieldName])) {
                                $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$paletteFieldName];
                                
                                // Mark as processed
                                $processedFields[$paletteFieldName] = true;
                                
                                // Add the field to the result with proper indentation
                                $prefix = ($paletteItem === $lastPaletteField) ? "└─ " : "├─ ";
                                $fieldLabel = isset($fieldConfig['label']) ? $this->translateLabel($fieldConfig['label']) : $paletteFieldName;
                                $fieldType = $fieldConfig['config']['type'] ?? 'unknown';
                                $result .= "    " . $prefix . $paletteFieldName . " (" . $fieldLabel . "): " . $fieldType;
                                
                                // Add field details inline
                                $this->addFieldDetailsInline($result, $fieldConfig, $paletteFieldName, $table, $filterType);
                                $result .= "\n";
                            }
                        }
                    }
                } else {
                    // Regular field
                    if (isset($GLOBALS['TCA'][$table]['columns'][$fieldName])) {
                        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$fieldName];
                        
                        // Mark as processed
                        $processedFields[$fieldName] = true;
                        
                        // Add the field to the result
                        $fieldLabel = isset($fieldConfig['label']) ? $this->translateLabel($fieldConfig['label']) : $fieldName;
                        $fieldType = $fieldConfig['config']['type'] ?? 'unknown';
                        $result .= "    - " . $fieldName . " (" . $fieldLabel . "): " . $fieldType;
                        
                        // Add field details inline
                        $this->addFieldDetailsInline($result, $fieldConfig, $fieldName, $table, $filterType);
                        $result .= "\n";
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Add field details inline
     */
    protected function addFieldDetailsInline(string &$result, array $fieldConfig, string $fieldName, string $table, string $filterType = ''): void
    {
        $config = $fieldConfig['config'] ?? [];
        $type = $config['type'] ?? '';
        
        // Add field details based on type
        if ($type === 'flex') {
            // For flex fields, show the available FlexForm identifiers
            $this->addFlexFormIdentifiers($result, $config, $table, $fieldName, $filterType);
        } else {
            // For other field types, use the TcaFormattingUtility
            TcaFormattingUtility::addFieldDetailsInline($result, $config);
        }
    }
    
    /**
     * Add FlexForm identifiers to the result
     */
    protected function addFlexFormIdentifiers(string &$result, array $config, string $table, string $fieldName, string $filterType = ''): void
    {
        $result .= " (FlexForm)";
        
        // Get available FlexForm identifiers
        if (isset($config['ds']) && is_array($config['ds'])) {
            $identifiers = array_keys($config['ds']);
            
            // Filter out default identifier
            $identifiers = array_filter($identifiers, function($id) {
                return $id !== 'default';
            });
            
            // Filter identifiers based on the requested type
            if (!empty($filterType) && !empty($config['ds_pointerField'])) {
                $pointerFields = GeneralUtility::trimExplode(',', $config['ds_pointerField'], true);
                
                // Filter identifiers that match the current type
                // Either directly or with a wildcard
                $filteredIdentifiers = [];
                foreach ($identifiers as $id) {
                    if (strpos($id, ',') !== false) {
                        $parts = explode(',', $id);
                        // Check if the identifier matches the current type
                        // Either directly or with a wildcard
                        if (($parts[0] === '*' && $parts[1] === $filterType) || 
                            ($parts[1] === '*' && $parts[0] === $filterType) ||
                            ($parts[0] === $filterType) ||
                            ($parts[1] === $filterType)) {
                            $filteredIdentifiers[] = $id;
                        }
                    } elseif ($id === $filterType) {
                        $filteredIdentifiers[] = $id;
                    }
                }
                
                // Use filtered identifiers if any were found
                if (!empty($filteredIdentifiers)) {
                    $identifiers = $filteredIdentifiers;
                }
            }
            
            if (!empty($identifiers)) {
                $result .= " [Identifiers: " . implode(', ', $identifiers) . "]";
                $result .= " (Use GetFlexFormSchema tool with these identifiers for details)";
            }
        }
        
        // Add ds_pointerField information if available
        if (isset($config['ds_pointerField'])) {
            $result .= " [ds_pointerField: " . $config['ds_pointerField'] . "]";
        }
    }
    
    /**
     * Translate a label
     */
    protected function translateLabel(string $label): string
    {
        return TcaFormattingUtility::translateLabel($label);
    }
    
    /**
     * Get types that are removed by TSconfig
     * This uses the same logic as TcaSelectItems to determine which types are restricted
     */
    protected function getRemovedTypesByTSconfig(string $table, string $typeField): array
    {
        if (empty($table) || empty($typeField)) {
            return [];
        }
        
        $removedTypes = [];
        
        // Get TSconfig for the current page
        $TSconfig = BackendUtility::getPagesTSconfig(0);
        
        // Check TCEFORM.[table].[field].removeItems
        $fieldTSconfig = $TSconfig['TCEFORM.'][$table . '.'][$typeField . '.']['removeItems'] ?? '';
        if (!empty($fieldTSconfig)) {
            $removedTypes = GeneralUtility::trimExplode(',', $fieldTSconfig, true);
        }
        
        // For tt_content, also check TCEMAIN.table.tt_content.disableCTypes
        if ($table === 'tt_content' && $typeField === 'CType') {
            $disableCTypes = $TSconfig['TCEMAIN.']['table.']['tt_content.']['disableCTypes'] ?? '';
            if (!empty($disableCTypes)) {
                $disabledTypes = GeneralUtility::trimExplode(',', $disableCTypes, true);
                $removedTypes = array_merge($removedTypes, $disabledTypes);
            }
        }
        
        return $removedTypes;
    }
}
