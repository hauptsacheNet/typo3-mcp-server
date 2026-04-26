<?php

defined('TYPO3') or die();

// Minimal TCA stub that mirrors the shape of the real GridElementsTeam/gridelements
// extension for the two columns the MCP server cares about. Only the details that
// affect WriteTableTool validation are modelled:
//   - tx_gridelements_container is a "select" with foreign_table (no static items),
//     flagged as exclude=1 just like the real extension.
//   - tx_gridelements_columns is a "select" with static items.
// Neither field is added to any type's showitem — that is intentional and matches
// the real extension, which manages the columns behind the scenes via drag&drop.

$GLOBALS['TCA']['tt_content']['columns']['tx_gridelements_container'] = [
    'exclude' => 1,
    'label' => 'Gridelements container',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'foreign_table' => 'tt_content',
        'items' => [
            ['label' => '', 'value' => 0],
        ],
        'default' => 0,
    ],
];

$GLOBALS['TCA']['tt_content']['columns']['tx_gridelements_columns'] = [
    'exclude' => 1,
    'label' => 'Gridelements column index',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'items' => [
            ['label' => 'column 0', 'value' => 0],
            ['label' => 'column 1', 'value' => 1],
            ['label' => 'column 2', 'value' => 2],
            ['label' => 'column 3', 'value' => 3],
        ],
        'default' => 0,
    ],
];
