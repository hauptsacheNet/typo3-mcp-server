<?php

return [
    'frontend' => [
        'hn-mcp-server/oauth-routes' => [
            'target' => \Hn\McpServer\Middleware\McpOAuthRouteMiddleware::class,
            'before' => [
                'typo3/cms-frontend/site',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
        'hn-mcp-server/oauth-wellknown' => [
            'target' => \Hn\McpServer\Middleware\OAuthWellKnownMiddleware::class,
            'before' => [
                'typo3/cms-frontend/site',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
    'backend' => [
        'hn-mcp-server/oauth-routes' => [
            'target' => \Hn\McpServer\Middleware\McpOAuthRouteMiddleware::class,
            'before' => [
                'typo3/cms-backend/site-resolver',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
        'hn-mcp-server/oauth-wellknown' => [
            'target' => \Hn\McpServer\Middleware\OAuthWellKnownMiddleware::class,
            'before' => [
                'typo3/cms-backend/site-resolver',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
];