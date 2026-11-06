<?php

declare(strict_types=1);

defined('TYPO3') or die();

$ll = 'LLL:EXT:test_descriptions/Resources/Private/Language/locallang_tca.xlf:';

$GLOBALS['TCA']['tt_content']['columns'] += [
    // Field description as LLL reference, spanning two lines in the XLIFF
    'tx_testdescriptions_note' => [
        'label' => 'Note',
        'description' => $ll . 'note.description',
        'config' => [
            'type' => 'input',
            'size' => 30,
        ],
    ],
    // Select items with LLL, plain, missing, empty and unresolvable descriptions
    'tx_testdescriptions_mode' => [
        'label' => 'Mode',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => 'Mode A', 'value' => 'a', 'description' => $ll . 'mode.a.description'],
                ['label' => 'Mode B', 'value' => 'b', 'description' => 'Mode B shows nothing.'],
                ['label' => 'Mode C', 'value' => 'c'],
                ['label' => 'Mode D', 'value' => 'd', 'description' => ''],
                ['label' => 'Mode E', 'value' => 'e', 'description' => $ll . 'does.not.exist'],
            ],
        ],
    ],
    // Empty description must not produce a "Description:" line
    'tx_testdescriptions_empty' => [
        'label' => 'Empty description',
        'description' => '',
        'config' => [
            'type' => 'input',
        ],
    ],
    // Unresolvable LLL must not leak as raw "LLL:" string
    'tx_testdescriptions_broken' => [
        'label' => 'Broken description',
        'description' => $ll . 'does.not.exist',
        'config' => [
            'type' => 'input',
        ],
    ],
    // Virtual field of type "user" (no database column)
    'tx_testdescriptions_hint' => [
        'label' => 'Hint',
        'description' => $ll . 'hint.description',
        'config' => [
            'type' => 'user',
            'renderType' => 'testDescriptionsHint',
        ],
    ],
];

$GLOBALS['TCA']['tt_content']['palettes']['tx_testdescriptions'] = [
    'label' => 'Description palette',
    'showitem' => 'tx_testdescriptions_note, tx_testdescriptions_mode',
];

$GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'][] = [
    'label' => $ll . 'ctype.described',
    'value' => 'test_described',
    'group' => 'default',
    'description' => $ll . 'ctype.described.description',
];
$GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'][] = [
    'label' => 'Plain element',
    'value' => 'test_plain',
    'group' => 'default',
];
$GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'][] = [
    'label' => 'Broken element',
    'value' => 'test_broken',
    'group' => 'default',
    'description' => $ll . 'does.not.exist',
];

$GLOBALS['TCA']['tt_content']['types']['test_described'] = [
    'showitem' => 'CType, header, bodytext, --palette--;;tx_testdescriptions, tx_testdescriptions_hint, tx_testdescriptions_empty, tx_testdescriptions_broken',
    'columnsOverrides' => [
        'bodytext' => [
            'description' => $ll . 'bodytext.override.description',
        ],
    ],
];
$GLOBALS['TCA']['tt_content']['types']['test_plain'] = [
    'showitem' => 'CType, header, bodytext',
];
$GLOBALS['TCA']['tt_content']['types']['test_broken'] = [
    'showitem' => 'CType, header',
];
