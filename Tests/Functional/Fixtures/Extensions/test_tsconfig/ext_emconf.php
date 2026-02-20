<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Test TSconfig',
    'description' => 'Test fixture extension providing TSconfig for functional tests',
    'category' => 'misc',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];
