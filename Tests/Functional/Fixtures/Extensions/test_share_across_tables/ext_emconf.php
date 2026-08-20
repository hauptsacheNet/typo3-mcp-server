<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'MCP Server Test Fixture: shareAcrossTables inline child',
    'description' => 'Minimal child table using foreign_table_field (Content Blocks "shareAcrossTables" pattern), for functional tests only.',
    'category' => 'example',
    'author' => 'Konrad Michalik',
    'author_email' => 'hej@konradmichalik.dev',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.4.99',
        ],
    ],
];
