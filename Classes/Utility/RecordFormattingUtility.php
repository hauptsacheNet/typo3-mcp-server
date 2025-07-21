<?php

declare(strict_types=1);

namespace Hn\McpServer\Utility;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Hn\McpServer\Service\TableAccessService;

/**
 * Utility class for formatting TYPO3 records consistently across MCP tools
 */
class RecordFormattingUtility
{
    /**
     * Get table label from TCA
     */
    public static function getTableLabel(string $table): string
    {
        if (!empty($GLOBALS['TCA'][$table]['ctrl']['title'])) {
            return TableAccessService::translateLabel($GLOBALS['TCA'][$table]['ctrl']['title']);
        }
        
        // Fallback to humanized table name
        return ucfirst(str_replace(['tx_', '_'], ['', ' '], $table));
    }

    /**
     * Get a meaningful title for a record
     */
    public static function getRecordTitle(string $table, array $record): string
    {
        // Try to use BackendUtility to get a proper record title
        try {
            $title = BackendUtility::getRecordTitle($table, $record);
            if (!empty($title)) {
                return $title;
            }
        } catch (\Throwable $e) {
            // Fall back to manual title detection
        }
        
        // Use the TCA label field if defined
        if (isset($GLOBALS['TCA'][$table]['ctrl']['label']) && !empty($record[$GLOBALS['TCA'][$table]['ctrl']['label']])) {
            return $record[$GLOBALS['TCA'][$table]['ctrl']['label']];
        }
        
        // Common title fields in TYPO3
        $titleFields = ['title', 'header', 'name', 'username', 'first_name', 'lastname', 'subject'];
        
        foreach ($titleFields as $field) {
            if (!empty($record[$field])) {
                return $record[$field];
            }
        }
        
        // Last resort, just return the UID
        return 'Record #' . $record['uid'];
    }

    /**
     * Get a label for a content type
     */
    public static function getContentTypeLabel(string $cType): string
    {
        if (isset($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'])) {
            $items = $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'];
            foreach ($items as $item) {
                // Handle both old and new TCA item formats
                if (is_array($item) && isset($item['value']) && $item['value'] === $cType) {
                    return TableAccessService::translateLabel($item['label']);
                } elseif (is_array($item) && isset($item[1]) && $item[1] === $cType) {
                    return TableAccessService::translateLabel($item[0]);
                }
            }
        }
        
        // Fallback to a humanized version of the CType
        return ucfirst(str_replace('_', ' ', $cType));
    }

    /**
     * Get column position definitions
     */
    public static function getColumnPositionDefinitions(): array
    {
        // Default column positions
        $colPosDefs = [
            0 => 'Main Content',
            1 => 'Left',
            2 => 'Right',
            3 => 'Border',
            4 => 'Footer',
        ];
        
        // Try to get column positions from page TSconfig
        if (isset($GLOBALS['TYPO3_CONF_VARS']['BE']['defaultPageTSconfig'])) {
            $tsconfigString = $GLOBALS['TYPO3_CONF_VARS']['BE']['defaultPageTSconfig'];
            if (preg_match_all('/mod\.wizards\.newContentElement\.wizardItems\..*?\.elements\..*?\.tt_content_defValues\.colPos\s*=\s*(\d+)/', $tsconfigString, $matches)) {
                foreach ($matches[1] as $colPos) {
                    if (!isset($colPosDefs[$colPos])) {
                        // Try to find the label for this column position
                        if (preg_match('/mod\.wizards\.newContentElement\.wizardItems\..*?\.elements\..*?\.title\s*=\s*(.+)/', $tsconfigString, $labelMatches)) {
                            $colPosDefs[$colPos] = $labelMatches[1];
                        } else {
                            $colPosDefs[$colPos] = 'Column ' . $colPos;
                        }
                    }
                }
            }
        }
        
        // Check for backend layouts
        if (isset($GLOBALS['TCA']['backend_layout']['columns']['config']['config']['items'])) {
            $items = $GLOBALS['TCA']['backend_layout']['columns']['config']['config']['items'];
            foreach ($items as $item) {
                if (is_array($item) && isset($item[1]) && preg_match('/colPos=(\d+)/', $item[1], $matches)) {
                    $colPos = (int)$matches[1];
                    if (!isset($colPosDefs[$colPos])) {
                        $colPosDefs[$colPos] = TableAccessService::translateLabel($item[0]);
                    }
                }
            }
        }
        
        return $colPosDefs;
    }

    /**
     * Check if a table has a pid field
     */
    public static function tableHasPidField(string $table): bool
    {
        // System tables that don't have pid
        $tablesWithoutPid = [
            'be_users', 'be_groups', 'sys_registry', 'sys_log', 'sys_history', 
            'sys_file', 'be_sessions', 'fe_sessions', 'sys_domain'
        ];

        return !in_array($table, $tablesWithoutPid);
    }


    /**
     * Apply default sorting from TCA to a query builder
     */
    public static function applyDefaultSorting($queryBuilder, string $table): void
    {
        if (!isset($GLOBALS['TCA'][$table]['ctrl'])) {
            return;
        }

        $ctrl = $GLOBALS['TCA'][$table]['ctrl'];

        // Check for sortby field
        if (!empty($ctrl['sortby'])) {
            $queryBuilder->orderBy($ctrl['sortby'], 'ASC');
            return;
        }

        // Check for default_sortby
        if (!empty($ctrl['default_sortby'])) {
            $sortParts = GeneralUtility::trimExplode(',', str_replace('ORDER BY', '', $ctrl['default_sortby']), true);
            foreach ($sortParts as $sortPart) {
                $sortPart = trim($sortPart);
                if (preg_match('/^(.*?)\s+(ASC|DESC)$/i', $sortPart, $matches)) {
                    $field = trim($matches[1]);
                    $direction = strtoupper($matches[2]);
                    $queryBuilder->addOrderBy($field, $direction);
                } else {
                    $queryBuilder->addOrderBy($sortPart, 'ASC');
                }
            }
            return;
        }

        // Default to ordering by UID
        $queryBuilder->orderBy('uid', 'ASC');
    }

    /**
     * Format content preview for display
     */
    public static function formatContentPreview(string $content, int $maxLength = 100): string
    {
        if (empty($content)) {
            return '';
        }

        // Remove HTML tags
        $content = strip_tags($content);
        
        // Normalize whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        
        // Truncate if too long
        if (mb_strlen($content) > $maxLength) {
            $content = mb_substr($content, 0, $maxLength) . '...';
        }
        
        return $content;
    }

    /**
     * Extract a snippet around a search query
     */
    public static function extractSnippet(string $content, string $query, int $contextLength = 50): string
    {
        $pos = stripos($content, $query);
        if ($pos === false) {
            return '';
        }

        $start = max(0, $pos - $contextLength);
        $length = $contextLength * 2 + strlen($query);
        
        $snippet = substr($content, $start, $length);
        
        // Add ellipsis if needed
        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        if ($start + $length < strlen($content)) {
            $snippet .= '...';
        }

        // Highlight the query (simple text-based highlighting)
        $snippet = str_ireplace($query, "**$query**", $snippet);

        return trim($snippet);
    }
}