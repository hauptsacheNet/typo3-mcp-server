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

ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', [
    'label' => 'Element with fields required only for some header layouts',
    'value' => 'testrequired_stage',
]);

// Mirrors a "stage" element whose fields depend on a selected variant: the
// fields are required, but the backend form only shows them for that variant.
$GLOBALS['TCA']['tt_content']['types']['testrequired_stage'] = [
    'showitem' => '--palette--;;general, header, header_layout, header_position, subheader, header_link, bodytext, --div--;Language, --palette--;;language',
    'columnsOverrides' => [
        'subheader' => [
            'displayCond' => 'FIELD:header_layout:=:2',
            'config' => [
                'required' => true,
            ],
        ],
        'header_link' => [
            'displayCond' => [
                'AND' => [
                    'FIELD:header_layout:IN:3,4',
                    'OR' => [
                        'FIELD:header_position:=:center',
                        'FIELD:header_position:=:left',
                    ],
                ],
            ],
            'config' => [
                'required' => true,
            ],
        ],
        'bodytext' => [
            // Cannot be evaluated on the record alone
            'displayCond' => 'VERSION:IS:false',
            'config' => [
                'required' => true,
            ],
        ],
    ],
];
