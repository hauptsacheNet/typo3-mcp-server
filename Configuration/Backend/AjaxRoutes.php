<?php

declare(strict_types=1);

/**
 * AJAX routes configuration for MCP Server
 */
return [
    'mcp_server_generate_token' => [
        'path' => '/mcp-server/generate-token',
        'target' => \Hn\McpServer\Controller\McpServerModuleController::class . '::generateTokenAction',
    ],
    'mcp_server_refresh_token' => [
        'path' => '/mcp-server/refresh-token',
        'target' => \Hn\McpServer\Controller\McpServerModuleController::class . '::refreshTokenAction',
    ],
];