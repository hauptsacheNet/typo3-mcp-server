<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

/**
 * A tool that only MCP Apps views call (`_meta.ui.visibility: ["app"]`), never the model.
 *
 * The server lists and executes such tools only for clients that declared the MCP Apps
 * extension: other hosts don't know the visibility flag and would offer the tool to the
 * model. See Documentation/Architecture/McpApps.md.
 */
interface AppOnlyToolInterface extends ToolInterface
{
}
