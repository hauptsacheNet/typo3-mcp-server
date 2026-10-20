<?php

return [
    'ctrl' => [
        'title' => 'Grandchild',
        'label' => 'title',
        'delete' => 'deleted',
        'versioningWS' => true,
        'security' => ['ignorePageTypeRestriction' => true],
    ],
    'columns' => [
        'title' => ['config' => ['type' => 'input']],
        // like uid_foreign/tablenames/fieldname of sys_file_reference: the relation can be written on the child alone
        'parent_uid' => ['config' => ['type' => 'passthrough']],
        'parent_table' => ['config' => ['type' => 'passthrough']],
        'parent_field' => ['config' => ['type' => 'passthrough']],
    ],
    'types' => ['0' => ['showitem' => 'title']],
];
