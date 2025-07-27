<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional;

use Hn\McpServer\Service\LanguageService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Abstract base test class for functional tests
 * 
 * Provides common setup and utility methods to reduce code duplication
 * across test classes.
 */
abstract class AbstractFunctionalTest extends FunctionalTestCase
{
    protected Context $context;
    protected ConnectionPool $connectionPool;
    protected LanguageService $languageService;
    
    /**
     * Core extensions that most tests need
     */
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    /**
     * Test extensions that most tests need
     */
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->initializeServices();
        $this->setupDefaultLanguage();
        $this->loadStandardFixtures();
        $this->setupDefaultBackendUser();
    }
    
    /**
     * Initialize commonly used services
     */
    protected function initializeServices(): void
    {
        $this->context = GeneralUtility::makeInstance(Context::class);
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
    }
    
    /**
     * Set up default backend user
     * 
     * @param int $uid Backend user UID
     * @return BackendUserAuthentication
     */
    protected function setupDefaultBackendUser(int $uid = 1): BackendUserAuthentication
    {
        $backendUser = $this->setUpBackendUser($uid);
        $GLOBALS['BE_USER'] = $backendUser;
        return $backendUser;
    }
    
    /**
     * Set up default language service
     * 
     * @param string $languageKey Language key (default: 'default')
     */
    protected function setupDefaultLanguage(string $languageKey = 'default'): void
    {
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create($languageKey);
    }
    
    /**
     * Load standard test fixtures
     * 
     * Override this method in child classes to customize fixture loading
     */
    protected function loadStandardFixtures(): void
    {
        // Load common fixtures used by most tests
        $fixturesPath = __DIR__ . '/Fixtures/';
        
        if (file_exists($fixturesPath . 'be_users.csv')) {
            $this->importCSVDataSet($fixturesPath . 'be_users.csv');
        }
        
        if (file_exists($fixturesPath . 'pages.csv')) {
            $this->importCSVDataSet($fixturesPath . 'pages.csv');
        }
        
        if (file_exists($fixturesPath . 'tt_content.csv')) {
            $this->importCSVDataSet($fixturesPath . 'tt_content.csv');
        }
    }
    
    /**
     * Get the root page UID from fixtures
     * 
     * @return int
     */
    protected function getRootPageUid(): int
    {
        return 1; // Standard fixture root page
    }
    
    /**
     * Create a workspace and switch to it
     * 
     * @param string $title Workspace title
     * @return int Workspace ID
     */
    protected function createAndSwitchToWorkspace(string $title = 'Test Workspace'): int
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_workspace');
        $connection->insert('sys_workspace', [
            'title' => $title,
            'description' => 'Workspace for testing',
            'adminusers' => '1',
            'members' => '1',
            'db_mountpoints' => '0',
            'file_mountpoints' => '',
            'publish_time' => 0,
            'unpublish_time' => 0,
            'freeze' => 0,
            'live_edit' => 0,
            'swap_modes' => 0,
            'publish_access' => 0,
            'stagechg_notification' => 0,
            'custom_stages' => 0,
            'uid' => 0,
            'pid' => 0,
        ]);
        
        $workspaceId = (int)$connection->lastInsertId();
        $this->switchToWorkspace($workspaceId);
        
        return $workspaceId;
    }
    
    /**
     * Switch to a specific workspace
     * 
     * @param int $workspaceId
     */
    protected function switchToWorkspace(int $workspaceId): void
    {
        $GLOBALS['BE_USER']->workspace = $workspaceId;
        $this->context->setAspect('workspace', new \TYPO3\CMS\Core\Context\WorkspaceAspect($workspaceId));
    }
    
    /**
     * Get a database connection for a table
     * 
     * @param string $table
     * @return \TYPO3\CMS\Core\Database\Connection
     */
    protected function getConnectionForTable(string $table): \TYPO3\CMS\Core\Database\Connection
    {
        return $this->connectionPool->getConnectionForTable($table);
    }
}