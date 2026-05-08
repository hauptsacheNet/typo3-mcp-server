<?php

defined('TYPO3') || die('Access denied.');

call_user_func(static function () {
    // Register a 50/50 split container ("test_two_columns") with two grid columns:
    //   - colPos 200 ("Left")
    //   - colPos 201 ("Right")
    //
    // b13/container's Registry adds these colPos values to the static
    // tt_content.colPos items list, AND wires an itemsProcFunc that
    // narrows the items at runtime based on tx_container_parent / colPos
    // in the row.
    $registry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\B13\Container\Tca\Registry::class);
    $registry->configureContainer(
        new \B13\Container\Tca\ContainerConfiguration(
            'test_two_columns',
            'Test Two Columns',
            'Two-column 50/50 layout for tests',
            [
                [
                    ['name' => 'Left', 'colPos' => 200],
                    ['name' => 'Right', 'colPos' => 201],
                ],
            ]
        )
    );
});
