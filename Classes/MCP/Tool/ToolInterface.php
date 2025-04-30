<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Interface for all MCP tools
 */
#[AutoconfigureTag('mcp.tool')]
interface ToolInterface
{
    /**
     * Get the tool name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the tool schema (JSON Schema format)
     *
     * @return array
     */
    public function getSchema(): array;

    /**
     * Execute the tool with the given parameters
     *
     * @param array $params
     * @return CallToolResult
     */
    public function execute(array $params): CallToolResult;
}
