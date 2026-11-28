<?php

declare(strict_types=1);

/**
 * Embedded inline child of tx_testdatetime_event with its own datetime field,
 * which WriteTable converts outside of the parent's field validation.
 */
return [
    'ctrl' => [
        'title' => 'Test datetime slot',
        'label' => 'starts_at',
        'sortby' => 'sorting',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'versioningWS' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'starts_at' => [
            'label' => 'Starts at',
            'config' => [
                'type' => 'datetime',
            ],
        ],
        'event' => [
            'label' => 'Parent',
            'config' => [
                'type' => 'passthrough',
            ],
        ],
    ],
    'types' => [
        0 => ['showitem' => 'starts_at'],
    ],
];
