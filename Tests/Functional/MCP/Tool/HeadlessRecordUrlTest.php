<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * With EXT:headless the site base serves JSON, visitors use the frontendBase.
 * Record URLs must point there, like the backend's view button does.
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
    ];

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

    public function testSiteWithoutHeadlessModeKeepsSiteBase(): void
    {
        $this->writeSiteConfiguration(0);

        $this->assertSame('https://api.example.com/about', $this->readRecord('pages', 2)['frontend_url'] ?? null);
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
     * @param int $headlessMode 0 = off, 1 = full, 2 = mixed
     * @param int|null $previewOverrideMode Site setting headless.preview.overrideMode, unset when null
     */
    private function writeSiteConfiguration(int $headlessMode, ?int $previewOverrideMode = null): void
    {
        $siteDir = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($siteDir);
        GeneralUtility::writeFile($siteDir . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => 'https://api.example.com/',
            'frontendBase' => 'https://www.example.com/',
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
