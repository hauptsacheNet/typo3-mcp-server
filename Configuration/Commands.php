<?php

return [
    'mcp:server' => [
        'class' => \Hn\McpServer\Command\McpServerCommand::class,
        'schedulable' => false,
    ],
    'mcp:test' => [
        'class' => \Hn\McpServer\Command\McpTestCommand::class,
        'schedulable' => false,
    ],
    'mcp:generate-token' => [
        'class' => \Hn\McpServer\Command\GenerateTokenCommand::class,
        'schedulable' => false,
    ],
];
