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
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * With EXT:headless the site base serves JSON, visitors use the frontendBase.
 * Record URLs must point there, like the backend's view button does.
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
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');
    }

    public function testFrontendUrlPointsAtFrontendBase(): void
    {
        $this->writeSiteConfiguration(1);

        $record = $this->readRecord('pages', 2);

        $this->assertSame('https://www.example.com/about', $record['frontend_url'] ?? null);
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

        $record = $this->readRecord('pages', 2);

        $this->assertSame('https://api.example.com/about', $record['frontend_url'] ?? null);
    }

    /**
     * Mixed-mode sites render HTML for backend previews unless the site setting
     * headless.preview.overrideMode switches them to the frontend (view button parity).
     */
    public function testMixedModeKeepsSiteBaseByDefault(): void
    {
        $this->writeSiteConfiguration(2);

        $record = $this->readRecord('pages', 2);

        $this->assertSame('https://api.example.com/about', $record['frontend_url'] ?? null);
    }

    public function testMixedModeWithPreviewOverrideUsesFrontendBase(): void
    {
        $this->writeSiteConfiguration(2, '', 1);

        $record = $this->readRecord('pages', 2);

        $this->assertSame('https://www.example.com/about', $record['frontend_url'] ?? null);
    }

    public function testGetPageResolvesFrontendBaseUrl(): void
    {
        $this->writeSiteConfiguration(1);

        $text = $this->executeGetPage(['url' => 'https://www.example.com/about/team']);

        $this->assertStringContainsString('UID: 4' . "\n", $text);
    }

    public function testFrontendDomainIsAdvertisedForGetPage(): void
    {
        $this->writeSiteConfiguration(1, 'https://www.example.de/');

        $domains = GeneralUtility::makeInstance(SiteInformationService::class)->getAllDomains();

        $this->assertContains('api.example.com', $domains);
        $this->assertContains('www.example.com', $domains);
        $this->assertContains('www.example.de', $domains);
    }

    /**
     * EXT:headless reads a frontendBase without scheme as domain.
     */
    public function testFrontendBaseWithoutSchemeIsAcceptedByGetPage(): void
    {
        $this->writeSiteConfiguration(1, '', null, 'www.example.com');

        $this->assertContains('www.example.com', GeneralUtility::makeInstance(SiteInformationService::class)->getAllDomains());
        $text = $this->executeGetPage(['url' => 'https://www.example.com/about/team']);
        $this->assertStringContainsString('UID: 4' . "\n", $text);
    }

    /**
     * A frontend served below a path: EXT:headless prefixes the page path with it,
     * GetPage has to strip it again.
     */
    public function testFrontendBaseWithPathRoundTrips(): void
    {
        $this->writeSiteConfiguration(1, '', null, 'https://www.example.com/app/');

        $record = $this->readRecord('pages', 4);
        $this->assertSame('https://www.example.com/app/about/team', $record['frontend_url'] ?? null);

        $this->assertStringContainsString('UID: 4' . "\n", $this->executeGetPage(['url' => $record['frontend_url']]));
        $this->assertStringContainsString('UID: 1' . "\n", $this->executeGetPage(['url' => 'https://www.example.com/app/']));
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
     * @param string $germanFrontendBase Frontend domain of the German language, none when empty
     * @param int|null $previewOverrideMode Site setting headless.preview.overrideMode, unset when null
     * @param string $frontendBase Site-wide frontendBase
     */
    private function writeSiteConfiguration(
        int $headlessMode,
        string $germanFrontendBase = '',
        ?int $previewOverrideMode = null,
        string $frontendBase = 'https://www.example.com/'
    ): void {
        $german = [
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
        ];
        if ($germanFrontendBase !== '') {
            $german['frontendBase'] = $germanFrontendBase;
        }

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
                1 => $german,
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
