<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die;

/*
 * Same shape friendsoftypo3/content-blocks generates for a "Collection" field with
 * shareAcrossTables (foreign_table_field) + shareAcrossFields (foreign_match_fields).
 */
$GLOBALS['TCA']['tt_content']['columns']['tx_testsat_items'] = [
    'label' => 'Test shareAcrossTables items',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_testsat_item',
        'foreign_field' => 'foreign_table_parent_uid',
        'foreign_table_field' => 'tablenames',
        'foreign_match_fields' => [
            'fieldname' => 'tx_testsat_items',
        ],
        'foreign_sortby' => 'sorting',
    ],
];

ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    'tx_testsat_items',
    '',
    'after:bodytext',
);
