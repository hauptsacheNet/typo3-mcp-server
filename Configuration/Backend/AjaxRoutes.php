<?php

declare(strict_types=1);

/**
 * AJAX routes configuration for MCP Server
 */
return [
    'mcp_server_get_tokens' => [
        'path' => '/mcp-server/get-tokens',
        'target' => \Hn\McpServer\Controller\McpServerModuleController::class . '::getUserTokensAction',
    ],
    'mcp_server_revoke_token' => [
        'path' => '/mcp-server/revoke-token',
        'target' => \Hn\McpServer\Controller\McpServerModuleController::class . '::revokeTokenAction',
    ],
    'mcp_server_create_wellknown' => [
        'path' => '/mcp-server/create-wellknown',
        'target' => \Hn\McpServer\Controller\McpServerModuleController::class . '::createWellKnownFileAction',
    ],
];