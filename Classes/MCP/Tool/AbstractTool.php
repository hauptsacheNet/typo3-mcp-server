<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

/**
 * Abstract base class for MCP tools
 */
abstract class AbstractTool implements ToolInterface
{
    /**
     * Get the tool name based on the class name
     */
    public function getName(): string
    {
        $className = (new \ReflectionClass($this))->getShortName();
        return str_replace('Tool', '', $className);
    }
}
