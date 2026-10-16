<?php

declare(strict_types=1);

/**
 * Mirrors the TCA that friendsoftypo3/content-blocks generates for a "Collection"
 * field with shareAcrossTables + shareAcrossFields: a hidden child table located
 * purely through foreign_table_field ("tablenames") + foreign_match_fields
 * ("fieldname") + foreign_field ("foreign_table_parent_uid"), never through its
 * own list module entry.
 */
return [
    'ctrl' => [
        'title' => 'Test shareAcrossTables item',
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
        // Content Blocks declares these three as passthrough columns on the
        // generated child table (confirmed via the real compiled TCA). Without
        // them here, DataHandler::fillInFieldArray() silently drops any value
        // for a field that has no TCA columns entry at all — masking the very
        // bug this fixture exists to reproduce.
        'foreign_table_parent_uid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'tablenames' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'fieldname' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
    ],
    'types' => [
        0 => [
            'showitem' => 'title',
        ],
    ],
];
