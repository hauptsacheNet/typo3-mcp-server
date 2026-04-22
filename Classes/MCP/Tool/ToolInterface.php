<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Interface for all MCP tools
 */
#[AutoconfigureTag('mcp_server.tool')]
// @deprecated since 0.3, use the "mcp_server.tool" tag instead. Kept so
// external extensions that manually tagged their services under the old
// name continue to be picked up by the ToolRegistry.
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
