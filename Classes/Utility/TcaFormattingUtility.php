<?php

declare(strict_types=1);

namespace Hn\McpServer\Utility;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Utility for formatting TCA and FlexForm information
 */
class TcaFormattingUtility
{
    /**
     * Translate a label
     */
    public static function translateLabel(string $label): string
    {
        // If the label is a LLL reference, translate it
        if (strpos($label, 'LLL:') === 0) {
            // Initialize language service if needed
            if (!isset($GLOBALS['LANG']) || !$GLOBALS['LANG'] instanceof LanguageService) {
                $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
                $GLOBALS['LANG'] = $languageServiceFactory->create('default');
            }
            
            return $GLOBALS['LANG']->sL($label) ?: $label;
        }
        
        return $label;
    }

    /**
     * Add field details inline for TCA or FlexForm configuration
     */
    public static function addFieldDetailsInline(string &$result, $config): void
    {
        // Get the field type
        $type = $config['type'] ?? '';
        
        // Add field details based on type
        switch ($type) {
            case 'input':
                if (isset($config['size'])) {
                    $result .= " [size: " . $config['size'] . "]";
                }
                if (isset($config['max'])) {
                    $result .= " [max: " . $config['max'] . "]";
                }
                break;
                
            case 'text':
                if (isset($config['cols'])) {
                    $result .= " [cols: " . $config['cols'] . "]";
                }
                if (isset($config['rows'])) {
                    $result .= " [rows: " . $config['rows'] . "]";
                }
                break;
                
            case 'check':
                if (isset($config['default'])) {
                    $result .= " [Default: " . $config['default'] . "]";
                }
                break;
                
            case 'select':
                // Add renderType if available
                if (isset($config['renderType'])) {
                    $result .= " [renderType: " . $config['renderType'] . "]";
                }
                
                // Add foreign table and MM information
                if (isset($config['foreign_table'])) {
                    $result .= " [foreign table: " . $config['foreign_table'] . "]";
                }
                if (isset($config['MM'])) {
                    $result .= " [MM table: " . $config['MM'] . "]";
                }
                
                // Add select options if available
                if (isset($config['items']) && is_array($config['items'])) {
                    $options = [];
                    
                    foreach ($config['items'] as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        
                        $itemValue = '';
                        $itemLabel = '';
                        
                        // Handle both associative and numeric index syntax
                        if (isset($item['value']) && isset($item['label'])) {
                            // New associative syntax
                            $itemValue = $item['value'];
                            $itemLabel = self::translateLabel($item['label']);
                        } elseif (isset($item[0]) && isset($item[1])) {
                            // Old numeric index syntax
                            $itemValue = $item[1];
                            $itemLabel = self::translateLabel($item[0]);
                        } elseif (isset($item['numIndex']) && is_array($item['numIndex'])) {
                            // XML converted to array format
                            if (isset($item['numIndex']['label']) && isset($item['numIndex']['value'])) {
                                $itemLabel = self::translateLabel($item['numIndex']['label']);
                                $itemValue = $item['numIndex']['value'];
                            }
                        }
                        
                        if ($itemLabel) {
                            $options[] = $itemValue . " (" . $itemLabel . ")";
                        }
                    }
                    
                    if (!empty($options)) {
                        $result .= " [Options: " . implode(', ', $options) . "]";
                    }
                }
                break;
                
            case 'group':
                // Add allowed table if available
                if (isset($config['allowed'])) {
                    $result .= " [allowed: " . $config['allowed'] . "]";
                }
                break;
                
            case 'inline':
                // Add foreign table if available
                if (isset($config['foreign_table'])) {
                    $result .= " [foreign table: " . $config['foreign_table'] . "]";
                }
                break;
                
            case 'flex':
                // Only applicable for TCA
                if (isset($config['ds_pointerField'])) {
                    $result .= " [ds_pointerField: " . $config['ds_pointerField'] . "]";
                }
                break;
        }
        
        // Add required flag if set
        if (isset($config['eval']) && strpos($config['eval'], 'required') !== false) {
            $result .= " [Required]";
        }
        
        // Add default value if set
        if (isset($config['default']) && $type !== 'check') {
            $result .= " [Default: " . $config['default'] . "]";
        }
    }
}
