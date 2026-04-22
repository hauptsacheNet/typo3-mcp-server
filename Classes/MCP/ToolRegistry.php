<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Hn\McpServer\MCP\Tool\ToolInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry for MCP tools
 */
class ToolRegistry
{
    /**
     * @var ToolInterface[] Registered tools
     */
    protected array $tools = [];

    public function __construct(
        #[AutowireIterator('mcp_server.tool')]
        iterable $tools,
        #[AutowireIterator('mcp.tool')]
        iterable $legacyTools = [],
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
        // Deduplicated by name: interface implementers receive both tags via
        // autoconfigure, so they are already in $tools. This loop only picks
        // up services that external extensions tagged manually with the old
        // "mcp.tool" name without implementing the interface's autoconfigure.
        foreach ($legacyTools as $tool) {
            $this->tools[$tool->getName()] ??= $tool;
        }
    }

    /**
     * Get all registered tools
     * 
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * Get a specific tool by name
     */
    public function getTool(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }
}
