<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\LanguageService;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetPageLanguageTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        
        // Set up backend user
        $this->setUpBackendUser(1);
        
        // Initialize language service
        $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
        $GLOBALS['LANG'] = $languageServiceFactory->create('default');
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
     * Create test page translations and content
     */
    protected function createTestTranslations(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        
        // Create German translation for page 2 (About)
        $connection->insert('pages', [
            'uid' => 200,
            'pid' => 1,
            'sys_language_uid' => 1,
            'l10n_parent' => 2,
            'title' => 'Über uns',
            'subtitle' => 'Erfahren Sie mehr über unser Unternehmen',
            'hidden' => 0,
            'doktype' => 1,
        ]);
        
        // Create French translation for page 2
        $connection->insert('pages', [
            'uid' => 201,
            'pid' => 1,
            'sys_language_uid' => 2,
            'l10n_parent' => 2,
            'title' => 'À propos',
            'subtitle' => 'En savoir plus sur notre entreprise',
            'hidden' => 0,
            'doktype' => 1,
        ]);
        
        // Create German content for page 2
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $connection->insert('tt_content', [
            'uid' => 1000,
            'pid' => 2,
            'sys_language_uid' => 1,
            'l18n_parent' => 100,
            'CType' => 'text',
            'header' => 'Willkommen auf der Über uns Seite',
            'bodytext' => 'Dies ist die deutsche Version unserer Über uns Seite.',
            'colPos' => 0,
            'sorting' => 256,
        ]);
        
        // Create content that exists only in German (no parent)
        $connection->insert('tt_content', [
            'uid' => 1001,
            'pid' => 2,
            'sys_language_uid' => 1,
            'CType' => 'text',
            'header' => 'Nur auf Deutsch verfügbar',
            'bodytext' => 'Dieser Inhalt existiert nur in der deutschen Version.',
            'colPos' => 0,
            'sorting' => 512,
        ]);
    }

    /**
     * Test that language parameter is shown in schema for multi-language sites
     */
    public function testLanguageParameterInSchema(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTool($siteInformationService, $languageService);
        
        $schema = $tool->getSchema();
        
        // Should have language parameter with enum
        $this->assertArrayHasKey('language', $schema['parameters']['properties']);
        $this->assertArrayHasKey('enum', $schema['parameters']['properties']['language']);
        $this->assertContains('en', $schema['parameters']['properties']['language']['enum']);
        $this->assertContains('de', $schema['parameters']['properties']['language']['enum']);
        $this->assertContains('fr', $schema['parameters']['properties']['language']['enum']);
        
        // Should have deprecated languageId parameter
        $this->assertArrayHasKey('languageId', $schema['parameters']['properties']);
        $this->assertTrue($schema['parameters']['properties']['languageId']['deprecated'] ?? false);
    }

    /**
     * Test getting page with default language
     */
    public function testGetPageDefaultLanguage(): void
    {
        $this->createTestTranslations();
        
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTool($siteInformationService, $languageService);
        
        $result = $tool->execute([
            'uid' => 2
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // Should show default English content
        $this->assertStringContainsString('Title: About', $output);
        $this->assertStringContainsString('Team Introduction', $output);
        $this->assertStringContainsString('Meet our team', $output);
        $this->assertStringNotContainsString('Language:', $output);
        $this->assertStringNotContainsString('Translated:', $output);
    }

    /**
     * Test getting page with German language
     */
    public function testGetPageGermanLanguage(): void
    {
        $this->createTestTranslations();
        
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTool($siteInformationService, $languageService);
        
        $result = $tool->execute([
            'uid' => 2,
            'language' => 'de'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // Should show German page title and subtitle
        $this->assertStringContainsString('Title: Über uns', $output);
        $this->assertStringContainsString('Subtitle: Erfahren Sie mehr über unser Unternehmen', $output);
        
        // Should show language information
        $this->assertStringContainsString('Language: DE (ID: 1)', $output);
        $this->assertStringContainsString('Translated: Yes', $output);
        
        // Should show German content
        $this->assertStringContainsString('Willkommen auf der Über uns Seite', $output);
        $this->assertStringContainsString('Nur auf Deutsch verfügbar', $output);
        
        // Should show both German content and fallback default content
        // This is intended behavior - untranslated content falls back to default language
    }

    /**
     * Test getting page with French language
     */
    public function testGetPageFrenchLanguage(): void
    {
        $this->createTestTranslations();
        
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTool($siteInformationService, $languageService);
        
        $result = $tool->execute([
            'uid' => 2,
            'language' => 'fr'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // Should show French page title and subtitle
        $this->assertStringContainsString('Title: À propos', $output);
        $this->assertStringContainsString('Subtitle: En savoir plus sur notre entreprise', $output);
        
        // Should show language information
        $this->assertStringContainsString('Language: FR (ID: 2)', $output);
        $this->assertStringContainsString('Translated: Yes', $output);
        
        // French has no content translations, should show default content
        $this->assertStringContainsString('Team Introduction', $output);
        $this->assertStringContainsString('Meet our team', $output);
    }

    /**
     * Test showing available translations
     */
    public function testShowAvailableTranslations(): void
    {
        $this->createTestTranslations();
        
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTool($siteInformationService, $languageService);
        
        $result = $tool->execute([
            'uid' => 2,
            'language' => 'en'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // Should show available translations
        $this->assertStringContainsString('Available Translations: DE, FR', $output);
    }

    /**
     * Test invalid language code
     */
    public function testInvalidLanguageCode(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTool($siteInformationService, $languageService);
        
        $result = $tool->execute([
            'uid' => 2,
            'language' => 'xx'
        ]);
        
        $this->assertTrue($result->isError);
        $errorMessage = $result->error ?? $result->content[0]->text;
        $this->assertStringContainsString('Unknown language code: xx', $errorMessage);
    }

    /**
     * Test backward compatibility with languageId parameter
     */
    public function testBackwardCompatibilityLanguageId(): void
    {
        $this->createTestTranslations();
        
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTool($siteInformationService, $languageService);
        
        $result = $tool->execute([
            'uid' => 2,
            'languageId' => 1  // Using deprecated parameter
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // Should work with numeric languageId
        $this->assertStringContainsString('Title: Über uns', $output);
        $this->assertStringContainsString('Language: DE (ID: 1)', $output);
    }

    /**
     * Test URL resolution with language
     */
    public function testUrlResolutionWithLanguage(): void
    {
        $this->createTestTranslations();
        
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTool($siteInformationService, $languageService);
        
        $result = $tool->execute([
            'url' => '/about',
            'language' => 'de'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // Should resolve URL and show German version
        $this->assertStringContainsString('Title: Über uns', $output);
        $this->assertStringContainsString('URL: https://example.com/', $output);
    }
}