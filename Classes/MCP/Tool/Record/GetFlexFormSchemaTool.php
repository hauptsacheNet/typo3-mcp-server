<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Hn\McpServer\Utility\TcaFormattingUtility;

/**
 * Tool for getting FlexForm schema information
 */
class GetFlexFormSchemaTool extends AbstractRecordTool
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
            'description' => 'Get schema information for a specific FlexForm field',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table name containing the FlexForm field (default: tt_content)',
                        'default' => 'tt_content',
                    ],
                    'field' => [
                        'type' => 'string',
                        'description' => 'The field name containing the FlexForm data (default: pi_flexform)',
                        'default' => 'pi_flexform',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'The FlexForm identifier to get schema information for (required)',
                        'required' => true,
                    ],
                ],
                'required' => ['identifier'],
            ],
            'examples' => [
                [
                    'description' => 'Get schema information for a specific FlexForm',
                    'parameters' => [
                        'table' => 'tt_content',
                        'field' => 'pi_flexform',
                        'identifier' => 'form_formframework',
                    ],
                ],
            ],
        ];
    }

    /**
     * Execute the tool
     */
    public function execute(array $params): CallToolResult
    {
        // Get parameters
        $table = $params['table'] ?? 'tt_content';
        $field = $params['field'] ?? 'pi_flexform';
        $identifier = $params['identifier'] ?? '';

        // Validate parameters
        if (empty($identifier)) {
            return $this->createErrorResult('Identifier parameter is required');
        }

        // Validate table access using TableAccessService
        try {
            $this->ensureTableAccess($table, 'read');
        } catch (\InvalidArgumentException $e) {
            return $this->createErrorResult($e->getMessage());
        }

        // Check if the table and field exist
        if (!isset($GLOBALS['TCA'][$table]['columns'][$field])) {
            return $this->createErrorResult("Field '$field' not found in table '$table'");
        }

        // Check if the field is a FlexForm field
        if ($GLOBALS['TCA'][$table]['columns'][$field]['config']['type'] !== 'flex') {
            return $this->createErrorResult("Field '$field' in table '$table' is not a FlexForm field");
        }

        // Get the FlexForm configuration
        $flexFormConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'];

        // Special handling for form_formframework
        if ($identifier === 'form_formframework') {
            $identifier = '*,form_formframework';
        }

        // Build the result
        $result = "FLEXFORM SCHEMA: $identifier\n";
        $result .= "=======================================\n\n";
        $result .= "Table: $table\n";
        $result .= "Field: $field\n\n";

        // Check if the identifier exists directly in the ds array
        if (isset($flexFormConfig['ds'][$identifier])) {
            $dsValue = $flexFormConfig['ds'][$identifier];

            // Handle FILE: references
            if (is_string($dsValue) && strpos($dsValue, 'FILE:') === 0) {
                $file = substr($dsValue, 5);
                $file = GeneralUtility::getFileAbsFileName($file);
                $result .= "Schema defined in file: " . $file . "\n\n";

                if (file_exists($file)) {
                    $content = file_get_contents($file);
                    if (!empty($content)) {
                        // Parse the XML content using TYPO3's built-in method
                        $xmlArray = GeneralUtility::xml2array($content);
                        
                        if ($xmlArray) {
                            // Process sheets
                            if (isset($xmlArray['sheets'])) {
                                $result .= "SHEETS:\n";
                                $result .= "-------\n";
                                
                                foreach ($xmlArray['sheets'] as $sheetName => $sheet) {
                                    $result .= "Sheet: $sheetName\n";
                                    
                                    // Process fields
                                    if (isset($sheet['ROOT']['el'])) {
                                        $result .= "  Fields:\n";
                                        
                                        foreach ($sheet['ROOT']['el'] as $fieldName => $field) {
                                            $result .= "  - $fieldName";
                                            
                                            // Get field type and label
                                            $fieldType = 'unknown';
                                            $fieldLabel = $fieldName;
                                            $fieldDescription = '';
                                            
                                            // Handle both TCEforms structure and direct field configuration
                                            if (isset($field['TCEforms'])) {
                                                // TCEforms structure (older format)
                                                // Get field label
                                                if (isset($field['TCEforms']['label'])) {
                                                    $fieldLabel = TcaFormattingUtility::translateLabel($field['TCEforms']['label']);
                                                    $result .= " (" . $fieldLabel . ")";
                                                }
                                                
                                                // Get field type and config
                                                if (isset($field['TCEforms']['config']['type'])) {
                                                    $fieldType = $field['TCEforms']['config']['type'];
                                                    $result .= ": " . $fieldType;
                                                    
                                                    // Add field details based on type
                                                    TcaFormattingUtility::addFieldDetailsInline($result, $field['TCEforms']['config']);
                                                }
                                                
                                                // Get field description
                                                if (isset($field['TCEforms']['description'])) {
                                                    $fieldDescription = TcaFormattingUtility::translateLabel($field['TCEforms']['description']);
                                                    $result .= " - " . $fieldDescription;
                                                }
                                            } else {
                                                // Direct field configuration (newer format)
                                                // Get field label
                                                if (isset($field['label'])) {
                                                    $fieldLabel = TcaFormattingUtility::translateLabel($field['label']);
                                                    $result .= " (" . $fieldLabel . ")";
                                                }
                                                
                                                // Get field type and config
                                                if (isset($field['config']['type'])) {
                                                    $fieldType = $field['config']['type'];
                                                    $result .= ": " . $fieldType;
                                                    
                                                    // Add field details based on type
                                                    TcaFormattingUtility::addFieldDetailsInline($result, $field['config']);
                                                }
                                                
                                                // Get field description
                                                if (isset($field['description'])) {
                                                    $fieldDescription = TcaFormattingUtility::translateLabel($field['description']);
                                                    $result .= " - " . $fieldDescription;
                                                }
                                            }
                                            
                                            $result .= "\n";
                                        }
                                    }
                                    
                                    $result .= "\n";
                                }
                            } elseif (isset($xmlArray['ROOT']['el'])) {
                                $result .= "FIELDS:\n";
                                $result .= "------\n";
                                
                                foreach ($xmlArray['ROOT']['el'] as $fieldName => $field) {
                                    $result .= "- $fieldName";
                                    
                                    // Get field type and label
                                    $fieldType = 'unknown';
                                    $fieldLabel = $fieldName;
                                    $fieldDescription = '';
                                    
                                    // Handle both TCEforms structure and direct field configuration
                                    if (isset($field['TCEforms'])) {
                                        // TCEforms structure (older format)
                                        // Get field label
                                        if (isset($field['TCEforms']['label'])) {
                                            $fieldLabel = TcaFormattingUtility::translateLabel($field['TCEforms']['label']);
                                            $result .= " (" . $fieldLabel . ")";
                                        }
                                        
                                        // Get field type and config
                                        if (isset($field['TCEforms']['config']['type'])) {
                                            $fieldType = $field['TCEforms']['config']['type'];
                                            $result .= ": " . $fieldType;
                                            
                                            // Add field details based on type
                                            TcaFormattingUtility::addFieldDetailsInline($result, $field['TCEforms']['config']);
                                        }
                                        
                                        // Get field description
                                        if (isset($field['TCEforms']['description'])) {
                                            $fieldDescription = TcaFormattingUtility::translateLabel($field['TCEforms']['description']);
                                            $result .= " - " . $fieldDescription;
                                        }
                                    } else {
                                        // Direct field configuration (newer format)
                                        // Get field label
                                        if (isset($field['label'])) {
                                            $fieldLabel = TcaFormattingUtility::translateLabel($field['label']);
                                            $result .= " (" . $fieldLabel . ")";
                                        }
                                        
                                        // Get field type and config
                                        if (isset($field['config']['type'])) {
                                            $fieldType = $field['config']['type'];
                                            $result .= ": " . $fieldType;
                                            
                                            // Add field details based on type
                                            TcaFormattingUtility::addFieldDetailsInline($result, $field['config']);
                                        }
                                        
                                        // Get field description
                                        if (isset($field['description'])) {
                                            $fieldDescription = TcaFormattingUtility::translateLabel($field['description']);
                                            $result .= " - " . $fieldDescription;
                                        }
                                    }
                                    
                                    $result .= "\n";
                                }
                                
                                $result .= "\n";
                            }
                        } else {
                            $result .= "Failed to parse XML schema\n";
                        }

                        // Generate a JSON example
                        $result .= "JSON EXAMPLE:\n";
                        $result .= "============\n";

                        // Create a simplified structure that matches what ReadTableTool will return
                        $example = [];

                        // Process sheets
                        if (isset($xmlArray['sheets'])) {
                            foreach ($xmlArray['sheets'] as $sheetName => $sheet) {
                                if (isset($sheet['ROOT']['el'])) {
                                    foreach ($sheet['ROOT']['el'] as $fieldName => $field) {
                                        // Get field type
                                        $fieldType = isset($field['TCEforms']['config']['type']) ? $field['TCEforms']['config']['type'] : 'input';

                                        // Generate example value based on field type
                                        switch ($fieldType) {
                                            case 'input':
                                                $example[$fieldName] = "Example text";
                                                break;
                                            case 'text':
                                                $example[$fieldName] = "Example multi-line text";
                                                break;
                                            case 'check':
                                                $example[$fieldName] = true;
                                                break;
                                            case 'select':
                                                $example[$fieldName] = "option1";
                                                break;
                                            default:
                                                $example[$fieldName] = "Example value";
                                        }
                                    }
                                }
                            }
                        } elseif (isset($xmlArray['ROOT']['el'])) {
                            foreach ($xmlArray['ROOT']['el'] as $fieldName => $field) {
                                // Get field type
                                $fieldType = isset($field['TCEforms']['config']['type']) ? $field['TCEforms']['config']['type'] : 'input';

                                // Generate example value based on field type
                                switch ($fieldType) {
                                    case 'input':
                                        $example[$fieldName] = "Example text";
                                        break;
                                    case 'text':
                                        $example[$fieldName] = "Example multi-line text";
                                        break;
                                    case 'check':
                                        $example[$fieldName] = true;
                                        break;
                                    case 'select':
                                        $example[$fieldName] = "option1";
                                        break;
                                    default:
                                        $example[$fieldName] = "Example value";
                                }
                            }
                        }

                        // For form_formframework, provide a more specific example
                        if ($identifier === '*,form_formframework') {
                            $example = [
                                'settings' => [
                                    'persistenceIdentifier' => '1:/form_definitions/example.form.yaml',
                                    'overrideFinishers' => '0'
                                ]
                            ];
                        }

                        $result .= json_encode(['pi_flexform' => $example], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    } else {
                        return $this->createErrorResult("FlexForm file is empty: $file");
                    }
                } else {
                    return $this->createErrorResult("FlexForm file not found: $file");
                }
            } elseif (is_string($dsValue)) {
                $result .= "Schema defined inline as XML\n\n";

                // Parse the XML content using TYPO3's built-in method
                $xmlArray = GeneralUtility::xml2array($dsValue);
                
                if ($xmlArray) {
                    // Process sheets
                    if (isset($xmlArray['sheets'])) {
                        $result .= "SHEETS:\n";
                        $result .= "-------\n";
                        
                        foreach ($xmlArray['sheets'] as $sheetName => $sheet) {
                            $result .= "Sheet: $sheetName\n";
                            
                            // Process fields
                            if (isset($sheet['ROOT']['el'])) {
                                $result .= "  Fields:\n";
                                
                                foreach ($sheet['ROOT']['el'] as $fieldName => $field) {
                                    $result .= "  - $fieldName";
                                    
                                    // Get field type and label
                                    $fieldType = 'unknown';
                                    $fieldLabel = $fieldName;
                                    $fieldDescription = '';
                                    
                                    // Handle both TCEforms structure and direct field configuration
                                    if (isset($field['TCEforms'])) {
                                        // TCEforms structure (older format)
                                        // Get field label
                                        if (isset($field['TCEforms']['label'])) {
                                            $fieldLabel = TcaFormattingUtility::translateLabel($field['TCEforms']['label']);
                                            $result .= " (" . $fieldLabel . ")";
                                        }
                                        
                                        // Get field type and config
                                        if (isset($field['TCEforms']['config']['type'])) {
                                            $fieldType = $field['TCEforms']['config']['type'];
                                            $result .= ": " . $fieldType;
                                            
                                            // Add field details based on type
                                            TcaFormattingUtility::addFieldDetailsInline($result, $field['TCEforms']['config']);
                                        }
                                        
                                        // Get field description
                                        if (isset($field['TCEforms']['description'])) {
                                            $fieldDescription = TcaFormattingUtility::translateLabel($field['TCEforms']['description']);
                                            $result .= " - " . $fieldDescription;
                                        }
                                    } else {
                                        // Direct field configuration (newer format)
                                        // Get field label
                                        if (isset($field['label'])) {
                                            $fieldLabel = TcaFormattingUtility::translateLabel($field['label']);
                                            $result .= " (" . $fieldLabel . ")";
                                        }
                                        
                                        // Get field type and config
                                        if (isset($field['config']['type'])) {
                                            $fieldType = $field['config']['type'];
                                            $result .= ": " . $fieldType;
                                            
                                            // Add field details based on type
                                            TcaFormattingUtility::addFieldDetailsInline($result, $field['config']);
                                        }
                                        
                                        // Get field description
                                        if (isset($field['description'])) {
                                            $fieldDescription = TcaFormattingUtility::translateLabel($field['description']);
                                            $result .= " - " . $fieldDescription;
                                        }
                                    }
                                    
                                    $result .= "\n";
                                }
                            }
                            
                            $result .= "\n";
                        }
                    } elseif (isset($xmlArray['ROOT']['el'])) {
                        $result .= "FIELDS:\n";
                        $result .= "------\n";
                        
                        foreach ($xmlArray['ROOT']['el'] as $fieldName => $field) {
                            $result .= "- $fieldName";
                            
                            // Get field type and label
                            $fieldType = 'unknown';
                            $fieldLabel = $fieldName;
                            $fieldDescription = '';
                            
                            // Handle both TCEforms structure and direct field configuration
                            if (isset($field['TCEforms'])) {
                                // TCEforms structure (older format)
                                // Get field label
                                if (isset($field['TCEforms']['label'])) {
                                    $fieldLabel = TcaFormattingUtility::translateLabel($field['TCEforms']['label']);
                                    $result .= " (" . $fieldLabel . ")";
                                }
                                
                                // Get field type and config
                                if (isset($field['TCEforms']['config']['type'])) {
                                    $fieldType = $field['TCEforms']['config']['type'];
                                    $result .= ": " . $fieldType;
                                    
                                    // Add field details based on type
                                    TcaFormattingUtility::addFieldDetailsInline($result, $field['TCEforms']['config']);
                                }
                                
                                // Get field description
                                if (isset($field['TCEforms']['description'])) {
                                    $fieldDescription = TcaFormattingUtility::translateLabel($field['TCEforms']['description']);
                                    $result .= " - " . $fieldDescription;
                                }
                            } else {
                                // Direct field configuration (newer format)
                                // Get field label
                                if (isset($field['label'])) {
                                    $fieldLabel = TcaFormattingUtility::translateLabel($field['label']);
                                    $result .= " (" . $fieldLabel . ")";
                                }
                                
                                // Get field type and config
                                if (isset($field['config']['type'])) {
                                    $fieldType = $field['config']['type'];
                                    $result .= ": " . $fieldType;
                                    
                                    // Add field details based on type
                                    TcaFormattingUtility::addFieldDetailsInline($result, $field['config']);
                                }
                                
                                // Get field description
                                if (isset($field['description'])) {
                                    $fieldDescription = TcaFormattingUtility::translateLabel($field['description']);
                                    $result .= " - " . $fieldDescription;
                                }
                            }
                            
                            $result .= "\n";
                        }
                        
                        $result .= "\n";
                    }
                } else {
                    $result .= "Failed to parse XML schema\n";
                }

                // Generate a JSON example
                $result .= "JSON EXAMPLE:\n";
                $result .= "============\n";
                $result .= json_encode(['pi_flexform' => ['example' => 'This is an example of the FlexForm data structure']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif (is_array($dsValue)) {
                $result .= "Schema defined as PHP array\n\n";

                // Generate a JSON example
                $result .= "JSON EXAMPLE:\n";
                $result .= "============\n";
                $result .= json_encode(['pi_flexform' => ['example' => 'This is an example of the FlexForm data structure']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            return $this->createSuccessResult($result);
        }

        // If we get here, the identifier was not found
        return $this->createErrorResult("FlexForm schema not found for identifier: $identifier");
    }

    /**
     * Get all possible values for a pointer field
     */
    protected function getPointerFieldValues(string $table, string $field): array
    {
        $values = [];

        if (isset($GLOBALS['TCA'][$table]['columns'][$field]['config']['items'])) {
            foreach ($GLOBALS['TCA'][$table]['columns'][$field]['config']['items'] as $item) {
                if (isset($item['value'])) {
                    $values[] = $item['value'];
                } elseif (isset($item[1])) {
                    $values[] = $item[1];
                }
            }
        }

        return $values;
    }

    /**
     * Generate a JSON example for the FlexForm
     */
    protected function generateJsonExample(array $flexFormDS): string
    {
        // Check if we have a valid FlexForm structure
        if (empty($flexFormDS)) {
            return json_encode(['pi_flexform' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Create a simplified structure that matches what ReadTableTool will return
        $example = [];

        // Process sheets
        if (isset($flexFormDS['sheets']) && is_array($flexFormDS['sheets'])) {
            foreach ($flexFormDS['sheets'] as $sheetName => $sheetConfig) {
                if (isset($sheetConfig['ROOT']['el']) && is_array($sheetConfig['ROOT']['el'])) {
                    foreach ($sheetConfig['ROOT']['el'] as $fieldName => $fieldConfig) {
                        $example[$fieldName] = $this->getExampleValueForField($fieldConfig);
                    }
                }
            }
        } elseif (isset($flexFormDS['ROOT']['el']) && is_array($flexFormDS['ROOT']['el'])) {
            foreach ($flexFormDS['ROOT']['el'] as $fieldName => $fieldConfig) {
                $example[$fieldName] = $this->getExampleValueForField($fieldConfig);
            }
        }

        return json_encode(['pi_flexform' => $example], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get an example value for a FlexForm field based on its configuration
     */
    protected function getExampleValueForField(array $fieldConfig): mixed
    {
        // Handle section containers
        if (isset($fieldConfig['type']) && $fieldConfig['type'] === 'array') {
            $sectionExample = [];
            if (isset($fieldConfig['el']) && is_array($fieldConfig['el'])) {
                foreach ($fieldConfig['el'] as $sectionFieldName => $sectionFieldConfig) {
                    $sectionExample[$sectionFieldName] = $this->getExampleValueForField($sectionFieldConfig);
                }
            }
            return [$sectionExample]; // Return as array to represent multiple section items
        }

        // Get the field configuration
        $config = $fieldConfig['config'] ?? [];
        if (empty($config) && isset($fieldConfig['TCEforms']['config'])) {
            $config = $fieldConfig['TCEforms']['config'];
        }

        $fieldType = $config['type'] ?? '';

        switch ($fieldType) {
            case 'input':
                return 'Example text';

            case 'text':
                return 'Example multi-line text';

            case 'check':
                return true;

            case 'select':
                // Try to get the first item from items array
                if (!empty($config['items'])) {
                    $firstItem = reset($config['items']);
                    if (is_array($firstItem)) {
                        return $firstItem[1] ?? '1';
                    }
                }
                return '1';

            case 'group':
                return '1,2,3';

            case 'inline':
                return [1, 2, 3];

            default:
                return 'Example value';
        }
    }

    /**
     * Process a FlexForm field and return its description
     */
    protected function processFlexFormField(string $fieldName, array $fieldConfig, int $level): string
    {
        $result = '';
        $indent = str_repeat('  ', $level);

        // Extract field configuration
        $tceForms = $fieldConfig['TCEforms'] ?? [];
        $config = $tceForms['config'] ?? [];
        $type = $config['type'] ?? 'unknown';
        $label = TcaFormattingUtility::translateLabel($tceForms['label'] ?? $fieldName);

        // Handle section containers
        if (isset($fieldConfig['type']) && $fieldConfig['type'] === 'array') {
            $result .= "$indent- $fieldName (Section Container):\n";

            if (isset($fieldConfig['section']) && $fieldConfig['section'] === '1') {
                $result .= "$indent  Section: true\n";
            }

            if (isset($fieldConfig['el']) && is_array($fieldConfig['el'])) {
                $result .= "$indent  Elements:\n";

                foreach ($fieldConfig['el'] as $sectionFieldName => $sectionFieldConfig) {
                    $result .= $this->processFlexFormField($sectionFieldName, $sectionFieldConfig, $level + 2);
                }
            }

            return $result;
        }

        // Regular field
        $result .= "$indent- $fieldName ($type): $label\n";

        // Add field configuration details
        if (!empty($config)) {
            // Field size
            if (isset($config['size'])) {
                $result .= "$indent  Size: " . $config['size'] . "\n";
            }

            // Field max length
            if (isset($config['max'])) {
                $result .= "$indent  Max Length: " . $config['max'] . "\n";
            }

            // Field validation rules
            if (isset($config['eval'])) {
                $result .= "$indent  Validation: " . $config['eval'] . "\n";
            }

            // Select field items
            if ($type === 'select' && isset($config['items']) && is_array($config['items'])) {
                $result .= "$indent  Options:\n";

                foreach ($config['items'] as $item) {
                    $itemLabel = '';
                    $itemValue = '';

                    if (isset($item['label'])) {
                        $itemLabel = TcaFormattingUtility::translateLabel($item['label']);
                        $itemValue = $item['value'] ?? '';
                    } elseif (isset($item[0])) {
                        $itemLabel = TcaFormattingUtility::translateLabel($item[0]);
                        $itemValue = $item[1] ?? '';
                    }

                    $result .= "$indent    - $itemValue: $itemLabel\n";
                }
            }

            // Checkbox field
            if ($type === 'check') {
                $result .= "$indent  Default: " . ($config['default'] ?? '0') . "\n";
            }

            // Relation fields (group, select with foreign_table)
            if (isset($config['foreign_table'])) {
                $result .= "$indent  Foreign Table: " . $config['foreign_table'] . "\n";
            }
        }

        return $result;
    }

    /**
     * Add field details inline
     */
    protected function addFieldDetailsInline(string &$result, $config): void
    {
        TcaFormattingUtility::addFieldDetailsInline($result, $config);
    }

    /**
     * Get all available FlexForms for a table and field
     */
    protected function getAvailableFlexForms(string $table, string $field): array
    {
        $result = [];

        // Check if the table and field exist
        if (!isset($GLOBALS['TCA'][$table]['columns'][$field])) {
            return $result;
        }

        // Check if the field is a FlexForm field
        if ($GLOBALS['TCA'][$table]['columns'][$field]['config']['type'] !== 'flex') {
            return $result;
        }

        $flexFormConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'];

        // Handle ds_pointerField configuration
        if (!empty($flexFormConfig['ds_pointerField'])) {
            $pointerField = $flexFormConfig['ds_pointerField'];
            $pointerFieldConfig = $GLOBALS['TCA'][$table]['columns'][$pointerField] ?? [];

            // Get the possible values for the pointer field
            if (!empty($pointerFieldConfig['config']['items'])) {
                foreach ($pointerFieldConfig['config']['items'] as $item) {
                    $value = $item[1] ?? '';
                    if (!empty($value)) {
                        $result[$value] = [
                            'id' => $value,
                            'label' => $item[0] ?? $value,
                        ];
                    }
                }
            }
        }

        // Handle ds configuration
        if (!empty($flexFormConfig['ds']) && is_array($flexFormConfig['ds'])) {
            foreach ($flexFormConfig['ds'] as $key => $ds) {
                if (is_string($ds) && strpos($ds, 'FILE:') === 0) {
                    $file = substr($ds, 5);
                    $result[$key] = [
                        'id' => $key,
                        'file' => $file,
                    ];
                } else {
                    $result[$key] = [
                        'id' => $key,
                    ];
                }
            }
        }

        // Add default FlexForm if available
        if (!empty($flexFormConfig['ds']['default'])) {
            $result['default'] = [
                'id' => 'default',
            ];
        }

        return $result;
    }

    /**
     * Get the FlexForm data structure for a specific identifier
     */
    protected function getFlexFormDS(string $table, string $field, string $identifier): array
    {
        // Check if the table and field exist
        if (!isset($GLOBALS['TCA'][$table]['columns'][$field])) {
            return [];
        }

        // Check if the field is a FlexForm field
        if ($GLOBALS['TCA'][$table]['columns'][$field]['config']['type'] !== 'flex') {
            return [];
        }

        $flexFormConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'];
        $ds = $flexFormConfig['ds'] ?? [];

        // Try to get the FlexForm DS directly from the configuration
        if (!empty($ds[$identifier])) {
            $flexFormDS = $ds[$identifier];

            // Handle FILE: references
            if (is_string($flexFormDS) && strpos($flexFormDS, 'FILE:') === 0) {
                $file = substr($flexFormDS, 5);
                $file = GeneralUtility::getFileAbsFileName($file);

                if (file_exists($file)) {
                    $content = file_get_contents($file);
                    if (!empty($content)) {
                        $flexFormService = GeneralUtility::makeInstance(FlexFormService::class);
                        return $flexFormService->convertFlexFormContentToArray($content);
                    }
                }
            } elseif (is_string($flexFormDS)) {
                $flexFormService = GeneralUtility::makeInstance(FlexFormService::class);
                return $flexFormService->convertFlexFormContentToArray($flexFormDS);
            } elseif (is_array($flexFormDS)) {
                return $flexFormDS;
            }
        }

        return [];
    }

    /**
     * Process a FlexForm data structure and return a human-readable description
     */
    protected function processFlexFormDS(array $flexFormDS, string $identifier): string
    {
        $result = '';

        // Process sheets
        if (isset($flexFormDS['sheets']) && is_array($flexFormDS['sheets'])) {
            foreach ($flexFormDS['sheets'] as $sheetName => $sheetConfig) {
                $sheetLabel = TcaFormattingUtility::translateLabel($sheetName);
                $result .= "SHEET: $sheetLabel\n";
                $result .= str_repeat("-", strlen("SHEET: $sheetLabel")) . "\n";

                // Process the fields
                if (isset($sheetConfig['ROOT']['el']) && is_array($sheetConfig['ROOT']['el'])) {
                    foreach ($sheetConfig['ROOT']['el'] as $fieldName => $fieldConfig) {
                        $result .= $this->processFlexFormField($fieldName, $fieldConfig, 0);
                    }
                }

                $result .= "\n";
            }
        } elseif (isset($flexFormDS['ROOT']['el']) && is_array($flexFormDS['ROOT']['el'])) {
            foreach ($flexFormDS['ROOT']['el'] as $fieldName => $fieldConfig) {
                $result .= $this->processFlexFormField($fieldName, $fieldConfig, 0);
            }
        } else {
            $result .= "No fields found in FlexForm data structure.\n";
        }

        return $result;
    }
}
