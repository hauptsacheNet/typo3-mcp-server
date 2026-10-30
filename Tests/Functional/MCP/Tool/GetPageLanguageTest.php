<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
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

        // DataHandler's localize path (WriteTable "translate") needs $GLOBALS['LANG']
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
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
            'l18n_parent' => 102,
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
        $this->assertArrayHasKey('language', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('enum', $schema['inputSchema']['properties']['language']);
        $this->assertContains('en', $schema['inputSchema']['properties']['language']['enum']);
        $this->assertContains('de', $schema['inputSchema']['properties']['language']['enum']);
        $this->assertContains('fr', $schema['inputSchema']['properties']['language']['enum']);
        
        // Should have deprecated languageId parameter
        $this->assertArrayHasKey('languageId', $schema['inputSchema']['properties']);
        $this->assertTrue($schema['inputSchema']['properties']['languageId']['deprecated'] ?? false);
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
        $this->assertStringContainsString("UID: 2\n", $output);
        $this->assertStringContainsString('Title: About', $output);
        $this->assertStringContainsString('- [102] Team Introduction (Type:', $output);
        $this->assertStringContainsString('Meet our team', $output);
        $this->assertStringNotContainsString('Language:', $output);
        $this->assertStringNotContainsString('Translated:', $output);
        // Translations are not part of the default-language view
        $this->assertStringNotContainsString('Willkommen', $output);
        $this->assertStringNotContainsString('Nur auf Deutsch', $output);
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

        // The translation record is the primary uid, the original is named alongside
        $this->assertStringContainsString("UID: 200\n", $output);
        $this->assertStringNotContainsString("UID: 2\n", $output);
        $this->assertStringContainsString('Language: DE (ID: 1)', $output);
        $this->assertStringContainsString('Translated: Yes (DE translation of page 2)', $output);

        // A translated element is listed once, under the translation's uid
        $this->assertStringContainsString('- [1000] Willkommen auf der Über uns Seite (DE, translates 102) (Type:', $output);
        $this->assertSame(1, substr_count($output, 'Willkommen auf der Über uns Seite'));
        $this->assertStringNotContainsString('[102]', $output);
        $this->assertStringNotContainsString('Team Introduction', $output);

        // Untranslated elements fall back to the default language, labeled as such
        $this->assertStringContainsString('- [103] Team Members (EN original, not translated) (Type:', $output);
        $this->assertSame(1, substr_count($output, 'Team Members'));

        // Content only existing in German (free mode) is kept
        $this->assertStringContainsString('- [1001] Nur auf Deutsch verfügbar (DE) (Type:', $output);

        $this->assertStringContainsString('Total: 3 elements', $output);
    }

    /**
     * A translation pointing to a record that is not on the page must not show up
     * as if it were content of its own
     */
    public function testGetPageLanguageSkipsOrphanedTranslations(): void
    {
        $this->createTestTranslations();
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 1002,
            'pid' => 2,
            'sys_language_uid' => 1,
            'l18n_parent' => 100,
            'CType' => 'text',
            'header' => 'Verwaiste Übersetzung',
            'colPos' => 0,
            'sorting' => 768,
        ]);

        $result = $this->createTool()->execute(['uid' => 2, 'language' => 'de']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringNotContainsString('Verwaiste Übersetzung', $result->content[0]->text);
    }

    /**
     * Hidden translations are still translations; they must not make their
     * original look untranslated (and vanish themselves)
     */
    public function testGetPageLanguageListsHiddenTranslation(): void
    {
        $this->createTestTranslations();
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 1003,
            'pid' => 2,
            'sys_language_uid' => 1,
            'l18n_parent' => 103,
            'CType' => 'text',
            'header' => 'Teammitglieder',
            'hidden' => 1,
            'colPos' => 0,
            'sorting' => 512,
        ]);

        $result = $this->createTool()->execute(['uid' => 2, 'language' => 'de']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        $this->assertStringContainsString('- [1003] Teammitglieder (DE, translates 103)', $output);
        $this->assertStringNotContainsString('[103]', $output);
    }

    /**
     * Requesting a language the page is not translated into explains how to translate it
     */
    public function testGetPageLanguageWithoutTranslation(): void
    {
        $this->createTestTranslations();

        $result = $this->createTool()->execute(['uid' => 6, 'language' => 'de']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        $this->assertStringContainsString("UID: 6\n", $output);
        $this->assertStringContainsString(
            'Translated: No (default-language record — use WriteTable action "translate" to create the DE translation)',
            $output
        );
        $this->assertStringContainsString('- [108] Office Hours (EN original, not translated)', $output);
    }

    /**
     * A page translation that only exists as a workspace draft is reported with the
     * uid ReadTable and WriteTable accept
     */
    public function testGetPageLanguageWithWorkspaceOnlyTranslation(): void
    {
        $writeTool = new WriteTableTool();
        $translateResult = $writeTool->execute([
            'action' => 'translate',
            'table' => 'pages',
            'uid' => 6,
            'data' => ['sys_language_uid' => 'de', 'title' => 'Kontakt'],
        ]);
        $this->assertFalse($translateResult->isError, json_encode($translateResult->jsonSerialize()));
        $translationUid = json_decode($translateResult->content[0]->text, true)['translationUid'];
        $this->assertIsInt($translationUid);

        $result = $this->createTool()->execute(['uid' => 6, 'language' => 'de']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        $this->assertStringContainsString('UID: ' . $translationUid . "\n", $output);
        $this->assertStringContainsString('Title: Kontakt', $output);
        $this->assertStringContainsString('Translated: Yes (DE translation of page 6)', $output);

        $readResult = (new ReadTableTool())->execute(['table' => 'pages', 'uid' => $translationUid]);
        $this->assertFalse($readResult->isError, json_encode($readResult->jsonSerialize()));
        $records = json_decode($readResult->content[0]->text, true)['records'];
        $this->assertCount(1, $records);
        $this->assertSame('Kontakt', $records[0]['title']);
    }

    /**
     * A live translation edited in the workspace has a workspace version with its
     * own uid; the output must keep showing the live uid
     */
    public function testGetPageLanguageMapsWorkspaceVersionToLiveUid(): void
    {
        $this->createTestTranslations();

        $writeTool = new WriteTableTool();
        $updateResult = $writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 200,
            'data' => ['title' => 'Über uns (Entwurf)'],
        ]);
        $this->assertFalse($updateResult->isError, json_encode($updateResult->jsonSerialize()));
        $updateResult = $writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 1000,
            'data' => ['header' => 'Willkommen (Entwurf)'],
        ]);
        $this->assertFalse($updateResult->isError, json_encode($updateResult->jsonSerialize()));

        $result = $this->createTool()->execute(['uid' => 2, 'language' => 'de']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        $this->assertStringContainsString("UID: 200\n", $output);
        $this->assertStringContainsString('Title: Über uns (Entwurf)', $output);
        $this->assertStringContainsString('Translated: Yes (DE translation of page 2)', $output);
        $this->assertStringContainsString('- [1000] Willkommen (Entwurf) (DE, translates 102)', $output);
    }

    /**
     * A content translation created in the workspace replaces its original in the list
     */
    public function testGetPageLanguageWithWorkspaceOnlyContentTranslation(): void
    {
        $this->createTestTranslations();

        $translateResult = (new WriteTableTool())->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => 103,
            'data' => ['sys_language_uid' => 'de', 'header' => 'Teammitglieder'],
        ]);
        $this->assertFalse($translateResult->isError, json_encode($translateResult->jsonSerialize()));
        $translationUid = json_decode($translateResult->content[0]->text, true)['translationUid'];
        $this->assertIsInt($translationUid);

        $result = $this->createTool()->execute(['uid' => 2, 'language' => 'de']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        $this->assertStringContainsString('- [' . $translationUid . '] Teammitglieder (DE, translates 103)', $output);
        $this->assertSame(1, substr_count($output, 'Teammitglieder'));
        $this->assertStringNotContainsString('[103]', $output);
        $this->assertStringNotContainsString('Team Members', $output);
    }

    /**
     * Ticket repro: editing the uid printed in a language view changes the translation,
     * not the default-language page
     */
    public function testUpdatingPrintedUidChangesTranslationOnly(): void
    {
        $this->createTestTranslations();

        $result = $this->createTool()->execute(['uid' => 2, 'language' => 'de']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(1, preg_match('/^UID: (\d+)$/m', $result->content[0]->text, $matches));
        $printedUid = (int)$matches[1];

        $updateResult = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $printedUid,
            'data' => ['title' => 'Über uns – neu'],
        ]);
        $this->assertFalse($updateResult->isError, json_encode($updateResult->jsonSerialize()));

        $readTool = new ReadTableTool();
        $originalResult = $readTool->execute(['table' => 'pages', 'uid' => 2]);
        $this->assertFalse($originalResult->isError, json_encode($originalResult->jsonSerialize()));
        $this->assertSame('About', json_decode($originalResult->content[0]->text, true)['records'][0]['title']);

        $translationResult = $readTool->execute(['table' => 'pages', 'uid' => 200]);
        $this->assertFalse($translationResult->isError, json_encode($translationResult->jsonSerialize()));
        $this->assertSame('Über uns – neu', json_decode($translationResult->content[0]->text, true)['records'][0]['title']);

        $result = $this->createTool()->execute(['uid' => 2, 'language' => 'de']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Title: Über uns – neu', $result->content[0]->text);
    }

    /**
     * The uid of a page translation (as shown in language views) opens the page in
     * that translation's language
     */
    public function testGetPageByTranslationUid(): void
    {
        $this->createTestTranslations();

        $result = $this->createTool()->execute(['uid' => 200]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        $this->assertStringContainsString("UID: 200\n", $output);
        $this->assertStringContainsString('Title: Über uns', $output);
        $this->assertStringContainsString('Language: DE (ID: 1)', $output);
        $this->assertStringContainsString('Translated: Yes (DE translation of page 2)', $output);
        $this->assertStringContainsString('- [1000] Willkommen auf der Über uns Seite (DE, translates 102)', $output);
    }

    protected function createTool(): GetPageTool
    {
        return new GetPageTool(
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(LanguageService::class)
        );
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
        $this->assertStringContainsString("UID: 201\n", $output);
        $this->assertStringContainsString('Language: FR (ID: 2)', $output);
        $this->assertStringContainsString('Translated: Yes (FR translation of page 2)', $output);

        // French has no content translations, should show default content
        $this->assertStringContainsString('- [102] Team Introduction (EN original, not translated)', $output);
        $this->assertStringContainsString('Meet our team', $output);
        // German content must not leak into the French view
        $this->assertStringNotContainsString('Willkommen', $output);
        $this->assertStringNotContainsString('Nur auf Deutsch', $output);
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