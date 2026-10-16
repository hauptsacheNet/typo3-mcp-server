<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TCA']['tt_content']['columns']['tx_testnestedfiles_items'] = [
    'label' => 'Items',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_testnestedfiles_item',
        'foreign_field' => 'tt_content_items',
        'foreign_sortby' => 'sorting',
    ],
];

$GLOBALS['TCA']['tt_content']['types']['textmedia']['showitem'] =
    ($GLOBALS['TCA']['tt_content']['types']['textmedia']['showitem'] ?? '') . ',tx_testnestedfiles_items';
