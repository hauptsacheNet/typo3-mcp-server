<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\Traits\RestrictedEditorTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The restricted editor translates HTML content elements into a language
 * with fallbackType "free" and one with fallbackType "fallback". In both
 * cases the translate action creates a connected translation (l18n_parent
 * points to the original) inside the workspace.
 */
class RestrictedEditorTranslationTest extends FunctionalTestCase
{
    use RestrictedEditorTrait;

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
        $this->importRestrictedEditorFixture();
        $this->actAsBackendUser(self::RESTRICTED_EDITOR_UID);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function targetLanguageProvider(): array
    {
        return [
            'fallbackType free' => ['de', 1],
            'fallbackType fallback' => ['fr', 2],
        ];
    }

    #[DataProvider('targetLanguageProvider')]
    public function testTranslateHtmlElementCreatesConnectedTranslationInWorkspace(string $isoCode, int $languageUid): void
    {
        $liveBefore = $this->fetchLiveRecords('tt_content');

        $result = (new WriteTableTool())->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
            'data' => [
                'sys_language_uid' => $isoCode,
                'bodytext' => '<p>Translated markup (' . $isoCode . ')</p>',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $translationUid = $this->decodeToolResult($result)['translationUid'] ?? null;
        $this->assertIsInt($translationUid, json_encode($result->jsonSerialize()));

        $translations = $this->fetchRawRecords('tt_content', [
            'l18n_parent' => self::HTML_ELEMENT_UID,
            'sys_language_uid' => $languageUid,
        ]);
        $this->assertCount(1, $translations);
        $translation = $translations[0];
        $this->assertSame(self::EDITORIAL_WORKSPACE_UID, (int)$translation['t3ver_wsid'], 'Translation must live in the workspace');
        $this->assertSame('html', $translation['CType']);
        $this->assertSame(self::MOUNTED_PAGE_UID, (int)$translation['pid']);
        $this->assertSame('<p>Translated markup (' . $isoCode . ')</p>', $translation['bodytext']);

        $this->assertEquals($liveBefore, $this->fetchLiveRecords('tt_content'), 'Live tt_content rows must be unchanged');
    }

    #[DataProvider('targetLanguageProvider')]
    public function testTranslatedHtmlElementCanBeReadAndUpdated(string $isoCode, int $languageUid): void
    {
        $tool = new WriteTableTool();
        $translate = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
            'data' => ['sys_language_uid' => $isoCode, 'bodytext' => '<p>First draft</p>'],
        ]);
        $this->assertFalse($translate->isError, json_encode($translate->jsonSerialize()));
        $translationUid = (int)$this->decodeToolResult($translate)['translationUid'];

        $update = $tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $translationUid,
            'data' => ['bodytext' => '<p>Second draft</p>'],
        ]);
        $this->assertFalse($update->isError, json_encode($update->jsonSerialize()));

        $read = (new ReadTableTool())->execute([
            'table' => 'tt_content',
            'pid' => self::MOUNTED_PAGE_UID,
            'language' => $isoCode,
        ]);
        $this->assertFalse($read->isError, json_encode($read->jsonSerialize()));
        $records = $this->decodeToolResult($read)['records'];
        $this->assertCount(1, $records, json_encode($records));
        $this->assertSame('<p>Second draft</p>', $records[0]['bodytext']);
        $this->assertSame('html', $records[0]['CType']);

        $translations = $this->fetchRawRecords('tt_content', [
            'l18n_parent' => self::HTML_ELEMENT_UID,
            'sys_language_uid' => $languageUid,
        ]);
        $this->assertCount(1, $translations, 'Updating must not create a second translation');
        $this->assertSame(self::EDITORIAL_WORKSPACE_UID, (int)$translations[0]['t3ver_wsid']);
    }

    public function testTranslatingOtherContentTypeIsRejected(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => self::TEXT_ELEMENT_UID,
            'data' => ['sys_language_uid' => 'de'],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([], $this->fetchRawRecords('tt_content', ['l18n_parent' => self::TEXT_ELEMENT_UID]));
    }

    public function testTranslatingElementOutsideMountIsRejected(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => self::UNMOUNTED_HTML_ELEMENT_UID,
            'data' => ['sys_language_uid' => 'de'],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([], $this->fetchRawRecords('tt_content', ['l18n_parent' => self::UNMOUNTED_HTML_ELEMENT_UID]));
    }

    protected function writeSiteConfiguration(): void
    {
        $language = static fn(int $id, string $title, string $iso, string $locale, array $extra = []): array => array_merge([
            'title' => $title,
            'enabled' => true,
            'languageId' => $id,
            'base' => $id === 0 ? '/' : '/' . $iso . '/',
            'locale' => $locale,
            'iso-639-1' => $iso,
            'hreflang' => $iso,
            'direction' => 'ltr',
            'flag' => $iso,
            'navigationTitle' => $title,
        ], $extra);

        $configuration = [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'websiteTitle' => 'Test Site',
            'languages' => [
                $language(0, 'English', 'en', 'en_US.UTF-8'),
                $language(1, 'German', 'de', 'de_DE.UTF-8', ['fallbackType' => 'free']),
                $language(2, 'French', 'fr', 'fr_FR.UTF-8', ['fallbackType' => 'fallback', 'fallbacks' => '0']),
            ],
            'routes' => [],
            'errorHandling' => [],
        ];

        $configPath = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($configPath);
        GeneralUtility::writeFile($configPath . '/config.yaml', Yaml::dump($configuration, 99, 2), true);
    }
}
