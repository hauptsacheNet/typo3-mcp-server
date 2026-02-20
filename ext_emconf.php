<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'MCP Server',
    'description' => 'TYPO3 extension that provides a Model Context Protocol (MCP) server for interacting with TYPO3 pages and records',
    'category' => 'module',
    'author' => 'Marco Pfeiffer',
    'author_email' => 'marco@hauptsache.net',
    'state' => 'beta',
    'clearCacheOnLoad' => true,
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
