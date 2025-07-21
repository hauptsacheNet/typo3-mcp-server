<?php

declare(strict_types=1);

namespace Hn\McpServer\Utility;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayout\DataProviderContext;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Database\ConnectionPool;
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
     * 
     * @param int|null $pageId The page ID to get backend layout for (optional)
     * @param bool &$hasCustomLayout Output parameter to indicate if a custom layout is in use
     * @return array
     */
    public static function getColumnPositionDefinitions(?int $pageId = null, bool &$hasCustomLayout = false): array
    {
        // Default column positions
        $colPosDefs = [
            0 => 'Main Content',
            1 => 'Left',
            2 => 'Right',
            3 => 'Border',
            4 => 'Footer',
        ];
        
        $hasCustomLayout = false;
        
        // Try to get columns from backend layout if page ID is provided
        if ($pageId !== null) {
            try {
                $backendLayout = self::getBackendLayoutForPage($pageId);
                if ($backendLayout && !empty($backendLayout['__config']['backend_layout.']['rows.'])) {
                    $layoutColumns = self::extractColumnsFromBackendLayout($backendLayout['__config']['backend_layout.']['rows.']);
                    if (!empty($layoutColumns)) {
                        $hasCustomLayout = true;
                        return $layoutColumns;
                    }
                }
            } catch (\Exception $e) {
                // Fall back to defaults on error
            }
        }
        
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

    /**
     * Get backend layout for a specific page
     * 
     * @param int $pageId
     * @return array|null
     */
    protected static function getBackendLayoutForPage(int $pageId): ?array
    {
        try {
            // Get page record to check for backend layout settings
            $pageRecord = BackendUtility::getRecord('pages', $pageId);
            if (!$pageRecord) {
                return null;
            }
            
            // First, try the simpler approach: check if backend_layout is set directly
            $backendLayoutIdentifier = $pageRecord['backend_layout'] ?? '';
            
            // If not set on this page, check parent pages for backend_layout_next_level
            if (empty($backendLayoutIdentifier) && $pageRecord['pid'] > 0) {
                $backendLayoutIdentifier = self::getInheritedBackendLayout($pageRecord['pid']);
            }
            
            if (!empty($backendLayoutIdentifier)) {
                // Check if it's a numeric ID (database record)
                if (is_numeric($backendLayoutIdentifier)) {
                    $layoutRecord = BackendUtility::getRecord('backend_layout', (int)$backendLayoutIdentifier);
                    if ($layoutRecord && !empty($layoutRecord['config'])) {
                        // Parse the backend layout config directly
                        $config = self::parseBackendLayoutConfig($layoutRecord['config']);
                        if ($config) {
                            return ['__config' => $config];
                        }
                    }
                } else {
                    // It's a string identifier, might be TSConfig-based
                    // Try to get TSConfig backend layout
                    $pageTsConfig = BackendUtility::getPagesTSconfig($pageId);
                    if (isset($pageTsConfig['mod.']['web_layout.']['BackendLayouts.'][$backendLayoutIdentifier . '.'])) {
                        $layoutConfig = $pageTsConfig['mod.']['web_layout.']['BackendLayouts.'][$backendLayoutIdentifier . '.'];
                        if (isset($layoutConfig['config.'])) {
                            return ['__config' => $layoutConfig['config.']];
                        }
                    }
                }
            }
            
            // Fallback: try using BackendLayoutView (but this often requires full backend context)
            try {
                $backendLayoutView = GeneralUtility::makeInstance(BackendLayoutView::class);
                $backendLayout = $backendLayoutView->getBackendLayoutForPage($pageId);
                
                if ($backendLayout) {
                    return $backendLayout->getStructure();
                }
            } catch (\Throwable $e) {
                // BackendLayoutView might not work in all contexts
            }
        } catch (\Throwable $e) {
            // Log error for debugging
            error_log('Backend layout detection error: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Get inherited backend layout from parent pages
     * 
     * @param int $parentId
     * @return string
     */
    protected static function getInheritedBackendLayout(int $parentId): string
    {
        $parentRecord = BackendUtility::getRecord('pages', $parentId, 'pid,backend_layout_next_level');
        
        if ($parentRecord) {
            // Check if parent has backend_layout_next_level set
            if (!empty($parentRecord['backend_layout_next_level'])) {
                return $parentRecord['backend_layout_next_level'];
            }
            
            // If parent has a parent, check recursively
            if ($parentRecord['pid'] > 0) {
                return self::getInheritedBackendLayout($parentRecord['pid']);
            }
        }
        
        return '';
    }
    
    /**
     * Extract column definitions from backend layout configuration
     * 
     * @param array $rows
     * @return array
     */
    protected static function extractColumnsFromBackendLayout(array $rows): array
    {
        $columns = [];
        
        foreach ($rows as $row) {
            if (!isset($row['columns.'])) {
                continue;
            }
            
            foreach ($row['columns.'] as $column) {
                if (!is_array($column) || !isset($column['colPos'])) {
                    continue;
                }
                
                $colPos = (int) $column['colPos'];
                $name = $column['name'] ?? 'Column ' . $colPos;
                
                // Translate the name if it's a language label
                if (str_starts_with($name, 'LLL:')) {
                    $name = TableAccessService::translateLabel($name);
                }
                
                $columns[$colPos] = $name;
            }
        }
        
        return $columns;
    }
    
    /**
     * Parse backend layout configuration from TypoScript-like format
     * 
     * @param string $config
     * @return array|null
     */
    protected static function parseBackendLayoutConfig(string $config): ?array
    {
        // Simple parser for backend layout config
        // We're looking for rows.X.columns.Y structure
        $lines = explode("\n", $config);
        $result = [];
        $currentPath = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Handle closing brace
            if ($line === '}') {
                array_pop($currentPath);
                continue;
            }
            
            // Handle property with opening brace
            if (preg_match('/^(\w+)\s*\{/', $line, $matches)) {
                $currentPath[] = $matches[1] . '.';
                continue;
            }
            
            // Handle simple assignment
            if (preg_match('/^(\w+)\s*=\s*(.*)/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                
                // Build the full path
                $fullPath = implode('', $currentPath) . $key;
                
                // Set value in result array using the path
                $pathParts = explode('.', $fullPath);
                $current = &$result;
                foreach ($pathParts as $part) {
                    if (empty($part)) continue;
                    if (!isset($current[$part . '.'])) {
                        $current[$part . '.'] = [];
                    }
                    $current = &$current[$part . '.'];
                }
                // Remove the last '.' and set the value
                $lastKey = array_pop($pathParts);
                if ($lastKey) {
                    $current = &$result;
                    foreach ($pathParts as $part) {
                        if (empty($part)) continue;
                        $current = &$current[$part . '.'];
                    }
                    $current[$lastKey] = $value;
                }
            }
        }
        
        return $result;
    }
}