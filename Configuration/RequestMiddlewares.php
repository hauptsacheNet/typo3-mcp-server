<?php

return [
    'frontend' => [
        'hn-mcp-server/routes' => [
            'target' => \Hn\McpServer\Middleware\McpServerMiddleware::class,
            'before' => [
                'typo3/cms-frontend/site',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
    'backend' => [
        'hn-mcp-server/routes' => [
            'target' => \Hn\McpServer\Middleware\McpServerMiddleware::class,
            'before' => [
                'typo3/cms-backend/site-resolver',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
];