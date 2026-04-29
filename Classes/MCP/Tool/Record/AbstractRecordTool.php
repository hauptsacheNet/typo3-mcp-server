<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Abstract base class for record-related MCP tools
 */
abstract class AbstractRecordTool extends AbstractTool
{
    
    protected TableAccessService $tableAccessService;
    protected WorkspaceContextService $workspaceContextService;
    
    public function __construct()
    {
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
        $this->workspaceContextService = GeneralUtility::makeInstance(WorkspaceContextService::class);
    }
    
    /**
     * Initialize workspace context before execution
     * 
     * This method is called automatically by AbstractTool::execute()
     * before doExecute() is invoked.
     */
    protected function initialize(): void
    {
        parent::initialize();
        
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
     *
     * Workspace overlays can surface raw column values that DataHandler chose
     * not to sanitize (binary garbage smuggled into a string field, broken
     * UTF-8 sequences, etc.). Without JSON_INVALID_UTF8_SUBSTITUTE, json_encode
     * returns false on those bytes and TextContent then dies with a TypeError
     * because it expects a string. Substitute mode replaces the offending byte
     * with U+FFFD so the response is well-formed.
     */
    protected function createJsonResult(array $data): CallToolResult
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($encoded === false) {
            $encoded = '{}';
        }
        return new CallToolResult([new TextContent($encoded)]);
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

    /**
     * Get workspace hint text to prepend to tool output.
     * Returns empty string when in live workspace.
     */
    protected function getWorkspaceHint(): string
    {
        $info = $this->workspaceContextService->getWorkspaceInfo();
        if ($info['is_live']) {
            return '';
        }
        return '[WORKSPACE: "' . $info['title'] . '" — Edits are staged as drafts, not yet live.]' . "\n\n";
    }

}
