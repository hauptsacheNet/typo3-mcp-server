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
     * Get the tool schema
     */
    public function getSchema(): array
    {
        return [
            'description' => 'List available tables in TYPO3 that can be accessed via MCP, organized by extension.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true
            ]
        ];
    }

    /**
     * Execute the tool
     */
    public function execute(array $params): CallToolResult
    {
        // Initialize workspace context
        $this->initializeWorkspaceContext();
        
        try {
            // Get all accessible tables from TableAccessService (include all, regardless of read-only status)
            $tables = $this->tableAccessService->getAccessibleTables(true);
            
            // Convert to the expected format
            $formattedTables = $this->formatAccessibleTables($tables);
            
            // Group tables by extension
            $groupedTables = $this->groupTablesByExtension($formattedTables);
            
            // Format the result
            return $this->createSuccessResult($this->formatTablesAsText($groupedTables));
        } catch (\Throwable $e) {
            return $this->createErrorResult('Error listing tables: ' . $e->getMessage());
        }
    }
    
    /**
     * Format accessible tables from TableAccessService to the expected format
     */
    protected function formatAccessibleTables(array $accessibleTables): array
    {
        $tables = [];
        
        foreach ($accessibleTables as $table => $accessInfo) {
            $tables[$table] = [
                'name' => $table,
                'label' => $this->getTableLabel($table),
                'extension' => $this->getExtensionFromTable($table),
                'description' => $this->getTableDescription($table),
                'readOnly' => $accessInfo['read_only'],
                'type' => $this->getTableType($table),
                'workspace_capable' => $accessInfo['workspace_capable'],
                'workspace_info' => $accessInfo['workspace_capable'] 
                    ? 'Workspace-capable' 
                    : 'Not workspace-capable',
                'restrictions' => $accessInfo['restrictions'],
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
        $result = "ACCESSIBLE TABLES IN TYPO3 (via MCP)\n";
        $result .= "=====================================\n\n";
        
        $result .= "All tables listed are workspace-capable and accessible by the current user.\n";
        $result .= "Tables marked as [READ-ONLY] can be read but not modified.\n\n";
        
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
                
                // Show any restrictions
                if (!empty($tableInfo['restrictions'])) {
                    $result .= " [" . implode(', ', $tableInfo['restrictions']) . "]";
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
        // For now, we don't have a good way to get descriptions from TCA
        // This could be enhanced in the future if TYPO3 provides table descriptions
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

}
