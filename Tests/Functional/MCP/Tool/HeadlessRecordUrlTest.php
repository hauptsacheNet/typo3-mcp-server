<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * With EXT:headless the site base serves JSON, visitors use the frontendBase.
 * Record URLs must point there, like the backend's view button does, and
 * GetPage must accept them back.
 *
 * mcp_server contains no headless code. On TYPO3 14, EXT:headless 5 rewrites
 * every router URL generated during a backend request (core
 * AfterPageUriGeneratedEvent), and the MCP endpoint publishes a backend request.
 * On TYPO3 13 the site package needs the listener from the documentation; the
 * fixture extension test_headless_bridge is that listener.
 */
class HeadlessRecordUrlTest extends AbstractFunctionalTest
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
        // required by EXT:headless
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'headless',
        'news',
    ];

    private const NEWS_TABLE = 'tx_news_domain_model_news';
    private const DETAIL_PID = 8;

    protected function setUp(): void
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            $this->testExtensionsToLoad[] = __DIR__ . '/../../Fixtures/Extensions/test_headless_bridge';
        }
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');
        $this->publishMcpRequest();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        // The instance is shared by all tests of this class
        GeneralUtility::rmdir($this->instancePath . '/typo3conf/sites/shop-site', true);
        parent::tearDown();
    }

    public function testFrontendUrlPointsAtFrontendBase(): void
    {
        $this->writeSiteConfiguration(1);

        $this->assertSame('https://www.example.com/about', $this->readRecord('pages', 2)['frontend_url'] ?? null);
    }

    public function testTranslatedPageKeepsLanguagePrefixOnFrontendBase(): void
    {
        $this->writeSiteConfiguration(1);

        $text = $this->executeGetPage(['uid' => 2, 'language' => 'de']);

        $this->assertStringContainsString('URL: https://www.example.com/de/about' . "\n", $text);
    }

    public function testPreviewUrlPointsAtFrontendBaseAndKeepsToken(): void
    {
        $this->writeSiteConfiguration(1);

        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 102,
            'data' => ['header' => 'Changed'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);

        $this->assertMatchesRegularExpression(
            '#^https://www\.example\.com/about\?ADMCMD_prev=[0-9a-f]{32}\#c102$#',
            $data['preview_url'] ?? ''
        );
    }

    /**
     * GetPageTree and GetPage hand out the live and the workspace preview URL of
     * a page; both point at the frontend, the preview keeps its token, and the
     * preview URL resolves back in GetPage.
     */
    public function testPageTreeAndGetPageUrlsPointAtFrontendBase(): void
    {
        $this->writeSiteConfiguration(1);
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 4,
            'data' => ['title' => 'Team changed'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $tree = (new GetPageTreeTool(
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(LanguageService::class)
        ))->execute(['startPage' => 1]);
        $this->assertFalse($tree->isError, json_encode($tree->jsonSerialize()));
        $treeText = $tree->content[0]->text;
        $this->assertMatchesRegularExpression(
            '# - https://www\.example\.com/about/team \(preview with unpublished changes: '
                . 'https://www\.example\.com/about/team\?ADMCMD_prev=[0-9a-f]{32}\)#',
            $treeText
        );
        $this->assertStringNotContainsString('api.example.com', $treeText);

        $page = $this->executeGetPage(['uid' => 4]);
        $this->assertStringContainsString('URL: https://www.example.com/about/team' . "\n", $page);
        $this->assertMatchesRegularExpression(
            '#Preview URL: (https://www\.example\.com/about/team\?ADMCMD_prev=[0-9a-f]{32})\n#',
            $page
        );
        preg_match('#Preview URL: (\S+)#', $page, $previewUrl);
        $this->assertStringContainsString('UID: 4' . "\n", $this->executeGetPage(['url' => $previewUrl[1]]));
    }

    /**
     * Records linked through TCEMAIN.preview and a route enhancer move along.
     */
    public function testNewsRecordUrlPointsAtFrontendBase(): void
    {
        $this->writeSiteConfiguration(1);
        $this->addNewsRecord();

        $this->assertSame(
            'https://www.example.com/news/article-1/live-news',
            $this->readRecord(self::NEWS_TABLE, 1)['frontend_url'] ?? null
        );
    }

    public function testSiteWithoutHeadlessModeKeepsSiteBase(): void
    {
        $this->writeSiteConfiguration(0);

        $this->assertSame('https://api.example.com/about', $this->readRecord('pages', 2)['frontend_url'] ?? null);
        $this->assertNotContains('www.example.com', GeneralUtility::makeInstance(SiteInformationService::class)->getAllDomains());
        $this->assertStringContainsString('UID: 4' . "\n", $this->executeGetPage(['url' => 'https://api.example.com/about/team']));
    }

    public function testMixedModeKeepsSiteBaseByDefault(): void
    {
        $this->writeSiteConfiguration(2);

        $this->assertSame('https://api.example.com/about', $this->readRecord('pages', 2)['frontend_url'] ?? null);
    }

    public function testMixedModeWithPreviewOverrideUsesFrontendBase(): void
    {
        $this->writeSiteConfiguration(2, 1);

        $this->assertSame('https://www.example.com/about', $this->readRecord('pages', 2)['frontend_url'] ?? null);
    }

    public function testGetPageResolvesFrontendBaseUrl(): void
    {
        $this->writeSiteConfiguration(1);

        $this->assertStringContainsString('UID: 4' . "\n", $this->executeGetPage(['url' => 'https://www.example.com/about/team']));
        $this->assertStringContainsString('UID: 1' . "\n", $this->executeGetPage(['url' => 'https://www.example.com/']));
    }

    public function testGetPageResolvesTranslatedFrontendBaseUrl(): void
    {
        $this->writeSiteConfiguration(1);
        $this->addGermanTranslationOfAbout();

        $text = $this->executeGetPage(['url' => 'https://www.example.com/de/ueber-uns']);

        $this->assertStringContainsString('Title: Über uns' . "\n", $text);
        $this->assertStringContainsString('URL: https://www.example.com/de/ueber-uns' . "\n", $text);
    }

    public function testGetPageStillResolvesSiteBaseUrl(): void
    {
        $this->writeSiteConfiguration(1);

        $this->assertStringContainsString('UID: 4' . "\n", $this->executeGetPage(['url' => 'https://api.example.com/about/team']));
    }

    /**
     * A second site without headless mode lives below the host the first site
     * publishes to. Its URLs are site URLs already and must not be mapped onto
     * the API host of the first site.
     */
    public function testGetPageKeepsUrlOfOtherSiteSharingThePublicHost(): void
    {
        $this->writeSiteConfiguration(1);
        $this->addShopSite('https://www.example.com/shop/');

        $this->assertStringContainsString('UID: 51' . "\n", $this->executeGetPage(['url' => 'https://www.example.com/shop/cart']));
        $this->assertStringContainsString('UID: 4' . "\n", $this->executeGetPage(['url' => 'https://www.example.com/about/team']));
    }

    public function testFrontendDomainIsAdvertisedForGetPage(): void
    {
        $this->writeSiteConfiguration(1);

        $domains = GeneralUtility::makeInstance(SiteInformationService::class)->getAllDomains();

        $this->assertContains('api.example.com', $domains);
        $this->assertContains('www.example.com', $domains);
    }

    /**
     * EXT:headless reads a frontendBase without scheme as domain; the assistant
     * may also pass URLs without scheme.
     */
    public function testFrontendBaseWithoutSchemeRoundTrips(): void
    {
        $this->writeSiteConfiguration(1, null, 'www.example.com');

        $this->assertSame('https://www.example.com/about/team', $this->readRecord('pages', 4)['frontend_url'] ?? null);
        $this->assertContains('www.example.com', GeneralUtility::makeInstance(SiteInformationService::class)->getAllDomains());
        $this->assertStringContainsString('UID: 4' . "\n", $this->executeGetPage(['url' => 'www.example.com/about/team']));
    }

    /**
     * A frontend served below a path: EXT:headless prefixes the page path with it,
     * GetPage has to strip it again.
     */
    public function testFrontendBaseWithPathRoundTrips(): void
    {
        $this->writeSiteConfiguration(1, null, 'https://www.example.com/app/');

        $record = $this->readRecord('pages', 4);
        $this->assertSame('https://www.example.com/app/about/team', $record['frontend_url'] ?? null);

        $this->assertStringContainsString('UID: 4' . "\n", $this->executeGetPage(['url' => $record['frontend_url']]));
        $this->assertStringContainsString('UID: 1' . "\n", $this->executeGetPage(['url' => 'https://www.example.com/app/']));
        $this->addGermanTranslationOfAbout();
        $german = $this->executeGetPage(['url' => 'https://www.example.com/app/de/ueber-uns']);
        $this->assertStringContainsString('URL: https://www.example.com/app/de/ueber-uns' . "\n", $german);
    }

    /**
     * What McpServerMiddleware publishes for an HTTP MCP call: a backend request
     * with the Accept header MCP clients send.
     */
    private function publishMcpRequest(): void
    {
        $request = new ServerRequest('https://api.example.com/mcp', 'POST', 'php://input', [
            'Accept' => 'application/json, text/event-stream',
        ], ['HTTP_HOST' => 'api.example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/index.php']);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    private function addGermanTranslationOfAbout(): void
    {
        $this->getConnectionForTable('pages')->insert('pages', [
            'uid' => 200,
            'pid' => 1,
            'title' => 'Über uns',
            'doktype' => 1,
            'slug' => '/ueber-uns',
            'sys_language_uid' => 1,
            'l10n_parent' => 2,
        ]);
    }

    private function addNewsRecord(): void
    {
        $this->getConnectionForTable('pages')->insert('pages', [
            'uid' => 20,
            'pid' => 1,
            'title' => 'News Storage',
            'doktype' => 254,
            'slug' => '/news-storage',
            'sorting' => 1024,
        ]);
        $this->getConnectionForTable('pages')->update('pages', [
            'TSconfig' => implode("\n", [
                'TCEMAIN.preview.' . self::NEWS_TABLE . ' {',
                '  previewPageId = ' . self::DETAIL_PID,
                '  fieldToParameterMap.uid = tx_news_pi1[news]',
                '  additionalGetParameters.tx_news_pi1.controller = News',
                '  additionalGetParameters.tx_news_pi1.action = detail',
                '}',
            ]),
        ], ['uid' => 1]);
        $this->getConnectionForTable(self::NEWS_TABLE)->insert(self::NEWS_TABLE, [
            'uid' => 1,
            'pid' => 20,
            'title' => 'Live News',
            'path_segment' => 'live-news',
            'datetime' => 1734875000,
        ]);
    }

    private function readRecord(string $table, int $uid): array
    {
        $result = (new ReadTableTool())->execute(['table' => $table, 'uid' => $uid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);
        $this->assertCount(1, $data['records'], $result->content[0]->text);
        return $data['records'][0];
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
     * A second site with its own page tree (root 50, subpage 51 "/cart"), not headless.
     */
    private function addShopSite(string $base): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $connection->insert('pages', ['uid' => 50, 'pid' => 0, 'title' => 'Shop', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
        $connection->insert('pages', ['uid' => 51, 'pid' => 50, 'title' => 'Cart', 'slug' => '/cart', 'doktype' => 1]);

        $siteDir = $this->instancePath . '/typo3conf/sites/shop-site';
        GeneralUtility::mkdir_deep($siteDir);
        GeneralUtility::writeFile($siteDir . '/config.yaml', Yaml::dump([
            'rootPageId' => 50,
            'base' => $base,
            'headless' => 0,
            'websiteTitle' => 'Shop',
            'languages' => [
                0 => [
                    'title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/',
                    'locale' => 'en_US.UTF-8', 'iso-639-1' => 'en', 'hreflang' => 'en-us',
                    'direction' => 'ltr', 'navigationTitle' => 'English',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ], 99, 2), true);
        GeneralUtility::makeInstance(CacheManager::class)->getCache('core')->flush();
    }

    /**
     * @param int $headlessMode 0 = off, 1 = full, 2 = mixed
     * @param int|null $previewOverrideMode Site setting headless.preview.overrideMode, unset when null
     * @param string $frontendBase Site-wide frontendBase
     */
    private function writeSiteConfiguration(
        int $headlessMode,
        ?int $previewOverrideMode = null,
        string $frontendBase = 'https://www.example.com/'
    ): void {
        $siteDir = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($siteDir);
        GeneralUtility::writeFile($siteDir . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => 'https://api.example.com/',
            'frontendBase' => $frontendBase,
            'headless' => $headlessMode,
            'websiteTitle' => 'Test Site',
            'languages' => [
                0 => [
                    'title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/',
                    'locale' => 'en_US.UTF-8', 'iso-639-1' => 'en', 'hreflang' => 'en-us',
                    'direction' => 'ltr', 'navigationTitle' => 'English',
                ],
                1 => [
                    'title' => 'German', 'enabled' => true, 'languageId' => 1, 'base' => '/de/',
                    'locale' => 'de_DE.UTF-8', 'iso-639-1' => 'de', 'hreflang' => 'de-de',
                    'direction' => 'ltr', 'fallbackType' => 'fallback', 'navigationTitle' => 'Deutsch',
                ],
            ],
            'routeEnhancers' => [
                'News' => [
                    'type' => 'Extbase',
                    'limitToPages' => [self::DETAIL_PID],
                    'extension' => 'News',
                    'plugin' => 'Pi1',
                    'routes' => [
                        [
                            'routePath' => '/{news-title}',
                            '_controller' => 'News::detail',
                            '_arguments' => ['news-title' => 'news'],
                        ],
                    ],
                    'defaultController' => 'News::list',
                    'aspects' => [
                        'news-title' => [
                            'type' => 'PersistedAliasMapper',
                            'tableName' => self::NEWS_TABLE,
                            'routeFieldName' => 'path_segment',
                        ],
                    ],
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ], 99, 2), true);

        $settingsFile = $siteDir . '/settings.yaml';
        if ($previewOverrideMode !== null) {
            GeneralUtility::writeFile($settingsFile, Yaml::dump(['headless' => ['preview' => ['overrideMode' => $previewOverrideMode]]], 99, 2), true);
        } elseif (file_exists($settingsFile)) {
            unlink($settingsFile);
        }
        // Site settings are cached in the core cache, which survives between tests
        GeneralUtility::makeInstance(CacheManager::class)->getCache('core')->flush();
    }
}
