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

$GLOBALS['TCA']['tt_content']['columns']['tx_testrequiredfields_slides'] = [
    'label' => 'Slides',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_testrequiredfields_slide',
        'foreign_field' => 'parent_uid',
        'foreign_sortby' => 'sorting',
    ],
];

// Same child table, but the relation itself fills the required field of new slides.
$GLOBALS['TCA']['tt_content']['columns']['tx_testrequiredfields_house_slides'] = [
    'label' => 'House slides',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_testrequiredfields_slide',
        'foreign_field' => 'parent_uid',
        'foreign_sortby' => 'sorting',
        'foreign_match_fields' => [
            'author' => 'House author',
        ],
    ],
];

ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', [
    'label' => 'Slider whose embedded slides have a required field',
    'value' => 'testrequired_slides',
]);

$GLOBALS['TCA']['tt_content']['types']['testrequired_slides'] = [
    'showitem' => '--palette--;;general, header, tx_testrequiredfields_slides, tx_testrequiredfields_house_slides, --div--;Language, --palette--;;language',
];

$GLOBALS['TCA']['tt_content']['columns']['tx_testrequiredfields_event_date'] = [
    'label' => 'Event date',
    'config' => [
        'type' => 'datetime',
        'nullable' => true,
    ],
];

ExtensionManagementUtility::addTcaSelectItem('tt_content', 'CType', [
    'label' => 'Element whose conditions depend on defaults, language and a date',
    'value' => 'testrequired_dependent',
]);

$GLOBALS['TCA']['tt_content']['types']['testrequired_dependent'] = [
    'showitem' => '--palette--;;general, header, subheader, header_link, tx_testrequiredfields_event_date, --div--;Language, --palette--;;language',
    'columnsOverrides' => [
        'header' => [
            'config' => [
                'required' => true,
                'default' => 'Default header',
            ],
        ],
        // Shown once the header has a value, also the default filled in for an empty one
        'subheader' => [
            'displayCond' => 'FIELD:header:REQ:true',
            'config' => [
                'required' => true,
            ],
        ],
        // Only translations have to fill it
        'header_link' => [
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'config' => [
                'required' => true,
            ],
        ],
    ],
];
