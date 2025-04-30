<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'MCP Server',
    'description' => 'TYPO3 extension that provides a Model Context Protocol (MCP) server for interacting with TYPO3 pages and records',
    'category' => 'module',
    'author' => 'MCP Server Team',
    'author_email' => 'info@hauptsache.net',
    'state' => 'alpha',
    'clearCacheOnLoad' => true,
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'php' => '8.1.0-8.2.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
