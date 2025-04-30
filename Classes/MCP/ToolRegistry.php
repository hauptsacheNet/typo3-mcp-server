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
        #[AutowireIterator('mcp.tool')]
        iterable $tools
    ) {
        foreach ($tools as $tool) {
            $this->tools[$tool->getName()] = $tool;
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
