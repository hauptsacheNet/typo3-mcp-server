<?php

defined('TYPO3') or die();

// Load bundled libraries autoloader for non-composer installations (TER)
// In composer mode, these classes are already autoloaded via the main autoloader
$bundledAutoloader = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('mcp_server')
    . 'Resources/Private/PHP/vendor/autoload.php';
if (!\TYPO3\CMS\Core\Core\Environment::isComposerMode() && file_exists($bundledAutoloader)) {
    require_once $bundledAutoloader;
}

// Version the parent records of inline children changed in a workspace, so the Workspaces module shows
// (and publishes) the whole family instead of context-less children.
// See Documentation/Architecture/WorkspaceParentVersioning.md
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] =
    \Hn\McpServer\Hook\PropagateChangesToParentDataHandlerHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] =
    \Hn\McpServer\Hook\PropagateChangesToParentDataHandlerHook::class;
