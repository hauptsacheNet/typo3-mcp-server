<?php

return [
    'ctrl' => [
        'title' => 'Parent',
        'label' => 'title',
        'delete' => 'deleted',
        'versioningWS' => true,
        'security' => ['ignorePageTypeRestriction' => true],
    ],
    'columns' => [
        'title' => ['config' => ['type' => 'input']],
        'children' => [
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_testrel_child',
                'foreign_field' => 'parent',
                'foreign_sortby' => 'sorting',
            ],
        ],
        // no foreign_field: the parent stores the child uids as comma-separated list
        'list_children' => [
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_testrel_listchild',
            ],
        ],
        'optout_children' => [
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_testrel_optout',
                'foreign_field' => 'parent',
                'behaviour' => [
                    'mcp_server' => ['propagateToParent' => false],
                ],
            ],
        ],
    ],
    'types' => ['0' => ['showitem' => 'title, children, list_children, optout_children']],
];
