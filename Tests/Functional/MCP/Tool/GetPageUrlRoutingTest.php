<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * GetPage resolves URLs with the frontend routing (SiteMatcher + PageRouter), so
 * URLs only the router understands work: language prefixes, a site base path,
 * route enhancers and slugs changed in the workspace. None of these paths is
 * stored as slug, so the exact-slug database fallback cannot find them.
 */
class GetPageUrlRoutingTest extends AbstractFunctionalTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');

        // German translation of "About" (uid 2) with a slug of its own
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 200,
            'pid' => 1,
            'sys_language_uid' => 1,
            'l10n_parent' => 2,
            'title' => 'Über uns',
            'slug' => '/ueber-uns',
            'hidden' => 0,
            'doktype' => 1,
        ]);
    }

    public function testLanguagePrefixedUrlResolvesPageAndLanguage(): void
    {
        $this->writeSiteConfiguration();

        $text = $this->executeGetPage(['url' => 'https://example.com/de/ueber-uns']);

        $this->assertStringContainsString('UID: 2' . "\n", $text);
        $this->assertStringContainsString('Title: Über uns', $text);
        $this->assertStringContainsString('Language: DE (ID: 1)', $text);
    }

    public function testLanguagePrefixedPathWithoutHostResolvesPageAndLanguage(): void
    {
        $this->writeSiteConfiguration();

        $text = $this->executeGetPage(['url' => '/de/ueber-uns/']);

        $this->assertStringContainsString('UID: 2' . "\n", $text);
        $this->assertStringContainsString('Language: DE (ID: 1)', $text);
    }

    /**
     * An untranslated page is reachable below the language prefix with its default
     * language slug (fallbackType "fallback"), like in the frontend.
     */
    public function testLanguagePrefixedUrlOfUntranslatedPage(): void
    {
        $this->writeSiteConfiguration();

        $text = $this->executeGetPage(['url' => 'https://example.com/de/about/team']);

        $this->assertStringContainsString('UID: 4' . "\n", $text);
    }

    public function testExplicitLanguageWinsOverUrlLanguage(): void
    {
        $this->writeSiteConfiguration();

        $text = $this->executeGetPage(['url' => 'https://example.com/de/ueber-uns', 'language' => 'en']);

        $this->assertStringContainsString('UID: 2' . "\n", $text);
        $this->assertStringContainsString('Title: About' . "\n", $text);
        $this->assertStringNotContainsString('Über uns', $text);
    }

    public function testDefaultLanguageUrlStaysInDefaultLanguage(): void
    {
        $this->writeSiteConfiguration();

        $text = $this->executeGetPage(['url' => 'https://example.com/about']);

        $this->assertStringContainsString('UID: 2' . "\n", $text);
        $this->assertStringContainsString('Title: About' . "\n", $text);
        $this->assertStringNotContainsString('Language: DE', $text);
    }

    /**
     * A site served below a path: the URL path is base path + slug.
     */
    public function testSiteBasePathIsStripped(): void
    {
        $this->writeSiteConfiguration('https://example.com/site/');

        $this->assertStringContainsString('UID: 4' . "\n", $this->executeGetPage(['url' => 'https://example.com/site/about/team']));
        $this->assertStringContainsString('UID: 4' . "\n", $this->executeGetPage(['url' => '/site/about/team']));

        $text = $this->executeGetPage(['url' => 'https://example.com/site/de/ueber-uns']);
        $this->assertStringContainsString('UID: 2' . "\n", $text);
        $this->assertStringContainsString('Language: DE (ID: 1)', $text);
    }

    /**
     * A language served on a domain of its own is matched by that domain.
     */
    public function testLanguageDomainResolvesLanguage(): void
    {
        $this->writeSiteConfiguration('https://example.com/', 'https://example.de/');

        $text = $this->executeGetPage(['url' => 'https://example.de/ueber-uns']);

        $this->assertStringContainsString('UID: 2' . "\n", $text);
        $this->assertStringContainsString('Language: DE (ID: 1)', $text);

        $text = $this->executeGetPage(['url' => 'example.de']);
        $this->assertStringContainsString('UID: 1' . "\n", $text);
        $this->assertStringContainsString('Language: DE (ID: 1)', $text);
    }

    public function testLanguagePrefixedHomeUrlResolvesRootAndLanguage(): void
    {
        $this->writeSiteConfiguration();

        $text = $this->executeGetPage(['url' => 'https://example.com/de/']);

        $this->assertStringContainsString('UID: 1' . "\n", $text);
        $this->assertStringContainsString('Language: DE (ID: 1)', $text);
    }

    /**
     * A route enhancer changes the URL: "/about.html" is no slug, only the router knows it.
     */
    public function testRouteEnhancerUrlIsResolved(): void
    {
        $this->writeSiteConfiguration(routeEnhancers: [
            'PageTypeSuffix' => [
                'type' => 'PageType',
                'default' => '.html',
                'map' => ['.html' => 0],
            ],
        ]);

        $this->assertStringContainsString('UID: 4' . "\n", $this->executeGetPage(['url' => 'https://example.com/about/team.html']));

        $text = $this->executeGetPage(['url' => 'https://example.com/de/ueber-uns.html']);
        $this->assertStringContainsString('UID: 2' . "\n", $text);
        $this->assertStringContainsString('Language: DE (ID: 1)', $text);
    }

    /**
     * Hidden pages are resolved like in the backend: editors work on them before they go live.
     */
    public function testHiddenPageIsResolved(): void
    {
        $this->writeSiteConfiguration();

        $this->assertStringContainsString('UID: 3' . "\n", $this->executeGetPage(['url' => 'https://example.com/hidden']));
    }

    /**
     * A slug changed in the workspace resolves to the live uid, as the MCP client only knows
     * live ids. The slug fallback would find the workspace version row instead.
     */
    public function testSlugChangedInWorkspaceResolvesToLiveUid(): void
    {
        $this->writeSiteConfiguration();

        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 5,
            'data' => ['slug' => '/about/our-mission'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $text = $this->executeGetPage(['url' => 'https://example.com/about/our-mission']);

        $this->assertStringContainsString('UID: 5' . "\n", $text);
    }

    public function testUnknownPathIsAnError(): void
    {
        $this->writeSiteConfiguration();

        $tool = new GetPageTool(
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(LanguageService::class)
        );
        $result = $tool->execute(['url' => 'https://example.com/de/does-not-exist']);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Could not resolve URL', $result->content[0]->text);
    }

    private function executeGetPage(array $params): string
    {
        $tool = new GetPageTool(
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(LanguageService::class)
        );
        $result = $tool->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return $result->content[0]->text;
    }

    /**
     * @param string $germanBase Base of the German language, relative to the site base or absolute
     */
    private function writeSiteConfiguration(
        string $base = 'https://example.com/',
        string $germanBase = '/de/',
        array $routeEnhancers = []
    ): void {
        $configuration = [
            'rootPageId' => 1,
            'base' => $base,
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
                    'navigationTitle' => 'English',
                ],
                1 => [
                    'title' => 'German',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => $germanBase,
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'hreflang' => 'de-de',
                    'direction' => 'ltr',
                    'fallbackType' => 'fallback',
                    'fallbacks' => '0',
                    'navigationTitle' => 'Deutsch',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ];
        if ($routeEnhancers !== []) {
            $configuration['routeEnhancers'] = $routeEnhancers;
        }

        $siteDir = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($siteDir);
        GeneralUtility::writeFile($siteDir . '/config.yaml', Yaml::dump($configuration, 99, 2), true);
        // The site configuration is cached in the core cache, which survives between tests
        GeneralUtility::makeInstance(CacheManager::class)->getCache('core')->flush();
    }
}
