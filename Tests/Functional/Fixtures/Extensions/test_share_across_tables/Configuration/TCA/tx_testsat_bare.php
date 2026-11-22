<?php

declare(strict_types=1);

/**
 * Hidden inline child whose ownership columns (foreign_table_parent_uid, tablenames,
 * fieldname) exist in the database but are not declared in TCA - the shape some
 * theme extensions use. DataHandler::fillInFieldArray() drops any value for such a
 * column, so they can only be written directly.
 */
return [
    'ctrl' => [
        'title' => 'Test inline item without ownership TCA columns',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'sortby' => 'sorting',
        'versioningWS' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'pid' => [
            'label' => 'pid',
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
            ],
        ],
    ],
    'types' => [
        0 => [
            'showitem' => 'title',
        ],
    ],
];
