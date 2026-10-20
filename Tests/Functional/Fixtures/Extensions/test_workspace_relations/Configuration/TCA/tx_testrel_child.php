<?php

return [
    'ctrl' => [
        'title' => 'Child',
        'label' => 'title',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'versioningWS' => true,
        'security' => ['ignorePageTypeRestriction' => true],
    ],
    'columns' => [
        'title' => ['config' => ['type' => 'input']],
        'bodytext' => ['config' => ['type' => 'text', 'enableRichtext' => true]],
        'grandchildren' => [
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_testrel_grandchild',
                'foreign_field' => 'parent_uid',
                'foreign_table_field' => 'parent_table',
                'foreign_match_fields' => ['parent_field' => 'grandchildren'],
            ],
        ],
    ],
    'types' => ['0' => ['showitem' => 'title, bodytext, grandchildren']],
];
