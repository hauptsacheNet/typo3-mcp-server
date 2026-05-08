<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'MCP Server test fixture: container',
    'description' => 'Registers a 50/50 split container for tests via b13/container.',
    'category' => 'example',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'container' => '3.2.0-0.0.0',
        ],
    ],
];
