<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Gridelements stub (test fixture)',
    'description' => 'Adds the tx_gridelements_container / tx_gridelements_columns columns to tt_content so MCP behaviour around Gridelements container children can be tested without depending on the real GridElementsTeam/gridelements extension.',
    'category' => 'tests',
    'version' => '1.0.0',
    'state' => 'stable',
    'author' => 'Hn MCP Server Tests',
    'author_email' => 'noreply@example.com',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
    ],
];
