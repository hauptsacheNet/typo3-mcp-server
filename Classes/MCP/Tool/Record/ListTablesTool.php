<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for listing available tables in TYPO3
 */
class ListTablesTool extends AbstractRecordTool
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
            'description' => 'List available tables in TYPO3, organized by extension',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'includeHidden' => [
                        'type' => 'boolean',
                        'description' => 'Whether to include hidden tables',
                        'default' => false,
                    ],
                ],
            ],
            'examples' => [
                [
                    'description' => 'List all available tables',
                    'parameters' => []
                ],
                [
                    'description' => 'List all tables including hidden ones',
                    'parameters' => [
                        'includeHidden' => true
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
        $includeHidden = $params['includeHidden'] ?? false;
        
        try {
            // Get all tables from TCA
            $tables = $this->getTables($includeHidden);
            
            // Group tables by extension
            $groupedTables = $this->groupTablesByExtension($tables);
            
            // Format the result
            return $this->createSuccessResult($this->formatTablesAsText($groupedTables));
        } catch (\Throwable $e) {
            return $this->createErrorResult('Error listing tables: ' . $e->getMessage());
        }
    }
    
    /**
     * Get all tables from TCA
     */
    protected function getTables(bool $includeHidden = false): array
    {
        $tables = [];
        
        foreach (array_keys($GLOBALS['TCA']) as $table) {
            // Skip hidden tables if not explicitly included
            if (!$includeHidden && $this->isTableHidden($table)) {
                continue;
            }
            
            $tables[$table] = [
                'name' => $table,
                'label' => $this->getTableLabel($table),
                'extension' => $this->getExtensionFromTable($table),
                'description' => $this->getTableDescription($table),
                'hidden' => $this->isTableHidden($table),
                'readOnly' => $this->isTableReadOnly($table),
                'type' => $this->getTableType($table),
            ];
        }
        
        return $tables;
    }
    
    /**
     * Group tables by extension
     */
    protected function groupTablesByExtension(array $tables): array
    {
        $grouped = [];
        
        foreach ($tables as $tableName => $tableInfo) {
            $extension = $tableInfo['extension'];
            
            if (!isset($grouped[$extension])) {
                $grouped[$extension] = [
                    'extension' => $extension,
                    'extensionLabel' => $this->getExtensionLabel($extension),
                    'tables' => [],
                ];
            }
            
            $grouped[$extension]['tables'][$tableName] = $tableInfo;
        }
        
        // Sort extensions alphabetically
        ksort($grouped);
        
        return $grouped;
    }
    
    /**
     * Format tables as text
     */
    protected function formatTablesAsText(array $groupedTables): string
    {
        $result = "AVAILABLE TABLES IN TYPO3\n";
        $result .= "========================\n\n";
        
        foreach ($groupedTables as $extension => $extensionInfo) {
            $extensionLabel = $extensionInfo['extensionLabel'];
            
            if ($extension === 'core') {
                $result .= "CORE TABLES:\n";
            } else {
                $result .= "EXTENSION: " . $extension . " (" . $extensionLabel . ")\n";
            }
            
            foreach ($extensionInfo['tables'] as $tableName => $tableInfo) {
                $result .= "- " . $tableName . " (" . $tableInfo['label'] . ")";
                
                if (!empty($tableInfo['description'])) {
                    $result .= ": " . $tableInfo['description'];
                }
                
                $result .= " [" . $tableInfo['type'] . "]";
                
                if ($tableInfo['readOnly']) {
                    $result .= " [READ-ONLY]";
                }
                
                $result .= "\n";
            }
            
            $result .= "\n";
        }
        
        return $result;
    }
    
    /**
     * Get a description for a table
     */
    protected function getTableDescription(string $table): string
    {
        // For now, we don't have a good way to get descriptions
        // This could be enhanced in the future
        return '';
    }
    
    /**
     * Get a label for an extension
     */
    protected function getExtensionLabel(string $extension): string
    {
        if ($extension === 'core') {
            return 'TYPO3 Core';
        }
        
        // Try to get extension info from ExtensionManagementUtility
        // This is a simplified approach
        return ucfirst(str_replace('_', ' ', $extension));
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
     * Check if a table has a pid field
     */
    protected function tableHasPidField(string $table): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        
        // Most tables in TYPO3 have a pid field, but some system tables don't
        // We could check the actual database schema, but for simplicity we'll use a heuristic
        
        // These tables definitely don't have a pid field
        $tablesWithoutPid = [
            'sys_registry', 'sys_log', 'sys_history', 'sys_file', 'be_sessions', 'fe_sessions'
        ];
        
        if (in_array($table, $tablesWithoutPid)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get the table label
     */
    protected function getTableLabel(string $table): string
    {
        return $GLOBALS['TCA'][$table]['ctrl']['title'] ?? $table;
    }
}
