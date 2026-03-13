<?php

defined('TYPO3') or die();

// Load bundled libraries autoloader for non-composer installations (TER)
// In composer mode, these classes are already autoloaded via the main autoloader
$bundledAutoloader = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::extPath('mcp_server')
    . 'Resources/Private/PHP/vendor/autoload.php';
if (!\TYPO3\CMS\Core\Core\Environment::isComposerMode() && file_exists($bundledAutoloader)) {
    require_once $bundledAutoloader;
}
