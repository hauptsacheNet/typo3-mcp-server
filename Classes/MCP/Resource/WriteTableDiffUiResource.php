<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Resource;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Serves the MCP App HTML widget that visualises a write_table change.
 *
 * The host fetches this once via `resources/read` (the URI is declared on
 * the write_table tool via `_meta.ui.resourceUri`) and renders it in a
 * sandboxed iframe. The widget itself calls back into the MCP server via
 * `get_record_diff` and (optionally) `publish_record`.
 */
class WriteTableDiffUiResource implements ResourceInterface
{
    public const URI = 'ui://mcp-server/write-table-diff';

    public function getUri(): string
    {
        return self::URI;
    }

    public function getName(): string
    {
        return 'write_table change preview';
    }

    public function getMimeType(): string
    {
        return 'text/html;profile=mcp-app';
    }

    public function getDescription(): string
    {
        return 'Inline diff and publish widget rendered after every write_table tool call.';
    }

    public function read(): string
    {
        $path = GeneralUtility::getFileAbsFileName(
            'EXT:mcp_server/Resources/Public/Mcp/write-table-diff.html'
        );
        if (!$path || !is_file($path)) {
            // Fall back to extPath lookup in case of unusual mount points.
            $path = ExtensionManagementUtility::extPath('mcp_server')
                . 'Resources/Public/Mcp/write-table-diff.html';
        }
        return (string)file_get_contents($path);
    }
}
