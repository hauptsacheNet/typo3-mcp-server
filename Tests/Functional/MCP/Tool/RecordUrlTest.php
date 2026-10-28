<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Mcp\Types\CallToolResult;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * frontend_url / preview_url of pages, content elements and records with a
 * TCEMAIN.preview configuration (news), see RecordUrlService.
 */
class RecordUrlTest extends AbstractFunctionalTest
{
    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    private const NEWS_TABLE = 'tx_news_domain_model_news';
    private const STORAGE_PID = 20;
    private const DETAIL_PID = 8;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');

        $this->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::STORAGE_PID,
            'pid' => 1,
            'title' => 'News Storage',
            'doktype' => 254,
            'slug' => '/news-storage',
            'sorting' => 1024,
        ]);
        $this->getConnectionForTable('pages')->update('pages', [
            'TSconfig' => implode("\n", [
                'TCEMAIN.preview.tx_news_domain_model_news {',
                '  previewPageId = ' . self::DETAIL_PID,
                '  fieldToParameterMap.uid = tx_news_pi1[news]',
                '  additionalGetParameters.tx_news_pi1.controller = News',
                '  additionalGetParameters.tx_news_pi1.action = detail',
                '}',
            ]),
        ], ['uid' => 1]);

        $this->getConnectionForTable(self::NEWS_TABLE)->insert(self::NEWS_TABLE, [
            'uid' => 1,
            'pid' => self::STORAGE_PID,
            'title' => 'Live News',
            'path_segment' => 'live-news',
            'datetime' => 1734875000,
        ]);

        $this->writeSiteConfiguration();
    }

    public function testNewsRecordGetsItsFrontendUrlThroughTheRouteEnhancer(): void
    {
        $record = $this->readRecord(self::NEWS_TABLE, 1);

        $this->assertSame('https://example.com/news/article-1/live-news', $record['frontend_url'] ?? null);
        $this->assertArrayNotHasKey('preview_url', $record, 'An unchanged record needs no preview');
        $this->assertSame(0, $this->countPreviewTokens(), 'Reading unchanged records must not create preview tokens');
    }

    /**
     * TYPO3's PersistedAliasMapper generates the slug of the live record (it applies
     * no workspace overlay), and the frontend preview resolves that slug to the
     * record and renders its draft. The preview link therefore keeps the live slug,
     * exactly like the links TYPO3 renders inside the preview.
     */
    public function testUpdatedNewsRecordGetsPreviewUrl(): void
    {
        $result = $this->write([
            'action' => 'update',
            'table' => self::NEWS_TABLE,
            'uid' => 1,
            'data' => ['title' => 'Draft News', 'path_segment' => 'draft-news'],
        ]);

        $this->assertSame('https://example.com/news/article-1/live-news', $result['frontend_url'] ?? null);
        $this->assertMatchesRegularExpression(
            '#^https://example\.com/news/article-1/live-news\?ADMCMD_prev=[0-9a-f]{32}$#',
            $result['preview_url'] ?? ''
        );

        $record = $this->readRecord(self::NEWS_TABLE, 1);
        $this->assertSame('https://example.com/news/article-1/live-news', $record['frontend_url'] ?? null);
        $this->assertSame($result['preview_url'], $record['preview_url'] ?? null);
    }

    public function testNewNewsRecordOnlyHasPreviewUrl(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => self::NEWS_TABLE,
            'pid' => self::STORAGE_PID,
            'data' => ['title' => 'Brand New', 'path_segment' => 'brand-new'],
        ]);

        $this->assertArrayNotHasKey('frontend_url', $result, 'A record that only exists as draft has no live URL');
        $this->assertMatchesRegularExpression(
            '#^https://example\.com/news/article-1/brand-new\?ADMCMD_prev=[0-9a-f]{32}$#',
            $result['preview_url'] ?? ''
        );
    }

    public function testPreviewTokenIsReusedAndStoredForTheWorkspace(): void
    {
        $first = $this->write(['action' => 'update', 'table' => self::NEWS_TABLE, 'uid' => 1, 'data' => ['title' => 'One']]);
        $second = $this->write(['action' => 'update', 'table' => 'tt_content', 'uid' => 102, 'data' => ['header' => 'Two']]);

        $this->assertSame($this->extractToken($first['preview_url']), $this->extractToken($second['preview_url']));
        $this->assertSame(1, $this->countPreviewTokens());

        $token = $this->getConnectionForTable('sys_preview')->select(['*'], 'sys_preview')->fetchAssociative();
        $this->assertSame(['fullWorkspace' => (int)$GLOBALS['BE_USER']->workspace], json_decode($token['config'], true));
        $this->assertEqualsWithDelta(time() + 48 * 3600, (int)$token['endtime'], 60);
    }

    public function testPreviewTokenNearItsExpiryIsReplaced(): void
    {
        $first = $this->write(['action' => 'update', 'table' => self::NEWS_TABLE, 'uid' => 1, 'data' => ['title' => 'One']]);
        $this->getConnectionForTable('sys_preview')->update('sys_preview', ['endtime' => time() + 3600], []);

        $record = $this->readRecord(self::NEWS_TABLE, 1);

        $this->assertNotSame($this->extractToken($first['preview_url']), $this->extractToken($record['preview_url']));
        $this->assertSame(2, $this->countPreviewTokens());
    }

    public function testWorkspaceLifetimeIsUsedForNewTokens(): void
    {
        $this->getConnectionForTable('sys_workspace')->update('sys_workspace', ['previewlink_lifetime' => 5], []);

        $this->write(['action' => 'update', 'table' => self::NEWS_TABLE, 'uid' => 1, 'data' => ['title' => 'One']]);

        $token = $this->getConnectionForTable('sys_preview')->select(['endtime'], 'sys_preview')->fetchAssociative();
        $this->assertEqualsWithDelta(time() + 5 * 3600, (int)$token['endtime'], 60);
    }

    public function testPreviewLinksCanBeDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['workspacePreviewLinks'] = '0';

        $result = $this->write(['action' => 'update', 'table' => self::NEWS_TABLE, 'uid' => 1, 'data' => ['title' => 'One']]);

        $this->assertSame('https://example.com/news/article-1/live-news', $result['frontend_url'] ?? null);
        $this->assertArrayNotHasKey('preview_url', $result);
        $this->assertSame(0, $this->countPreviewTokens());
    }

    public function testTableWithoutPreviewConfigurationHasNoUrlFields(): void
    {
        $this->getConnectionForTable('sys_category')->insert('sys_category', ['uid' => 1, 'pid' => self::STORAGE_PID, 'title' => 'Category']);

        $record = $this->readRecord('sys_category', 1);
        $this->assertArrayNotHasKey('frontend_url', $record);

        $schema = (new GetTableSchemaTool())->execute(['table' => 'sys_category']);
        $this->assertFalse($schema->isError, json_encode($schema->jsonSerialize()));
        $this->assertStringNotContainsString('frontend_url', $schema->content[0]->text);

        $newsSchema = (new GetTableSchemaTool())->execute(['table' => self::NEWS_TABLE]);
        $this->assertFalse($newsSchema->isError, json_encode($newsSchema->jsonSerialize()));
        $this->assertStringContainsString('frontend_url', $newsSchema->content[0]->text);
        $this->assertStringContainsString('preview_url', $newsSchema->content[0]->text);
    }

    public function testFieldsFilterSkipsUrlsUnlessRequested(): void
    {
        $withoutUrls = $this->readRecord(self::NEWS_TABLE, 1, ['title']);
        $this->assertArrayNotHasKey('frontend_url', $withoutUrls);

        $withUrls = $this->readRecord(self::NEWS_TABLE, 1, ['title', 'frontend_url']);
        $this->assertSame('https://example.com/news/article-1/live-news', $withUrls['frontend_url'] ?? null);
    }

    public function testContentElementLinksToItsPageWithAnchor(): void
    {
        $record = $this->readRecord('tt_content', 102);

        $this->assertSame('https://example.com/about#c102', $record['frontend_url'] ?? null);
    }

    public function testContentElementInStorageFolderHasNoUrl(): void
    {
        $this->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 300,
            'pid' => self::STORAGE_PID,
            'header' => 'News body element',
            'CType' => 'text',
        ]);

        $record = $this->readRecord('tt_content', 300);

        $this->assertArrayNotHasKey('frontend_url', $record, 'A sysfolder has no frontend, linking the site root instead would mislead');
    }

    public function testGetPageShowsPreviewUrlOnlyWhenContentChanged(): void
    {
        $before = $this->executeText($this->createGetPageTool(), ['uid' => 2]);
        $this->assertStringContainsString('URL: https://example.com/about' . "\n", $before);
        $this->assertStringNotContainsString('Preview URL', $before);

        $this->write(['action' => 'update', 'table' => 'tt_content', 'uid' => 102, 'data' => ['header' => 'Changed']]);

        $after = $this->executeText($this->createGetPageTool(), ['uid' => 2]);
        $this->assertStringContainsString('URL: https://example.com/about' . "\n", $after);
        $this->assertMatchesRegularExpression('#Preview URL: https://example\.com/about\?ADMCMD_prev=[0-9a-f]{32}\n#', $after);
        $this->assertStringContainsString('valid until', $after);
    }

    public function testGetPageShowsLiveAndDraftSlugOfRenamedPage(): void
    {
        $this->write(['action' => 'update', 'table' => 'pages', 'uid' => 5, 'data' => ['slug' => '/about/our-mission']]);

        $text = $this->executeText($this->createGetPageTool(), ['uid' => 5]);

        $this->assertStringContainsString('URL: https://example.com/about/mission' . "\n", $text);
        $this->assertMatchesRegularExpression('#Preview URL: https://example\.com/about/our-mission\?ADMCMD_prev=[0-9a-f]{32}#', $text);
    }

    public function testGetPageOfUnpublishedPageOnlyHasPreviewUrl(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 2,
            'data' => ['title' => 'Draft Page', 'slug' => '/about/draft-page'],
        ]);

        $text = $this->executeText($this->createGetPageTool(), ['uid' => $result['uid']]);

        $this->assertStringContainsString('URL: none yet, the page is not published', $text);
        $this->assertMatchesRegularExpression('#Preview URL: https://example\.com/about/draft-page\?ADMCMD_prev=[0-9a-f]{32}#', $text);
    }

    public function testGetPageTreeAddsPreviewUrlOnlyToChangedPages(): void
    {
        $this->write(['action' => 'update', 'table' => 'tt_content', 'uid' => 102, 'data' => ['header' => 'Changed']]);

        $tree = $this->executeText(
            new GetPageTreeTool(GeneralUtility::makeInstance(SiteInformationService::class), GeneralUtility::makeInstance(LanguageService::class)),
            ['startPage' => 1, 'depth' => 2]
        );

        $lines = [];
        foreach (explode("\n", $tree) as $line) {
            if (preg_match('/- \[(\d+)\]/', $line, $matches)) {
                $lines[(int)$matches[1]] = $line;
            }
        }
        $this->assertMatchesRegularExpression('#https://example\.com/about \(preview with unpublished changes: https://example\.com/about\?ADMCMD_prev=[0-9a-f]{32}\)#', $lines[2]);
        $this->assertStringContainsString('https://example.com/about/team', $lines[4]);
        $this->assertStringNotContainsString('ADMCMD_prev', $lines[4]);
        $this->assertStringNotContainsString('https://', $lines[self::STORAGE_PID], 'A sysfolder has no URL');
    }

    public function testTranslatedNewsLinksInItsLanguage(): void
    {
        $result = $this->write([
            'action' => 'translate',
            'table' => self::NEWS_TABLE,
            'uid' => 1,
            'data' => ['sys_language_uid' => 'de', 'title' => 'Deutsche News', 'path_segment' => 'deutsche-news'],
        ]);

        // New translations start hidden, so the slug falls back to the default language record
        $this->assertMatchesRegularExpression(
            '#^https://example\.com/de/news/article-1/live-news\?ADMCMD_prev=[0-9a-f]{32}$#',
            $result['preview_url'] ?? ''
        );
    }

    /**
     * End to end: the frontend accepts the token and renders the draft without a login.
     */
    public function testPreviewUrlRendersTheDraftInTheFrontend(): void
    {
        $this->getConnectionForTable('sys_template')->insert('sys_template', [
            'pid' => 1,
            'root' => 1,
            'clear' => 3,
            'config' => "page = PAGE\npage.10 = TEXT\npage.10.field = title\n",
        ]);
        $result = $this->write(['action' => 'update', 'table' => 'pages', 'uid' => 4, 'data' => ['title' => 'Draft Team Title']]);
        $this->assertNotEmpty($result['preview_url'] ?? '', json_encode($result));

        // The preview link is meant for visitors without a backend login
        unset($GLOBALS['BE_USER']);
        $previewBody = (string)$this->executeFrontendSubRequest(new InternalRequest($result['preview_url']))->getBody();
        $liveBody = (string)$this->executeFrontendSubRequest(new InternalRequest($result['frontend_url']))->getBody();

        $this->assertStringContainsString('Draft Team Title', $previewBody);
        $this->assertStringContainsString('Team', $liveBody);
        $this->assertStringNotContainsString('Draft Team Title', $liveBody);
    }

    private function readRecord(string $table, int $uid, array $fields = []): array
    {
        $params = ['table' => $table, 'uid' => $uid];
        if ($fields !== []) {
            $params['fields'] = $fields;
        }
        $result = (new ReadTableTool())->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);
        $this->assertCount(1, $data['records'], $result->content[0]->text);
        return $data['records'][0];
    }

    private function write(array $params): array
    {
        $result = (new WriteTableTool())->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return json_decode($result->content[0]->text, true);
    }

    private function executeText(object $tool, array $params): string
    {
        /** @var CallToolResult $result */
        $result = $tool->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return $result->content[0]->text;
    }

    private function createGetPageTool(): GetPageTool
    {
        return new GetPageTool(
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(LanguageService::class)
        );
    }

    private function extractToken(string $url): string
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return (string)($query['ADMCMD_prev'] ?? '');
    }

    private function countPreviewTokens(): int
    {
        return $this->getConnectionForTable('sys_preview')->count('*', 'sys_preview', []);
    }

    private function writeSiteConfiguration(): void
    {
        $siteDir = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($siteDir);
        GeneralUtility::writeFile($siteDir . '/config.yaml', Yaml::dump([
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
                    'fallbackType' => 'fallback',
                    'navigationTitle' => 'Deutsch',
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
    }
}
