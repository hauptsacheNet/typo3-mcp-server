<?php

declare(strict_types=1);

defined('TYPO3') or die();

$GLOBALS['TCA']['tt_content']['columns']['assets']['config']['overrideChildTca']['columns']['crop']['config']['cropVariants'] = [
    'desktop' => [
        'title' => 'Desktop',
        'allowedAspectRatios' => [
            '16:9' => ['title' => '16:9', 'value' => 16 / 9],
            '1.91:1' => ['title' => '1.91:1', 'value' => 1.91],
            'retired' => ['title' => 'Retired', 'value' => 1.0, 'disabled' => true],
        ],
    ],
    'mobile' => [
        'title' => 'Mobile',
        'allowedAspectRatios' => [
            '2:3' => ['title' => '2:3', 'value' => 2 / 3],
            'free' => ['title' => 'Free', 'value' => 0.0],
        ],
    ],
    'legacy' => [
        'title' => 'Legacy',
        'disabled' => true,
        'allowedAspectRatios' => [
            '4:3' => ['title' => '4:3', 'value' => 4 / 3],
        ],
    ],
];
