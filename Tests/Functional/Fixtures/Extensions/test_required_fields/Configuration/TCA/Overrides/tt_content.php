<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', [
    'label' => 'Text and media with required fields',
    'value' => 'testrequired_textmedia',
]);

$GLOBALS['TCA']['tt_content']['types']['testrequired_textmedia'] = [
    'showitem' => '--palette--;;general, header, bodytext, assets, --div--;Language, --palette--;;language',
    'columnsOverrides' => [
        'header' => [
            'config' => [
                'required' => true,
            ],
        ],
        'bodytext' => [
            'config' => [
                'required' => true,
                'enableRichtext' => true,
            ],
        ],
        'assets' => [
            'config' => [
                'minitems' => 1,
                'maxitems' => 3,
            ],
        ],
    ],
];

ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', [
    'label' => 'Element with at least two categories',
    'value' => 'testrequired_categories',
]);

$GLOBALS['TCA']['tt_content']['types']['testrequired_categories'] = [
    'showitem' => '--palette--;;general, header, categories, --div--;Language, --palette--;;language',
    'columnsOverrides' => [
        'categories' => [
            'config' => [
                'minitems' => 2,
            ],
        ],
    ],
];

ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', [
    'label' => 'Element with a conditional and a numeric required field',
    'value' => 'testrequired_conditional',
]);

$GLOBALS['TCA']['tt_content']['types']['testrequired_conditional'] = [
    'showitem' => '--palette--;;general, header, subheader, imagewidth, --div--;Language, --palette--;;language',
    'columnsOverrides' => [
        'subheader' => [
            'displayCond' => 'FIELD:header:REQ:true',
            'config' => [
                'required' => true,
            ],
        ],
        'imagewidth' => [
            'config' => [
                'required' => true,
            ],
        ],
    ],
];
