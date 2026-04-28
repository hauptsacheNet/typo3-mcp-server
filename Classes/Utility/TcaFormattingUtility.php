<?php

declare(strict_types=1);

namespace Hn\McpServer\Utility;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\LanguageService as McpLanguageService;

/**
 * Utility for formatting TCA and FlexForm information
 */
class TcaFormattingUtility
{

    /**
     * Add field details inline for TCA or FlexForm configuration
     *
     * @param string &$result The result string to append to
     * @param array $config The field configuration
     * @param string $fieldName Optional field name for special handling
     * @param string $table Optional table name for authMode filtering
     */
    public static function addFieldDetailsInline(string &$result, $config, string $fieldName = '', string $table = ''): void
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
                
                // Check for typolink support via softref
                if (isset($config['softref']) && strpos($config['softref'], 'typolink_tag') !== false) {
                    $result .= " [Supports typolinks - Examples: t3://page?uid=123 for pages, t3://record?identifier=table&uid=456 for records, t3://file?uid=789 for files, https://example.com for external URLs, mailto:email@example.com for emails]";
                }
                break;
                
            case 'text':
                if (isset($config['cols'])) {
                    $result .= " [cols: " . $config['cols'] . "]";
                }
                if (isset($config['rows'])) {
                    $result .= " [rows: " . $config['rows'] . "]";
                }
                
                // Check for richtext enabled
                if (isset($config['enableRichtext']) && $config['enableRichtext']) {
                    $result .= " [Richtext/HTML]";
                }
                
                // Check for typolink support via softref
                if (isset($config['softref']) && strpos($config['softref'], 'typolink_tag') !== false) {
                    $result .= " [Supports typolinks - Examples: t3://page?uid=123 for pages, t3://record?identifier=table&uid=456 for records, t3://file?uid=789 for files, https://example.com for external URLs, mailto:email@example.com for emails]";
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
                    $tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
                    $parsed = $tableAccessService->parseSelectItems($config['items'], false); // Include dividers

                    // Check if this field has authMode restrictions
                    $hasAuthMode = !empty($config['authMode']);
                    $beUser = $GLOBALS['BE_USER'] ?? null;
                    $isAdmin = $beUser && $beUser->isAdmin();

                    $options = [];
                    foreach ($parsed['values'] as $value) {
                        // Skip dividers
                        if ($value === '--div--') {
                            continue;
                        }

                        // Filter by authMode for non-admin users
                        if ($hasAuthMode && !$isAdmin && $beUser && !empty($table) && !empty($fieldName)) {
                            if (!$beUser->checkAuthMode($table, $fieldName, $value)) {
                                continue; // User doesn't have permission for this value
                            }
                        }

                        $label = $parsed['labels'][$value] ?? '';
                        if ($label) {
                            $translatedLabel = TableAccessService::translateLabel($label);
                            $options[] = $value . " (" . $translatedLabel . ")";
                        }
                    }

                    if (!empty($options)) {
                        $result .= " [Options: " . implode(', ', $options) . "]";
                    }
                }
                
