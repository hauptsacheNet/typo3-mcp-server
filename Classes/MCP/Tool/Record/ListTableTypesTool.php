<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for listing available table types in TYPO3
 */
class ListTableTypesTool extends AbstractRecordTool
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
            'description' => 'List available tables and their types in TYPO3, organized by extension',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'pid' => [
                        'type' => 'integer',
                        'description' => 'Optional page ID to filter tables that are relevant for this page',
                    ],
                    'format' => [
                        'type' => 'string',
                        'description' => 'Output format (json or text)',
                        'enum' => ['json', 'text'],
                        'default' => 'json',
                    ],
                ],
            ],
            'examples' => [
                [
                    'description' => 'List all available tables',
                    'parameters' => []
                ],
                [
                    'description' => 'List tables relevant for a specific page',
                    'parameters' => [
                        'pid' => 123
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
        $pid = isset($params['pid']) ? (int)$params['pid'] : null;
        $condition = $params['condition'] ?? '';
        $includeHidden = $params['includeHidden'] ?? false;
        $format = $params['format'] ?? 'text';
        
        try {
            // Get all tables from TCA
            $tables = $this->getTables($includeHidden);
            
            // Filter by page ID if specified
            if ($pid !== null) {
                $tables = $this->filterTablesByPid($tables, $pid);
            }
            
            // Group tables by extension
            $groupedTables = $this->groupTablesByExtension($tables);
            
            // Format the result
            if ($format === 'json') {
                return $this->createJsonResult($groupedTables);
            } else {
                return $this->createSuccessResult($this->formatTablesAsText($groupedTables));
            }
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
     * Filter tables by page ID
     */
    protected function filterTablesByPid(array $tables, int $pid): array
    {
        if ($pid <= 0) {
            return $tables;
        }
        
        $filteredTables = [];
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        
        foreach ($tables as $tableName => $tableInfo) {
            // Skip tables that don't have a pid field
            if (!$this->tableHasPidField($tableName)) {
                continue;
            }
            
            // Check if there are records on this page
            $queryBuilder = $connectionPool->getQueryBuilderForTable($tableName);
            $count = $queryBuilder->count('uid')
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, \PDO::PARAM_INT))
                )
                ->executeQuery()
                ->fetchOne();
            
            if ($count > 0) {
                $filteredTables[$tableName] = $tableInfo;
                $filteredTables[$tableName]['recordCount'] = (int)$count;
            }
        }
        
        return $filteredTables;
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
                
                if (isset($tableInfo['recordCount'])) {
                    $result .= " (" . $tableInfo['recordCount'] . " records)";
                }
                
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
}
