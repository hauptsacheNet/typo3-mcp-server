<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\LanguageService;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Languages that share a two-letter code within one site (de_DE, de_AT,
 * de_CH) must stay addressable, and a code must be resolved within the site
 * of the record it is used for:
 *
 * - main site (root 1): de-DE (0), de-AT (1, fallbackType free), en (2),
 *   de-CH (3, fallbackType fallback)
 * - second site (root 50): fr (0), en (1)
 */
class SiteLanguageIdentifierTest extends FunctionalTestCase
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
        $this->writeSiteConfigurations();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $pages = $this->getConnection('pages');
        $pages->insert('pages', ['uid' => 50, 'pid' => 0, 'title' => 'Second site', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'sorting' => 2048]);
        $pages->insert('pages', ['uid' => 51, 'pid' => 50, 'title' => 'Second site page', 'slug' => '/page', 'doktype' => 1, 'sorting' => 256]);
        $this->setUpBackendUser(1);
        // DataHandler's localize path needs $GLOBALS['LANG'] for the
        // "[Translate to ...]" prefix
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    public function testCodesSharedWithinASiteBecomeFullTags(): void
    {
        $languageService = GeneralUtility::makeInstance(LanguageService::class);

        $this->assertSame(
            ['de-DE', 'de-AT', 'en', 'de-CH'],
            array_column($languageService->getLanguagesForPage(1), 'isoCode')
        );
        $this->assertSame(['fr', 'en'], array_column($languageService->getLanguagesForPage(51), 'isoCode'));
        $this->assertSame('de-AT (1, Österreich)', $languageService->formatLanguage(1, 1));
        $this->assertSame(1, $languageService->getUidFromIsoCode('DE-at', 1), 'Identifiers are case-insensitive');
    }

    public function testSchemaOffersTheIdentifiersOfAllSites(): void
    {
        $enum = (new ReadTableTool())->getSchema()['inputSchema']['properties']['language']['enum'] ?? [];

        foreach (['de-DE', 'de-AT', 'en', 'de-CH', 'fr'] as $identifier) {
            $this->assertContains($identifier, $enum);
        }
        $this->assertNotContains('de', $enum, 'The shared code must not be offered');
    }

    public function testTranslateIntoAVariantByTag(): void
    {
        $sourceUid = $this->createHtmlElement(1, 'Kontakt');

        $result = $this->translate($sourceUid, 'de-AT', ['bodytext' => '<p>Telefon +43 1 234567</p>']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);
        $this->assertSame('de-AT', $data['targetLanguage']);
        $this->assertSame('de-AT (1, Österreich)', $data['targetLanguageLabel']);
        $translations = $this->fetchTranslations($sourceUid);
        $this->assertCount(1, $translations);
        $this->assertSame(1, (int)$translations[0]['sys_language_uid']);
        $this->assertSame('<p>Telefon +43 1 234567</p>', $translations[0]['bodytext']);
    }

    public function testTranslateByNumericLanguageId(): void
    {
        $sourceUid = $this->createHtmlElement(1, 'Kontakt');

        $asInteger = $this->translate($sourceUid, 3, ['bodytext' => '<p>CH</p>']);
        $this->assertFalse($asInteger->isError, json_encode($asInteger->jsonSerialize()));

        $otherUid = $this->createHtmlElement(1, 'Impressum');
        $asString = $this->translate($otherUid, '1', ['bodytext' => '<p>AT</p>']);
        $this->assertFalse($asString->isError, json_encode($asString->jsonSerialize()));

        $this->assertSame([3], array_map('intval', array_column($this->fetchTranslations($sourceUid), 'sys_language_uid')));
        $this->assertSame([1], array_map('intval', array_column($this->fetchTranslations($otherUid), 'sys_language_uid')));
    }

    public function testSharedCodeIsRejectedWithTheValidLanguages(): void
    {
        $sourceUid = $this->createHtmlElement(1, 'Kontakt');

        $result = $this->translate($sourceUid, 'de', ['bodytext' => '<p>?</p>']);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $message = $result->content[0]->text;
        $this->assertStringContainsString('Unknown language code: de', $message);
        $this->assertStringContainsString('de-AT (1, Österreich)', $message);
        $this->assertStringContainsString('de-CH (3, Schweiz)', $message);
        $this->assertSame([], $this->fetchTranslations($sourceUid));
    }

    /**
     * Localizing into the default language made DataHandler copy the record
     * and its existing translations as new default-language records (one call
     * produced three rows).
     */
    public function testTranslateIntoTheDefaultLanguageIsRejected(): void
    {
        $content = $this->getConnection('tt_content');
        $content->insert('tt_content', ['uid' => 300, 'pid' => 1, 'CType' => 'html', 'header' => 'Kontakt', 'bodytext' => '<p>DE</p>', 'sys_language_uid' => 0]);
        $content->insert('tt_content', ['uid' => 301, 'pid' => 1, 'CType' => 'html', 'header' => 'Kontakt AT', 'bodytext' => '<p>AT</p>', 'sys_language_uid' => 1, 'l18n_parent' => 300, 'l10n_source' => 300]);
        $content->insert('tt_content', ['uid' => 302, 'pid' => 1, 'CType' => 'html', 'header' => 'Contact', 'bodytext' => '<p>EN</p>', 'sys_language_uid' => 2, 'l18n_parent' => 300, 'l10n_source' => 300]);
        $rowsBefore = $this->countRows('tt_content');

        foreach (['de-DE', 0, '0'] as $target) {
            $result = $this->translate(300, $target, ['bodytext' => '<p>?</p>']);
            $this->assertTrue($result->isError, 'Target ' . var_export($target, true) . ': ' . json_encode($result->jsonSerialize()));
            $this->assertStringContainsString('Cannot translate into de-DE (0, Deutsch)', $result->content[0]->text);
        }

        $this->assertSame($rowsBefore, $this->countRows('tt_content'), 'No record may be created');
    }

    public function testTranslateIntoALanguageOfAnotherSiteIsRejected(): void
    {
        $sourceUid = $this->createHtmlElement(1, 'Kontakt');

        $byCode = $this->translate($sourceUid, 'fr', ['bodytext' => '<p>FR</p>']);
        $this->assertTrue($byCode->isError, json_encode($byCode->jsonSerialize()));
        $this->assertStringContainsString('Unknown language code: fr for this site', $byCode->content[0]->text);

        $byId = $this->translate($sourceUid, 7, ['bodytext' => '<p>?</p>']);
        $this->assertTrue($byId->isError, json_encode($byId->jsonSerialize()));
        $this->assertStringContainsString('Unknown language ID: 7 for this site', $byId->content[0]->text);

        $this->assertSame([], $this->fetchTranslations($sourceUid));
    }

    public function testTheSameCodeResolvesWithinTheRecordsSite(): void
    {
        $mainUid = $this->createHtmlElement(1, 'Main');
        $secondUid = $this->createHtmlElement(51, 'Second');

        $this->assertFalse($this->translate($mainUid, 'en', ['bodytext' => '<p>EN main</p>'])->isError);
        $this->assertFalse($this->translate($secondUid, 'en', ['bodytext' => '<p>EN second</p>'])->isError);

        $this->assertSame([2], array_map('intval', array_column($this->fetchTranslations($mainUid), 'sys_language_uid')));
        $this->assertSame([1], array_map('intval', array_column($this->fetchTranslations($secondUid), 'sys_language_uid')));
    }

    public function testCreateWithAVariantTag(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => ['pid' => 1, 'CType' => 'html', 'header' => 'Nur Schweiz', 'bodytext' => '<p>CH</p>', 'sys_language_uid' => 'de-CH'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = (int)json_decode($result->content[0]->text, true)['uid'];
        $this->assertSame(3, (int)$this->fetchRow('tt_content', $uid)['sys_language_uid']);
    }

    public function testReadByVariantTag(): void
    {
        $sourceUid = $this->createHtmlElement(1, 'Kontakt');
        $this->assertFalse($this->translate($sourceUid, 'de-AT', ['bodytext' => '<p>AT</p>'])->isError);

        $austria = (new ReadTableTool())->execute(['table' => 'tt_content', 'pid' => 1, 'language' => 'de-AT']);
        $this->assertFalse($austria->isError, json_encode($austria->jsonSerialize()));
        $this->assertSame(['<p>AT</p>'], array_column(json_decode($austria->content[0]->text, true)['records'], 'bodytext'));

        $switzerland = (new ReadTableTool())->execute(['table' => 'tt_content', 'pid' => 1, 'language' => 'de-CH']);
        $this->assertFalse($switzerland->isError, json_encode($switzerland->jsonSerialize()));
        $this->assertSame([], json_decode($switzerland->content[0]->text, true)['records']);
    }

    public function testCodeWithDifferentIdsPerSiteNeedsAPageContext(): void
    {
        $withoutPage = (new ReadTableTool())->execute(['table' => 'tt_content', 'language' => 'en']);
        $this->assertTrue($withoutPage->isError, json_encode($withoutPage->jsonSerialize()));
        $this->assertStringContainsString('Ambiguous language code: "en"', $withoutPage->content[0]->text);

        $search = (new SearchTool())->execute(['terms' => ['Kontakt'], 'language' => 'en']);
        $this->assertTrue($search->isError, json_encode($search->jsonSerialize()));

        $withPage = (new ReadTableTool())->execute(['table' => 'tt_content', 'pid' => 51, 'language' => 'en']);
        $this->assertFalse($withPage->isError, json_encode($withPage->jsonSerialize()));

        $searchOnPage = (new SearchTool())->execute(['terms' => ['Kontakt'], 'language' => 'en', 'pageId' => 1]);
        $this->assertFalse($searchOnPage->isError, json_encode($searchOnPage->jsonSerialize()));
    }

    public function testPageOutputNamesTheVariant(): void
    {
        $this->getConnection('pages')->insert('pages', [
            'uid' => 60, 'pid' => 0, 'title' => 'Startseite AT', 'slug' => '/', 'doktype' => 1,
            'sys_language_uid' => 1, 'l10n_parent' => 1,
        ]);
        $tool = $this->get(ToolRegistry::class)->getTool('GetPage');

        $result = $tool->execute(['uid' => 1, 'language' => 'de-AT']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        $this->assertStringContainsString('Language: de-AT (1, Österreich)', $output);
        $this->assertStringContainsString('Available Translations: de-AT (1, Österreich)', $output);
    }

    private function createHtmlElement(int $pid, string $header): int
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => ['pid' => $pid, 'CType' => 'html', 'header' => $header, 'bodytext' => '<p>' . $header . '</p>'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return (int)json_decode($result->content[0]->text, true)['uid'];
    }

    private function translate(int $uid, string|int $language, array $fields): \Mcp\Types\CallToolResult
    {
        return (new WriteTableTool())->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => array_merge(['sys_language_uid' => $language], $fields),
        ]);
    }

    /**
     * @return array<int, array>
     */
    private function fetchTranslations(int $sourceUid): array
    {
        $queryBuilder = $this->getConnection('tt_content')->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('l18n_parent', $queryBuilder->createNamedParameter($sourceUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function fetchRow(string $table, int $uid): array
    {
        $queryBuilder = $this->getConnection($table)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative() ?: [];
    }

    private function countRows(string $table): int
    {
        $queryBuilder = $this->getConnection($table)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        return (int)$queryBuilder->count('uid')->from($table)->executeQuery()->fetchOne();
    }

    private function getConnection(string $table): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
    }

    private function writeSiteConfigurations(): void
    {
        $language = static fn(int $id, string $title, string $iso, string $locale, string $hreflang, array $extra = []): array => array_merge([
            'title' => $title,
            'enabled' => true,
            'languageId' => $id,
            'base' => $id === 0 ? '/' : '/' . strtolower($hreflang) . '/',
            'locale' => $locale,
            'iso-639-1' => $iso,
            'hreflang' => $hreflang,
            'direction' => 'ltr',
            'flag' => $iso,
            'navigationTitle' => $title,
        ], $extra);

        $this->writeSiteConfiguration('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                $language(0, 'Deutsch', 'de', 'de_DE.UTF-8', 'de-DE'),
                $language(1, 'Österreich', 'de', 'de_AT.UTF-8', 'de-AT', ['fallbackType' => 'free']),
                $language(2, 'English', 'en', 'en_GB.UTF-8', 'en-GB', ['fallbackType' => 'strict']),
                $language(3, 'Schweiz', 'de', 'de_CH.UTF-8', 'de-CH', ['fallbackType' => 'fallback', 'fallbacks' => '0']),
            ],
        ]);
        $this->writeSiteConfiguration('second', [
            'rootPageId' => 50,
            'base' => 'https://second.example.com/',
            'languages' => [
                $language(0, 'Français', 'fr', 'fr_FR.UTF-8', 'fr-FR'),
                $language(1, 'English', 'en', 'en_US.UTF-8', 'en-US', ['fallbackType' => 'strict']),
            ],
        ]);
    }

    private function writeSiteConfiguration(string $identifier, array $configuration): void
    {
        $path = $this->instancePath . '/typo3conf/sites/' . $identifier;
        GeneralUtility::mkdir_deep($path);
        GeneralUtility::writeFile($path . '/config.yaml', Yaml::dump($configuration + ['routes' => [], 'errorHandling' => []], 99, 2), true);
    }
}