                // Special handling for sys_language_uid field
                if ($fieldName === 'sys_language_uid') {
                    // Add note about ISO code support
                    $languageService = GeneralUtility::makeInstance(McpLanguageService::class);
                    $isoCodes = $languageService->getAvailableIsoCodes();
                    
                    if (!empty($isoCodes)) {
                        $result .= " [ISO codes accepted: " . implode(', ', $isoCodes) . "]";
                        $result .= " (Use ISO codes like 'de' instead of numeric IDs in WriteTable tool)";
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
            case 'file':
                $foreignTable = $config['foreign_table'] ?? '';
                if (empty($foreignTable)) {
                    break;
                }

                $foreignTCA = $GLOBALS['TCA'][$foreignTable] ?? [];
                $isHiddenTable = ($foreignTCA['ctrl']['hideTable'] ?? false) === true;

                if ($isHiddenTable) {
                    // Embedded relation: LLM writes array of record objects.
                    // Spell out the three operations explicitly — without this, models
                    // routinely either omit the field entirely (afraid of dropping refs)
                    // or rebuild from scratch with uid_local=0 (broken).
                    $newExample = self::generateMiniExample($foreignTable);
                    $result .= ' [embedded records from ' . $foreignTable
                        . '. The array fully replaces the existing list (omitted entries are deleted; array order = display order).'
                        . ' Add new: ' . $newExample . '.'
                        . ' Keep/patch existing: {"uid": <existing entry uid>, ...} (only fields you set are patched; omit "uid_local" so the file stays attached).'
                        . ' Use GetTableSchema on ' . $foreignTable . ' for all fields.]';
                } else {
                    // Independent relation: LLM writes array of UIDs
                    $result .= ' [relation to ' . $foreignTable . ' - write as array of UIDs, e.g. [12, 34]]';
                }
                break;
                
            case 'flex':
                // Only applicable for TCA
                if (isset($config['ds_pointerField'])) {
                    $result .= " [ds_pointerField: " . $config['ds_pointerField'] . "]";
                }
                break;
                
            case 'language':
                // Special handling for language type fields (TYPO3 11.2+)
                // Add note about ISO code support
                $languageService = GeneralUtility::makeInstance(McpLanguageService::class);
                $isoCodes = $languageService->getAvailableIsoCodes();
                
                if (!empty($isoCodes)) {
                    $result .= " [ISO codes accepted: " . implode(', ', $isoCodes) . "]";
                    $result .= " (Use ISO codes like 'de' instead of numeric IDs in WriteTable tool)";
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

    /**
     * Generate a mini example object from a table's accessible fields.
     * Shows a few key user-editable fields to give the LLM a sense of the record structure.
     */
    protected static function generateMiniExample(string $foreignTable): string
    {
        $foreignTCA = $GLOBALS['TCA'][$foreignTable] ?? [];
        $columns = $foreignTCA['columns'] ?? [];

        // System/auto-managed fields to skip in the example
        $skipFields = [
            'pid', 'tstamp', 'crdate', 'deleted', 'hidden', 'sorting', 'sorting_foreign',
            'uid_foreign', 'tablenames', 'fieldname',
            'sys_language_uid', 'l10n_parent', 'l10n_diffsource', 'l10n_state',
            't3ver_oid', 't3ver_wsid', 't3ver_state', 't3_origuid',
        ];

        $exampleFields = [];
        $tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);

        foreach ($columns as $fieldName => $fieldConfig) {
            if (in_array($fieldName, $skipFields, true)) {
                continue;
            }

            if (!$tableAccessService->canAccessField($foreignTable, $fieldName)) {
                continue;
            }

            $config = $fieldConfig['config'] ?? [];
            $fieldType = $config['type'] ?? '';

            // Generate a placeholder value based on field type. Distinguish group
            // pointers (e.g. uid_local → sys_file) from plain numbers so the model
            // sees "<sys_file uid>" instead of a literal "0", which has previously
            // been copied verbatim and produced broken sys_file_references.
            switch ($fieldType) {
                case 'input':
                case 'text':
                case 'link':
                case 'email':
                case 'slug':
                    $exampleFields[$fieldName] = '...';
                    break;
                case 'group':
                    $allowed = $config['allowed'] ?? '';
                    $allowedTable = $allowed !== '' ? trim(explode(',', $allowed)[0]) : '';
                    $exampleFields[$fieldName] = $allowedTable !== ''
                        ? '<' . $allowedTable . ' uid>'
                        : '<uid>';
                    break;
                case 'number':
                case 'check':
                    $exampleFields[$fieldName] = '0';
                    break;
                default:
                    // Skip complex types (imageManipulation, flex, etc.) in the mini example
                    continue 2;
            }

            // Limit to a few fields to keep it concise
            if (count($exampleFields) >= 4) {
                break;
            }
        }

        if (empty($exampleFields)) {
            return '{}';
        }

        $parts = [];
        foreach ($exampleFields as $name => $placeholder) {
            // Render numeric / angle-bracket placeholders unquoted so they
            // signal "integer here" rather than a literal string value.
            $isNumericPlaceholder = $placeholder === '0' || str_starts_with($placeholder, '<');
            if ($isNumericPlaceholder) {
                $parts[] = '"' . $name . '": ' . $placeholder;
            } else {
                $parts[] = '"' . $name . '": "' . $placeholder . '"';
            }
        }

        return '{' . implode(', ', $parts) . '}';
    }
}
