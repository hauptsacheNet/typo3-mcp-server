<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPreviewUrlTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\WorkspaceContextService;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetPreviewUrlToolTest extends FunctionalTestCase
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

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
        $this->writeSiteConfiguration([0 => ['title' => 'English', 'base' => '/', 'iso' => 'en']]);
    }

    public function testReturnsPreviewUrlWithTokenForPageWithWorkspaceChanges(): void
    {
        $this->stageTitleChangeOnPage2();

        $result = $this->createTool()->execute(['uid' => 2]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertMatchesRegularExpression('#https://example\.com/\S*ADMCMD_prev=[0-9a-f]{32}#', $text);
    }

    public function testStatesDefaultLifetimeAndStoresTokenInWorkspace(): void
    {
        $this->stageTitleChangeOnPage2();

        $result = $this->createTool()->execute(['uid' => 2]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('48 hours', $result->content[0]->text);

        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_preview')
            ->select(['config'], 'sys_preview')
            ->fetchAssociative();
        $this->assertSame($GLOBALS['BE_USER']->workspace, json_decode($row['config'], true)['fullWorkspace']);
    }

    public function testStatesWorkspaceSpecificLifetime(): void
    {
        $this->stageTitleChangeOnPage2();
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_workspace')
            ->update('sys_workspace', ['previewlink_lifetime' => 5], ['uid' => $GLOBALS['BE_USER']->workspace]);

        $result = $this->createTool()->execute(['uid' => 2]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('5 hours', $result->content[0]->text);
    }

    public function testDoesNotExposeWorkspaceId(): void
    {
        $this->stageTitleChangeOnPage2();

        $result = $this->createTool()->execute(['uid' => 2]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringNotContainsString('Test Workspace', $result->content[0]->text);
        $this->assertStringNotContainsString('workspace', strtolower(parse_url($this->extractUrl($result->content[0]->text), PHP_URL_QUERY) ?? ''));
    }

    public function testMentionsCookieRequirementAndPreviewNotice(): void
    {
        $this->stageTitleChangeOnPage2();

        $description = $this->createTool()->getSchema()['description'];

        $this->assertStringContainsString('cookie', $description);
        $this->assertStringContainsString('disablePreviewNotification', $description);
    }

    public function testLiveWorkspaceReturnsPlainUrlWithoutMintingToken(): void
    {
        $tool = new class (
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(LanguageService::class),
            GeneralUtility::makeInstance(PreviewUriBuilder::class),
        ) extends GetPreviewUrlTool {
            protected function initialize(): void
            {
                $GLOBALS['BE_USER']->workspace = 0;
            }
        };

        $result = $tool->execute(['uid' => 2]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringContainsString('https://example.com/', $text);
        $this->assertStringNotContainsString('ADMCMD_prev', $text);
        $count = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_preview')
            ->count('*', 'sys_preview', []);
        $this->assertSame(0, $count);
    }

    public function testUnknownPageReturnsError(): void
    {
        $result = $this->createTool()->execute(['uid' => 9999]);

        $this->assertTrue($result->isError);
    }

    public function testSchemaHasNoLanguageParameterForSingleLanguageSite(): void
    {
        $schema = $this->createTool()->getSchema();

        $this->assertArrayHasKey('uid', $schema['inputSchema']['properties']);
        $this->assertArrayNotHasKey('language', $schema['inputSchema']['properties']);
        $this->assertFalse($schema['annotations']['readOnlyHint']);
        $this->assertFalse($schema['annotations']['idempotentHint']);
    }

    public function testSchemaHasLanguageParameterForMultiLanguageSite(): void
    {
        $this->writeSiteConfiguration([
            0 => ['title' => 'English', 'base' => '/', 'iso' => 'en'],
            1 => ['title' => 'German', 'base' => '/de/', 'iso' => 'de'],
        ]);

        $schema = $this->createTool()->getSchema();

        $this->assertSame(['en', 'de'], $schema['inputSchema']['properties']['language']['enum']);
    }

    public function testLanguageParameterSelectsLanguageOfPreviewUrl(): void
    {
        $this->writeSiteConfiguration([
            0 => ['title' => 'English', 'base' => '/', 'iso' => 'en'],
            1 => ['title' => 'German', 'base' => '/de/', 'iso' => 'de'],
        ]);
        $this->stageTitleChangeOnPage2();

        $result = $this->createTool()->execute(['uid' => 2, 'language' => 'de']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('https://example.com/de/', $result->content[0]->text);
    }

    public function testLanguageIsResolvedWithinTheSiteOfThePage(): void
    {
        $this->writeSiteConfiguration([
            0 => ['title' => 'English', 'base' => '/', 'iso' => 'en'],
            1 => ['title' => 'German', 'base' => '/de/', 'iso' => 'de'],
        ]);
        $this->writeSiteConfiguration([
            0 => ['title' => 'English', 'base' => '/', 'iso' => 'en'],
            1 => ['title' => 'French', 'base' => '/fr/', 'iso' => 'fr'],
        ], 'news-site', 7, 'https://news.example.com/');
        $this->stageTitleChange(2);
        $this->stageTitleChange(8);

        $french = $this->createTool()->execute(['uid' => 8, 'language' => 'fr']);
        $this->assertFalse($french->isError, json_encode($french->jsonSerialize()));
        $this->assertStringContainsString('https://news.example.com/fr/', $french->content[0]->text);

        $notInSite = $this->createTool()->execute(['uid' => 2, 'language' => 'fr']);
        $this->assertTrue($notInSite->isError);
    }

    public function testRelativePreviewUrlIsRejected(): void
    {
        $this->writeSiteConfiguration([0 => ['title' => 'English', 'base' => '/', 'iso' => 'en']], 'test-site', 1, '/');
        $this->stageTitleChangeOnPage2();
        $tool = new GetPreviewUrlTool(
            new class () extends SiteInformationService {
                public function makeAbsoluteUrl(?string $url): ?string
                {
                    return $url;
                }
            },
            GeneralUtility::makeInstance(LanguageService::class),
            GeneralUtility::makeInstance(PreviewUriBuilder::class),
        );

        $result = $tool->execute(['uid' => 2]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testExpiredReusedTokenIsReportedInsteadOfItsFullLifetime(): void
    {
        $result = $this->callAgainWithReusedTokenExpiringAt(time() - 60);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('expired', $result->content[0]->text);
    }

    public function testReportsRemainingLifetimeOfTheIssuedToken(): void
    {
        $result = $this->callAgainWithReusedTokenExpiringAt(time() + 3 * 3600 - 60);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('3 hours', $result->content[0]->text);
    }

    /**
     * PreviewUriBuilder reuses its token per workspace and user from TYPO3 14.3 on,
     * 13.4 creates a new one on every call, so there is nothing to age there.
     */
    private function callAgainWithReusedTokenExpiringAt(int $endtime): \Mcp\Types\CallToolResult
    {
        $this->stageTitleChangeOnPage2();
        $tool = $this->createTool();
        $first = $tool->execute(['uid' => 2]);
        $this->assertFalse($first->isError, json_encode($first->jsonSerialize()));

        $previews = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_preview');
        $previews->update('sys_preview', ['endtime' => $endtime], []);
        $second = $tool->execute(['uid' => 2]);

        if ($previews->count('*', 'sys_preview', []) > 1) {
            $this->markTestSkipped('PreviewUriBuilder does not reuse tokens in this TYPO3 version.');
        }

        return $second;
    }

    private function createTool(): GetPreviewUrlTool
    {
        return new GetPreviewUrlTool(
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(LanguageService::class),
            GeneralUtility::makeInstance(PreviewUriBuilder::class),
        );
    }

    private function stageTitleChangeOnPage2(): void
    {
        $this->stageTitleChange(2);
    }

    private function stageTitleChange(int $uid): void
    {
        GeneralUtility::makeInstance(WorkspaceContextService::class)->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $uid,
            'data' => ['title' => 'Workspace Draft'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    private function extractUrl(string $text): string
    {
        preg_match('#https?://\S+#', $text, $matches);

        return $matches[0] ?? '';
    }

    /**
     * @param array<int, array{title: string, base: string, iso: string}> $languages
     */
    private function writeSiteConfiguration(array $languages, string $identifier = 'test-site', int $rootPageId = 1, string $base = 'https://example.com/'): void
    {
        $siteDir = $this->instancePath . '/typo3conf/sites/' . $identifier;
        GeneralUtility::mkdir_deep($siteDir);

        $configured = [];
        foreach ($languages as $id => $language) {
            $configured[$id] = [
                'title' => $language['title'],
                'enabled' => true,
                'languageId' => $id,
                'base' => $language['base'],
                'locale' => $language['iso'] === 'de' ? 'de_DE.UTF-8' : 'en_US.UTF-8',
                'iso-639-1' => $language['iso'],
                'hreflang' => $language['iso'],
                'direction' => 'ltr',
                'navigationTitle' => $language['title'],
            ];
        }

        GeneralUtility::writeFile($siteDir . '/config.yaml', Yaml::dump([
            'rootPageId' => $rootPageId,
            'base' => $base,
            'websiteTitle' => 'Test Site',
            'languages' => $configured,
            'routes' => [],
            'errorHandling' => [],
        ], 99, 2), true);
    }
}
