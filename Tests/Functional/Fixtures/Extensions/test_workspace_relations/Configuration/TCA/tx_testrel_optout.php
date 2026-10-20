<?php

return [
    'ctrl' => [
        'title' => 'Opt-out child',
        'label' => 'title',
        'delete' => 'deleted',
        'versioningWS' => true,
        'security' => ['ignorePageTypeRestriction' => true],
    ],
    'columns' => [
        'title' => ['config' => ['type' => 'input']],
    ],
    'types' => ['0' => ['showitem' => 'title']],
];
