<?php

declare(strict_types=1);

/**
 * One field per storage variant of TCA type=datetime: Unix timestamps for
 * format datetime/date, seconds since midnight for format time, and native
 * DATETIME/DATE/TIME columns (dbType), which hold server local time.
 */
return [
    'ctrl' => [
        'title' => 'Test datetime event',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'versioningWS' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
            ],
        ],
        'event_datetime' => [
            'label' => 'Date and time',
            'config' => [
                'type' => 'datetime',
            ],
        ],
        'event_date' => [
            'label' => 'Date',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
            ],
        ],
        'event_time' => [
            'label' => 'Time',
            'config' => [
                'type' => 'datetime',
                'format' => 'time',
            ],
        ],
        'native_datetime' => [
            'label' => 'Native date and time',
            'config' => [
                'type' => 'datetime',
                'dbType' => 'datetime',
            ],
        ],
        'native_date' => [
            'label' => 'Native date',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
                'dbType' => 'date',
            ],
        ],
        'native_time' => [
            'label' => 'Native time',
            'config' => [
                'type' => 'datetime',
                'format' => 'time',
                'dbType' => 'time',
            ],
        ],
    ],
    'types' => [
        0 => [
            'showitem' => 'title, event_datetime, event_date, event_time, native_datetime, native_date, native_time',
        ],
    ],
];
