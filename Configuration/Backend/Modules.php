<?php

declare(strict_types=1);

/**
 * Backend module configuration for MCP Server
 */
return [
    'user_mcp_server' => [
        'parent' => 'user',
        'position' => ['after' => 'user_setup'],
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/user/mcp-server',
        'iconIdentifier' => 'content-plugin',
        'labels' => 'LLL:EXT:mcp_server/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => \Hn\McpServer\Controller\McpServerModuleController::class . '::mainAction',
            ],
        ],
    ],
];