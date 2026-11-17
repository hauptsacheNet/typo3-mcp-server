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
