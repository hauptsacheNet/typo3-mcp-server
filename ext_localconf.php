<?php

defined('TYPO3') or die();

// Load bundled libraries autoloader for non-composer installations (TER)
// In composer mode, these classes are already autoloaded via the main autoloader
$bundledAutoloader = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('mcp_server')
    . 'Resources/Private/PHP/vendor/autoload.php';
if (!\TYPO3\CMS\Core\Core\Environment::isComposerMode() && file_exists($bundledAutoloader)) {
    require_once $bundledAutoloader;
}

// Tiny VariableFrontend cache used by WriteLogService to give each write a
// monotonically increasing writeId per (BE user, table, live UID). Persists
// across MCP requests so the inline diff widget can detect "superseded" state.
if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mcp_write_log'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['mcp_write_log'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend::class,
        'options' => [
            'defaultLifetime' => 60 * 60 * 24 * 30, // 30 days
        ],
        'groups' => ['system'],
    ];
}
