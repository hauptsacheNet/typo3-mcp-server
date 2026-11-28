<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\Service\LanguageService;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Identifiers of languages that share a two-letter code follow the hreflang
 * the site publishes, also without a region, and the code comes from the
 * locale rather than from iso-639-1. One site, modelled on a production
 * installation with colliding codes:
 *
 * | ID | language                | iso-639-1 | locale | hreflang | identifier |
 * |----|-------------------------|-----------|--------|----------|------------|
 * | 0  | German                  | de        | de_DE  | de       | de         |
 * | 1  | Swiss German            | de        | de_CH  | de-CH    | de-CH      |
 * | 2  | English (UK)            | en        | en_GB  | en-GB    | en-GB      |
 * | 3  | English (US)            | us (!)    | en_US  | en-US    | en-US      |
 * | 4  | English (international) | en        | en_GB  | en       | en         |
 * | 5  | French                  | fr        | fr_FR  | fr       | fr-FR      |
 * | 6  | Swiss French            | fr        | fr_CH  | fr       | fr-CH      |
 */
class SiteLanguageHreflangIdentifierTest extends FunctionalTestCase
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
        // Before any service instantiates the SiteFinder (TYPO3 12 caches the
        // site list on construction)
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
    }

    public function testHreflangWithoutRegionBecomesTheIdentifier(): void
    {
        $languageService = GeneralUtility::makeInstance(LanguageService::class);

        $this->assertSame(
            ['de', 'de-CH', 'en-GB', 'en-US', 'en', 'fr-FR', 'fr-CH'],
            array_column($languageService->getLanguagesForPage(1), 'isoCode')
        );
    }

    public function testBareCodeResolvesToTheLanguageThatPublishesIt(): void
    {
        $languageService = GeneralUtility::makeInstance(LanguageService::class);

        $this->assertSame(0, $languageService->getUidFromIsoCode('de', 1));
        $this->assertSame(4, $languageService->getUidFromIsoCode('en', 1));
        $this->assertSame(4, $languageService->resolveLanguageUid('EN', 1), 'Identifiers are case-insensitive');
    }

    /**
     * Two languages publish the same hreflang ("fr"), so it identifies neither
     * of them: they get the tags of their locales, and "fr" stays ambiguous.
     */
    public function testSharedHreflangFallsBackToTheLocaleAndStaysAmbiguous(): void
    {
        $languageService = GeneralUtility::makeInstance(LanguageService::class);

        $this->assertSame(5, $languageService->getUidFromIsoCode('fr-FR', 1));
        $this->assertSame(6, $languageService->getUidFromIsoCode('fr-CH', 1));
        $this->assertNull($languageService->getUidFromIsoCode('fr', 1));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fr-CH (6, Suisse)');
        $languageService->resolveLanguageUid('fr', 1);
    }

    /**
     * An iso-639-1 that disagrees with the locale (a country code by mistake)
     * neither becomes an identifier nor takes the language out of its group.
     */
    public function testLocaleDecidesOverAMismatchingIsoCode(): void
    {
        $languageService = GeneralUtility::makeInstance(LanguageService::class);

        $this->assertSame(3, $languageService->getUidFromIsoCode('en-US', 1));
        $this->assertNull($languageService->getUidFromIsoCode('us', 1));
    }

    private function writeSiteConfiguration(): void
    {
        $language = static fn(int $id, string $title, string $iso, string $locale, string $hreflang, string $base): array => [
            'title' => $title,
            'enabled' => true,
            'languageId' => $id,
            'base' => $base,
            'locale' => $locale,
            'iso-639-1' => $iso,
            'hreflang' => $hreflang,
            'direction' => 'ltr',
            'flag' => $iso,
            'navigationTitle' => $title,
        ];

        $path = $this->instancePath . '/typo3conf/sites/international';
        GeneralUtility::mkdir_deep($path);
        GeneralUtility::writeFile($path . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                $language(0, 'Deutsch', 'de', 'de_DE.UTF-8', 'de', '/'),
                $language(1, 'Schweiz', 'de', 'de_CH.UTF-8', 'de-CH', '/de-ch/'),
                $language(2, 'English (UK)', 'en', 'en_GB.UTF-8', 'en-GB', '/en-gb/'),
                $language(3, 'English (US)', 'us', 'en_US.UTF-8', 'en-US', '/en-us/'),
                $language(4, 'English', 'en', 'en_GB.UTF-8', 'en', '/en/'),
                $language(5, 'Français', 'fr', 'fr_FR.UTF-8', 'fr', '/fr/'),
                $language(6, 'Suisse', 'fr', 'fr_CH.UTF-8', 'fr', '/fr-ch/'),
            ],
            'routes' => [],
            'errorHandling' => [],
        ], 99, 2), true);
    }
}
