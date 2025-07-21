<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Abstract base class for record-related MCP tools
 */
abstract class AbstractRecordTool extends AbstractTool implements RecordToolInterface
{
    protected TableAccessService $tableAccessService;
    protected WorkspaceContextService $workspaceContextService;
    
    public function __construct()
    {
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
        $this->workspaceContextService = GeneralUtility::makeInstance(WorkspaceContextService::class);
    }
    
    /**
     * Initialize workspace context for the current operation
     */
    protected function initializeWorkspaceContext(): void
    {
        if (isset($GLOBALS['BE_USER'])) {
            $this->workspaceContextService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        }
    }
    
    /**
     * Ensure a table can be accessed for the given operation
     * 
     * @param string $table Table name
     * @param string $operation Operation type (read, write, delete)
     * @throws \InvalidArgumentException If access is denied
     */
    protected function ensureTableAccess(string $table, string $operation = 'read'): void
    {
        // Ensure workspace context is initialized before checking access
        $this->initializeWorkspaceContext();
        
        $this->tableAccessService->validateTableAccess($table, $operation);
    }
    /**
     * Create a successful result with text content
     */
    protected function createSuccessResult(string $content): CallToolResult
    {
        return new CallToolResult([new TextContent($content)]);
    }

    /**
     * Create a successful result with JSON content
     */
    protected function createJsonResult(array $data): CallToolResult
    {
        return new CallToolResult([new TextContent(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
    }

    /**
     * Create an error result
     */
    protected function createErrorResult(string $message): CallToolResult
    {
        return new CallToolResult([new TextContent($message)], true);
    }
    
    /**
     * Check if a table exists and is accessible
     */
    protected function tableExists(string $table): bool
    {
        return $this->tableAccessService->canAccessTable($table);
    }
    
    /**
     * Get extension key from table name
     */
    protected function getExtensionFromTable(string $table): string
    {
        // Core tables
        if (in_array($table, ['pages', 'tt_content', 'sys_category', 'sys_file', 'sys_file_reference'])) {
            return 'core';
        }
        
        // Extension tables usually have a prefix like tx_news_domain_model_news
        if (strpos($table, 'tx_') === 0) {
            $parts = explode('_', $table);
            if (count($parts) >= 2) {
                return $parts[1]; // Return the extension name
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Check if a table is workspace-capable
     */
    protected function isTableWorkspaceCapable(string $table): bool
    {
        $accessInfo = $this->tableAccessService->getTableAccessInfo($table);
        return $accessInfo['workspace_capable'];
    }
    
    /**
     * Get workspace capability information for a table
     */
    protected function getWorkspaceCapabilityInfo(string $table): array
    {
        $accessInfo = $this->tableAccessService->getTableAccessInfo($table);
        
        if (!$accessInfo['accessible']) {
            return [
                'workspace_capable' => false,
                'reason' => implode(', ', $accessInfo['reasons'])
            ];
        }
        
        return [
            'workspace_capable' => $accessInfo['workspace_capable'],
            'reason' => $accessInfo['workspace_capable'] 
                ? 'Table supports workspace operations' 
                : 'Table is not workspace-capable'
        ];
    }
    
    /**
     * Get a human-readable label for a table
     */
    protected function getTableLabel(string $table): string
    {
        if (!$this->tableExists($table)) {
            return $table;
        }
        
        return TableAccessService::translateLabel($this->tableAccessService->getTableTitle($table));
    }
    
    
    /**
     * Check if a table is hidden (not accessible through TableAccessService)
     */
    protected function isTableHidden(string $table): bool
    {
        // Use TableAccessService to determine if table is accessible
        // If it's not accessible, it's effectively "hidden" from MCP
        return !$this->tableAccessService->canAccessTable($table);
    }
}
