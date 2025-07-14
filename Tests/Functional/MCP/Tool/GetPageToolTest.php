<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\SiteInformationService;
use Mcp\Types\TextContent;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetPageToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import enhanced page and content fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        
        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUserWithWorkspace(1);
        
        // Create proper site configuration for real URL testing
        $this->createTestSiteConfiguration();
        
        // Initialize language service to prevent LANG errors
        if (!isset($GLOBALS['LANG']) || !$GLOBALS['LANG'] instanceof LanguageService) {
            $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
            $GLOBALS['LANG'] = $languageServiceFactory->create('default');
        }
    }

    /**
     * Create a proper site configuration for testing URL generation
     */
    protected function createTestSiteConfiguration(): void
    {
        // Create the sites directory if it doesn't exist
        $sitesDir = $this->instancePath . '/typo3conf/sites';
        if (!is_dir($sitesDir)) {
            GeneralUtility::mkdir_deep($sitesDir);
        }

        // Always use manual creation to ensure it works reliably
        $this->createSiteConfigurationManually();
        
        // Flush site configuration cache to ensure it's picked up
        $this->flushSiteConfigurationCache();
    }

    /**
     * Fallback method to create site configuration manually
     */
    protected function createSiteConfigurationManually(): void
    {
        $siteDir = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($siteDir);
        
        $siteConfiguration = [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'websiteTitle' => 'Test Site',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'hreflang' => 'en-us',
                    'direction' => 'ltr',
                    'flag' => 'us',
                    'navigationTitle' => 'English',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ];

        // Write YAML file manually using Symfony YAML component
        $yamlContent = Yaml::dump($siteConfiguration, 99, 2);
        $configFile = $siteDir . '/config.yaml';
        GeneralUtility::writeFile($configFile, $yamlContent, true);
    }

    /**
     * Flush site configuration cache to ensure changes are picked up
     */
    protected function flushSiteConfigurationCache(): void
    {
        // Try to clear the cache files manually as well
        $cacheFile = $this->instancePath . '/typo3temp/var/cache/code/core/sites-configuration.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        // Clear global caches if available
        if (class_exists('TYPO3\\CMS\\Core\\Cache\\CacheManager')) {
            try {
                $cacheManager = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class);
                if ($cacheManager->hasCache('core')) {
                    $cacheManager->getCache('core')->remove('sites-configuration');
                }
                if ($cacheManager->hasCache('runtime')) {
                    $cacheManager->getCache('runtime')->remove('sites-configuration');
                }
            } catch (\Throwable $e) {
                // Ignore cache errors during tests
            }
        }
    }

    /**
     * Test that site configuration is properly created and can be found
     */
    public function testSiteConfigurationCreated(): void
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        
        try {
            $site = $siteFinder->getSiteByPageId(1);
            $this->assertNotNull($site);
            $this->assertEquals('test-site', $site->getIdentifier());
            $this->assertEquals(1, $site->getRootPageId());
            $this->assertEquals('https://example.com/', $site->getBase()->__toString());
        } catch (\Throwable $e) {
            $this->fail('Site configuration not found or invalid: ' . $e->getMessage());
        }
    }

    /**
     * Test URL generation directly using TYPO3 site configuration
     */
    public function testDirectUrlGeneration(): void
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        
        try {
            // Test URL generation for page 6 (Contact)
            $site = $siteFinder->getSiteByPageId(6);
            $this->assertNotNull($site);
            
            $language = $site->getLanguageById(0);
            $uri = $site->getRouter()->generateUri(6, ['_language' => $language]);
            $url = (string)$uri;
            
            $this->assertStringContainsString('https://example.com', $url);
            $this->assertStringContainsString('/contact', $url);
        } catch (\Throwable $e) {
            $this->fail('Direct URL generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Test getting page information directly through the tool
     */
    public function testGetPageDirectly(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test getting page information for Home page
        $result = $tool->execute([
            'uid' => 1,
            'includeHidden' => false,
            'languageId' => 0
        ]);
        
        // Verify result structure
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $content = $result->content[0]->text;
        
        // Verify basic page information is present
        $this->assertStringContainsString('PAGE INFORMATION', $content);
        $this->assertStringContainsString('UID: 1', $content);
        $this->assertStringContainsString('Title: Home', $content);
        $this->assertStringContainsString('Parent Page (PID): 0', $content);
        $this->assertStringContainsString('Hidden: No', $content);
        
        // Verify content elements are listed
        $this->assertStringContainsString('RECORDS ON THIS PAGE', $content);
        $this->assertStringContainsString('Content Elements (tt_content)', $content);
        $this->assertStringContainsString('[100] Welcome Header', $content);
        $this->assertStringContainsString('[101] About Section', $content);
        
        // Hidden content should not be included
        $this->assertStringNotContainsString('[104] Hidden Content', $content);
    }

    /**
     * Test getting page with URL generation
     */
    public function testGetPageWithUrl(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        $result = $tool->execute([
            'uid' => 2,
            'languageId' => 0
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Verify page information
        $this->assertStringContainsString('UID: 2', $content);
        $this->assertStringContainsString('Title: About', $content);
        $this->assertStringContainsString('Navigation Title: About Us', $content);
        
        // With site configuration, should generate full URLs
        $this->assertStringContainsString('URL:', $content);
        // Should show full site URL with site config
        $this->assertStringContainsString('https://example.com/about', $content);
    }

    /**
     * Test getting page with hidden content included
     */
    public function testGetPageWithHiddenContent(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        $result = $tool->execute([
            'uid' => 1,
            'includeHidden' => true
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Now hidden content should be included
        $this->assertStringContainsString('[104] Hidden Content', $content);
        
        // Regular content should still be there
        $this->assertStringContainsString('[100] Welcome Header', $content);
        $this->assertStringContainsString('[101] About Section', $content);
    }

    /**
     * Test getting page with content elements showing proper structure
     */
    public function testGetPageWithContentElements(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test page 2 (About) which has content elements
        $result = $tool->execute([
            'uid' => 2,
            'includeHidden' => false
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Verify content elements are properly listed
        $this->assertStringContainsString('Content Elements (tt_content)', $content);
        $this->assertStringContainsString('[102] Team Introduction', $content);
        $this->assertStringContainsString('[103] Team Members', $content);
        
        // Verify content types are shown
        $this->assertStringContainsString('Type:', $content);
        $this->assertStringContainsString('textmedia', $content);
        
        // Verify column position information
        $this->assertStringContainsString('Column: Main Content', $content);
        $this->assertStringContainsString('[colPos: 0]', $content);
    }

    /**
     * Test error handling for non-existent page
     */
    public function testGetNonExistentPage(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        $result = $tool->execute([
            'uid' => 999
        ]);
        
        // Should return an error
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $errorMessage = $result->content[0]->text;
        $this->assertStringContainsString('Error retrieving page information', $errorMessage);
    }

    /**
     * Test invalid page UID (zero or negative)
     */
    public function testInvalidPageUid(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        $result = $tool->execute([
            'uid' => 0
        ]);
        
        $this->assertTrue($result->isError);
        $errorMessage = $result->content[0]->text;
        $this->assertStringContainsString('Invalid page UID', $errorMessage);
        
        // Test negative UID
        $result = $tool->execute([
            'uid' => -1
        ]);
        
        $this->assertTrue($result->isError);
        $errorMessage = $result->content[0]->text;
        $this->assertStringContainsString('Invalid page UID', $errorMessage);
    }

    /**
     * Test getting page through ToolRegistry
     */
    public function testGetPageThroughRegistry(): void
    {
        // Create tool registry with the GetPageTool
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tools = [new GetPageTool($siteInformationService)];
        $registry = new ToolRegistry($tools);
        
        // Get tool from registry
        $tool = $registry->getTool('GetPage');
        $this->assertNotNull($tool);
        $this->assertInstanceOf(GetPageTool::class, $tool);
        
        // Execute through registry
        $result = $tool->execute([
            'uid' => 1,
            'includeHidden' => false
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        $this->assertStringContainsString('UID: 1', $content);
        $this->assertStringContainsString('Title: Home', $content);
    }

    /**
     * Test tool name extraction
     */
    public function testToolName(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        $this->assertEquals('GetPage', $tool->getName());
    }

    /**
     * Test tool schema
     */
    public function testToolSchema(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        $schema = $tool->getSchema();
        
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('description', $schema);
        $this->assertArrayHasKey('parameters', $schema);
        $this->assertArrayHasKey('properties', $schema['parameters']);
        $this->assertArrayHasKey('uid', $schema['parameters']['properties']);
        $this->assertArrayHasKey('includeHidden', $schema['parameters']['properties']);
        $this->assertArrayHasKey('languageId', $schema['parameters']['properties']);
        
        // Verify oneOf constraint (either uid or url is required)
        $this->assertArrayHasKey('oneOf', $schema['parameters']);
        $this->assertIsArray($schema['parameters']['oneOf']);
        $this->assertCount(2, $schema['parameters']['oneOf']);
        
        // Check url parameter was added
        $this->assertArrayHasKey('url', $schema['parameters']['properties']);
    }

    /**
     * Test page with different content types
     */
    public function testPageWithDifferentContentTypes(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test Contact page which has a form
        $result = $tool->execute([
            'uid' => 6
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Verify page information
        $this->assertStringContainsString('Title: Contact', $content);
        
        // Verify form content element
        $this->assertStringContainsString('[105] Contact Form', $content);
        $this->assertStringContainsString('form_formframework', $content);
    }

    /**
     * Test page tree structure (parent-child relationships)
     */
    public function testPageTreeStructure(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test child page (Team - child of About)
        $result = $tool->execute([
            'uid' => 4
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Verify it shows the correct parent
        $this->assertStringContainsString('Title: Team', $content);
        $this->assertStringContainsString('Navigation Title: Our Team', $content);
        $this->assertStringContainsString('Parent Page (PID): 2', $content);
    }

    /**
     * Test URL resolution with full URL
     */
    public function testUrlResolutionWithFullUrl(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test with full URL for About page
        $result = $tool->execute([
            'url' => 'https://example.com/about'
        ]);
        
        $this->assertFalse($result->isError, 'Failed to resolve full URL: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Verify we got the right page
        $this->assertStringContainsString('UID: 2', $content);
        $this->assertStringContainsString('Title: About', $content);
    }

    /**
     * Test URL resolution with path only
     */
    public function testUrlResolutionWithPath(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test with just path for Contact page
        $result = $tool->execute([
            'url' => '/contact'
        ]);
        
        $this->assertFalse($result->isError, 'Failed to resolve path: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Verify we got the right page
        $this->assertStringContainsString('UID: 6', $content);
        $this->assertStringContainsString('Title: Contact', $content);
    }

    /**
     * Test URL resolution with nested path
     */
    public function testUrlResolutionWithNestedPath(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test with nested path for Team page (under About)
        $result = $tool->execute([
            'url' => '/about/team'
        ]);
        
        $this->assertFalse($result->isError, 'Failed to resolve nested path: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Verify we got the right page
        $this->assertStringContainsString('UID: 4', $content);
        $this->assertStringContainsString('Title: Team', $content);
    }

    /**
     * Test URL resolution without leading slash
     */
    public function testUrlResolutionWithoutLeadingSlash(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test without leading slash
        $result = $tool->execute([
            'url' => 'about'
        ]);
        
        $this->assertFalse($result->isError, 'Failed to resolve path without leading slash: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Verify we got the right page
        $this->assertStringContainsString('UID: 2', $content);
        $this->assertStringContainsString('Title: About', $content);
    }

    /**
     * Test URL resolution for home page
     */
    public function testUrlResolutionForHomePage(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test with just domain (home page)
        $result = $tool->execute([
            'url' => 'https://example.com/'
        ]);
        
        $this->assertFalse($result->isError, 'Failed to resolve home page URL: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Verify we got the home page
        $this->assertStringContainsString('UID: 1', $content);
        $this->assertStringContainsString('Title: Home', $content);
        
        // Also test with just /
        $result = $tool->execute([
            'url' => '/'
        ]);
        
        $this->assertFalse($result->isError, 'Failed to resolve home page path: ' . json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        $this->assertStringContainsString('UID: 1', $content);
    }

    /**
     * Test URL resolution with wrong domain
     */
    public function testUrlResolutionWithWrongDomain(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test with wrong domain - should fail because domain doesn't match site config
        $result = $tool->execute([
            'url' => 'https://wrong-domain.com/about'
        ]);
        
        $this->assertTrue($result->isError, 'Expected error when using wrong domain, but got: ' . json_encode($result->jsonSerialize()));
        $errorMessage = $result->content[0]->text;
        $this->assertStringContainsString('Could not resolve URL', $errorMessage);
        $this->assertStringContainsString('domain does not match', $errorMessage);
    }

    /**
     * Test URL resolution with invalid path
     */
    public function testUrlResolutionWithInvalidPath(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test with non-existent path
        $result = $tool->execute([
            'url' => '/non-existent-page'
        ]);
        
        $this->assertTrue($result->isError);
        $errorMessage = $result->content[0]->text;
        $this->assertStringContainsString('Could not resolve URL', $errorMessage);
    }

    /**
     * Test URL resolution with language parameter
     */
    public function testUrlResolutionWithLanguage(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test URL resolution with language ID
        $result = $tool->execute([
            'url' => '/about',
            'languageId' => 0  // Default language
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        $this->assertStringContainsString('UID: 2', $content);
    }

    /**
     * Test URL generation for different pages with real site configuration
     */
    public function testRealUrlGenerationForDifferentPages(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $tool = new GetPageTool($siteInformationService);
        
        // Test Home page (root) - should have base URL
        $result = $tool->execute(['uid' => 1]);
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        $this->assertStringContainsString('URL:', $content);
        $this->assertStringContainsString('https://example.com/', $content);
        
        // Test Contact page
        $result = $tool->execute(['uid' => 6]);
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        $this->assertStringContainsString('URL:', $content);
        $this->assertStringContainsString('https://example.com/contact', $content);
        
        // Test nested page (Team under About)
        $result = $tool->execute(['uid' => 4]);
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        $this->assertStringContainsString('URL:', $content);
        $this->assertStringContainsString('https://example.com/about/team', $content);
    }
    
    /**
     * Helper method to set up backend user with workspace
     */
    protected function setUpBackendUserWithWorkspace(int $uid): void
    {
        $backendUser = $this->setUpBackendUser($uid);
        $backendUser->workspace = 1; // Set to test workspace
        $GLOBALS['BE_USER'] = $backendUser;
    }
}