<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Slide',
        'label' => 'author',
        'sortby' => 'sorting',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // Embedded child: only ever written through its parent.
        'hideTable' => true,
        'versioningWS' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
    ],
    'columns' => [
        'bodytext' => [
            'label' => 'Quote',
            'config' => [
                'type' => 'text',
                'enableRichtext' => true,
            ],
        ],
        // The field this fixture exists for: a required field on the embedded child.
        'author' => [
            'label' => 'Author',
            'config' => [
                'type' => 'input',
                'required' => true,
            ],
        ],
        'parent_uid' => [
            'label' => 'Parent',
            'config' => [
                'type' => 'passthrough',
            ],
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'bodytext, author'],
    ],
];
