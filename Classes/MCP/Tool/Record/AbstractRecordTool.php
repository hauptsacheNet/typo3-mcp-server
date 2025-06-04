<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * Abstract base class for record-related MCP tools
 */
abstract class AbstractRecordTool extends AbstractTool implements RecordToolInterface
{
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
     * Check if a table exists in the TCA
     */
    protected function tableExists(string $table): bool
    {
        return isset($GLOBALS['TCA'][$table]);
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
     * Get a human-readable label for a table
     */
    protected function getTableLabel(string $table): string
    {
        if (!$this->tableExists($table)) {
            return $table;
        }
        
        if (!empty($GLOBALS['TCA'][$table]['ctrl']['title'])) {
            return $this->translateLabel($GLOBALS['TCA'][$table]['ctrl']['title']);
        }
        
        return $table;
    }
    
    /**
     * Translate a label if it's in LLL format
     */
    protected function translateLabel(string $label): string
    {
        // Check if the label is a language reference (LLL:)
        if (strpos($label, 'LLL:') === 0) {
            // Initialize language service if needed
            if (!isset($GLOBALS['LANG'])) {
                $languageServiceFactory = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class);
                $GLOBALS['LANG'] = $languageServiceFactory->create('default');
            }
            
            // Translate the label
            return $GLOBALS['LANG']->sL($label);
        }
        
        return $label;
    }
    
    /**
     * Check if a table is hidden in the TCA
     */
    protected function isTableHidden(string $table): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        
        return !empty($GLOBALS['TCA'][$table]['ctrl']['hideTable']);
    }
}
