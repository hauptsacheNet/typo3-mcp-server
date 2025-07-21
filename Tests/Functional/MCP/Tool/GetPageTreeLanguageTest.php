<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\LanguageService;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetPageTreeLanguageTest extends FunctionalTestCase
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
        
        // Create multi-language site configuration
        $this->createMultiLanguageSiteConfiguration();
        
        // Import test data
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        
        // Set up backend user
        $this->setUpBackendUser(1);
    }

    /**
     * Create a site configuration with multiple languages
     */
    protected function createMultiLanguageSiteConfiguration(): void
    {
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
                1 => [
                    'title' => 'German',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de/',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'hreflang' => 'de-de',
                    'direction' => 'ltr',
                    'flag' => 'de',
                    'navigationTitle' => 'Deutsch',
                ],
                2 => [
                    'title' => 'French',
                    'enabled' => true,
                    'languageId' => 2,
                    'base' => '/fr/',
                    'locale' => 'fr_FR.UTF-8',
                    'iso-639-1' => 'fr',
                    'hreflang' => 'fr-fr',
                    'direction' => 'ltr',
                    'flag' => 'fr',
                    'navigationTitle' => 'Français',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ];

        // Write the site configuration
        $configPath = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($configPath);
        
        $yamlContent = Yaml::dump($siteConfiguration, 99, 2);
        GeneralUtility::writeFile($configPath . '/config.yaml', $yamlContent, true);
    }

    /**
     * Create test pages with translations
     */
    protected function createTestPagesWithTranslations(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        
        // Create German translation for page 2
        $connection->insert('pages', [
            'uid' => 100,
            'pid' => 1,
            'sys_language_uid' => 1,
            'l10n_parent' => 2,
            'title' => 'Über uns',
            'nav_title' => 'Über',
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/de/ueber',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 256,
        ]);
        
        // Create French translation for page 2
        $connection->insert('pages', [
            'uid' => 101,
            'pid' => 1,
            'sys_language_uid' => 2,
            'l10n_parent' => 2,
            'title' => 'À propos',
            'nav_title' => 'À propos',
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/fr/a-propos',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 256,
        ]);
        
    }

    /**
     * Test that language parameter is shown in schema for multi-language sites
     */
    public function testLanguageParameterInSchema(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        $schema = $tool->getSchema();
        
        // Should have language parameter with enum
        $this->assertArrayHasKey('language', $schema['parameters']['properties']);
        $this->assertArrayHasKey('enum', $schema['parameters']['properties']['language']);
        $this->assertContains('en', $schema['parameters']['properties']['language']['enum']);
        $this->assertContains('de', $schema['parameters']['properties']['language']['enum']);
        $this->assertContains('fr', $schema['parameters']['properties']['language']['enum']);
    }

    /**
     * Test getting page tree with default language
     */
    public function testGetPageTreeDefaultLanguage(): void
    {
        $this->createTestPagesWithTranslations();
        
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        $result = $tool->execute([
            'startPage' => 1,
            'depth' => 1
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // Should show default language titles
        $this->assertStringContainsString('[2] About', $output);
        $this->assertStringNotContainsString('[TRANSLATED]', $output);
        $this->assertStringNotContainsString('[NOT TRANSLATED]', $output);
    }

    /**
     * Test getting page tree with German language
     */
    public function testGetPageTreeGermanLanguage(): void
    {
        $this->createTestPagesWithTranslations();
        
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        $result = $tool->execute([
            'startPage' => 1,
            'depth' => 1,
            'language' => 'de'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // Should show German titles where available
        // German translation has nav_title "Über", should use that instead of title
        $this->assertStringContainsString('[2] Über [TRANSLATED]', $output);
        
        // Page 2 should be marked as translated
        $this->assertStringContainsString('Über [TRANSLATED]', $output);
        
        // Pages without translations should be marked as not translated
        $this->assertStringContainsString('[6] Contact [NOT TRANSLATED]', $output);
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

    /**
     * Test getting page tree with French language
     */
    public function testGetPageTreeFrenchLanguage(): void
    {
        $this->createTestPagesWithTranslations();
        
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        $result = $tool->execute([
            'startPage' => 1,
            'depth' => 1,
            'language' => 'fr'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // Should show French title for page 2
        $this->assertStringContainsString('[2] À propos [TRANSLATED]', $output);
        
        // Page 3 has no French translation
        $this->assertStringContainsString('[3] Hidden Page [HIDDEN] [NOT TRANSLATED]', $output);
    }

    /**
     * Test invalid language code
     */
    public function testInvalidLanguageCode(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        $result = $tool->execute([
            'startPage' => 1,
            'language' => 'xx'
        ]);
        
        $this->assertTrue($result->isError);
        $errorMessage = $result->error ?? $result->content[0]->text;
        $this->assertStringContainsString('Unknown language code: xx', $errorMessage);
    }

    /**
     * Test page tree with nav_title in translation
     */
    public function testNavTitleInTranslation(): void
    {
        $this->createTestPagesWithTranslations();
        
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        $result = $tool->execute([
            'startPage' => 1,
            'depth' => 1,
            'language' => 'de'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // German translation has nav_title "Über", should use that instead of title
        $this->assertStringContainsString('[2] Über [TRANSLATED]', $output);
        $this->assertStringNotContainsString('Über uns', $output);
    }
}