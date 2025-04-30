<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\MCP\Tool\ToolInterface;

/**
 * Interface for all record-related MCP tools
 */
interface RecordToolInterface extends ToolInterface
{
    /**
     * Get the record tool type
     * 
     * @return string One of 'read', 'write', or 'schema'
     */
    public function getToolType(): string;
}
