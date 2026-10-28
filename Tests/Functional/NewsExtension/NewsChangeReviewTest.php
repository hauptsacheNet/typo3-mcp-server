<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\ReviewChangeTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The change review of the MCP Apps view: an inline child is part of its parent, so the
 * change of a file reference is shown, published and discarded as the change of the
 * news record its content element belongs to.
 */
class NewsChangeReviewTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'news',
        'mcp_server',
    ];

    private const NEWS_UID = 100;
    private const CONTENT_UID = 300;
    private const UNRELATED_CONTENT_UID = 301;
    private const SIBLING_CONTENT_UID = 302;
    private const CONTENT_ASSET_UID = 400;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connectionPool->getConnectionForTable('tx_news_domain_model_news')->insert('tx_news_domain_model_news', [
            'uid' => self::NEWS_UID,
            'pid' => 1,
            'title' => 'Live news',
            'tstamp' => 1734875000,
            'crdate' => 1734875000,
        ]);
        foreach ([self::CONTENT_UID => self::NEWS_UID, self::UNRELATED_CONTENT_UID => 0, self::SIBLING_CONTENT_UID => self::NEWS_UID] as $uid => $newsUid) {
            $connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid,
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'Live content ' . $uid,
                'tx_news_related_news' => $newsUid,
                'sorting' => $uid,
                'tstamp' => 1734875000,
                'crdate' => 1734875000,
            ]);
        }
        $connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => self::CONTENT_ASSET_UID,
            'pid' => 1,
            'uid_local' => 1,
            'uid_foreign' => self::CONTENT_UID,
            'tablenames' => 'tt_content',
            'fieldname' => 'assets',
            'sorting_foreign' => 256,
            'alternative' => 'Live alt',
            'tstamp' => 1734875000,
            'crdate' => 1734875000,
        ]);
    }

    public function testFileReferenceChangeIsShownAsChangeOfTheNews(): void
    {
        $this->write([
            'table' => 'sys_file_reference',
            'action' => 'update',
            'uid' => self::CONTENT_ASSET_UID,
            'data' => ['alternative' => 'Changed alt'],
        ]);

        $review = $this->review('show', 'sys_file_reference', self::CONTENT_ASSET_UID);

        $this->assertTrue($review['pending']);
        $this->assertTrue($review['canPublish']);
        $news = $review['record'];
        $this->assertSame('tx_news_domain_model_news', $news['table']);
        $this->assertSame(self::NEWS_UID, $news['uid']);
        $this->assertSame('Live news', $news['title']);
        // Versioned only because a child changed
        $this->assertSame('unchanged', $news['state']);
        $this->assertSame([], $news['fields']);

        // Only the content element with the change is listed, not its staged sibling
        $this->assertCount(1, $news['relations']);
        $this->assertSame('content_elements', $news['relations'][0]['field']);
        $this->assertSame([self::CONTENT_UID], array_column($news['relations'][0]['records'], 'uid'));

        $content = $news['relations'][0]['records'][0];
        $this->assertSame('unchanged', $content['state']);
        $this->assertSame('assets', $content['relations'][0]['field']);
        $reference = $content['relations'][0]['records'][0];
        $this->assertSame(self::CONTENT_ASSET_UID, $reference['uid']);
        $this->assertSame('changed', $reference['state']);
        $this->assertSame(
            [['field' => 'alternative', 'before' => 'Live alt', 'after' => 'Changed alt']],
            array_map(static fn(array $field): array => array_diff_key($field, ['label' => true]), $reference['fields'])
        );
    }

    public function testContentElementChangeIsShownWithItsFields(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => ['header' => 'Changed header'],
        ]);

        $review = $this->review('show', 'tt_content', self::CONTENT_UID);

        $content = $review['record']['relations'][0]['records'][0];
        $this->assertSame('changed', $content['state']);
        $this->assertSame('Changed header', $content['title']);
        $header = array_column($content['fields'], null, 'field')['header'] ?? null;
        $this->assertSame('Live content ' . self::CONTENT_UID, $header['before'] ?? null);
        $this->assertSame('Changed header', $header['after'] ?? null);
        $this->assertNotSame('header', $header['label'], 'The TCA label is shown');
    }

    public function testRecordWithoutInlineParentIsItsOwnChange(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::UNRELATED_CONTENT_UID,
            'data' => ['header' => 'Changed header'],
        ]);

        $review = $this->review('show', 'tt_content', self::UNRELATED_CONTENT_UID);

        $this->assertSame('tt_content', $review['record']['table']);
        $this->assertSame(self::UNRELATED_CONTENT_UID, $review['record']['uid']);
        $this->assertSame('changed', $review['record']['state']);
    }

    public function testPublishingTheChangePublishesTheWholeNews(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [
                'header' => 'Changed header',
                'assets' => [['uid' => self::CONTENT_ASSET_UID, 'alternative' => 'Changed alt']],
            ],
        ]);
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::UNRELATED_CONTENT_UID,
            'data' => ['header' => 'Unrelated change'],
        ]);

        $review = $this->review('publish', 'sys_file_reference', self::CONTENT_ASSET_UID);

        $this->assertSame('publish', $review['action']);
        $this->assertFalse($review['pending'], json_encode($review));
        $this->assertSame('Changed header', $this->getLiveRow('tt_content', self::CONTENT_UID)['header']);
        $this->assertSame('Changed alt', $this->getLiveRow('sys_file_reference', self::CONTENT_ASSET_UID)['alternative']);
        $this->assertSame('Live news', $this->getLiveRow('tx_news_domain_model_news', self::NEWS_UID)['title']);
        foreach ([
            ['tx_news_domain_model_news', self::NEWS_UID],
            ['tt_content', self::CONTENT_UID],
            ['tt_content', self::SIBLING_CONTENT_UID],
            ['sys_file_reference', self::CONTENT_ASSET_UID],
        ] as [$table, $uid]) {
            $this->assertSame([], $this->getWorkspaceVersions($table, $uid), "$table:$uid must not stay in the workspace");
        }

        // Changes outside the news stay in the workspace
        $this->assertCount(1, $this->getWorkspaceVersions('tt_content', self::UNRELATED_CONTENT_UID));
        $this->assertSame('Live content ' . self::UNRELATED_CONTENT_UID, $this->getLiveRow('tt_content', self::UNRELATED_CONTENT_UID)['header']);
    }

    public function testDiscardingTheChangeRestoresTheLiveState(): void
    {
        $liveBefore = $this->getLiveRow('tt_content', self::CONTENT_UID);
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [
                'header' => 'Changed header',
                'assets' => [['uid' => self::CONTENT_ASSET_UID, 'alternative' => 'Changed alt']],
            ],
        ]);

        $review = $this->review('discard', 'tt_content', self::CONTENT_UID);

        $this->assertFalse($review['pending'], json_encode($review));
        $this->assertSame($liveBefore, $this->getLiveRow('tt_content', self::CONTENT_UID));
        foreach ([
            ['tx_news_domain_model_news', self::NEWS_UID],
            ['tt_content', self::CONTENT_UID],
            ['tt_content', self::SIBLING_CONTENT_UID],
            ['sys_file_reference', self::CONTENT_ASSET_UID],
        ] as [$table, $uid]) {
            $this->assertSame([], $this->getWorkspaceVersions($table, $uid), "$table:$uid must not stay in the workspace");
        }
        $this->assertFalse($this->review('show', 'tt_content', self::CONTENT_UID)['pending']);
    }

    public function testNewContentElementIsShownAndPublishedAsNew(): void
    {
        $created = $this->write([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => 1,
                'CType' => 'text',
                'header' => 'New content',
                'tx_news_related_news' => self::NEWS_UID,
            ],
        ]);

        $review = $this->review('show', 'tt_content', (int)$created['uid']);
        $records = $review['record']['relations'][0]['records'];
        $new = array_column($records, null, 'uid')[(int)$created['uid']] ?? null;
        $this->assertSame('new', $new['state'] ?? null, json_encode($review));
        $this->assertContains('New content', array_column($new['fields'], 'after'));

        $this->review('publish', 'tt_content', (int)$created['uid']);

        $row = $this->getLiveRow('tt_content', (int)$created['uid']);
        $this->assertSame(0, (int)$row['t3ver_wsid'], 'The new record is live now');
        $this->assertSame('New content', $row['header']);
        $this->assertSame(self::NEWS_UID, (int)$row['tx_news_related_news']);
    }

    public function testDeletedContentElementIsShownAsDeletedInItsNews(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'delete',
            'uid' => self::CONTENT_UID,
        ]);

        $review = $this->review('show', 'tt_content', self::CONTENT_UID);

        $this->assertSame('tx_news_domain_model_news', $review['record']['table'], json_encode($review));
        $deleted = array_column($review['record']['relations'][0]['records'], null, 'uid')[self::CONTENT_UID] ?? null;
        $this->assertSame('deleted', $deleted['state'] ?? null, json_encode($review));
        $this->assertSame('Live content ' . self::CONTENT_UID, $deleted['title']);

        $this->review('publish', 'tt_content', self::CONTENT_UID);

        $this->assertSame(1, (int)$this->getLiveRow('tt_content', self::CONTENT_UID)['deleted']);
    }

    public function testPublishingIsRefusedWhereTheWorkspaceRequiresThePublishStage(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::UNRELATED_CONTENT_UID,
            'data' => ['header' => 'Changed header'],
        ]);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_workspace')->update(
            'sys_workspace',
            ['publish_access' => 1],
            ['uid' => (int)$GLOBALS['BE_USER']->workspace]
        );

        $review = $this->review('show', 'tt_content', self::UNRELATED_CONTENT_UID);
        $this->assertTrue($review['pending']);
        $this->assertFalse($review['canPublish']);

        $result = GeneralUtility::makeInstance(ReviewChangeTool::class)->execute([
            'action' => 'publish',
            'table' => 'tt_content',
            'uid' => self::UNRELATED_CONTENT_UID,
        ]);
        $this->assertTrue($result->isError);
        $this->assertSame('Live content ' . self::UNRELATED_CONTENT_UID, $this->getLiveRow('tt_content', self::UNRELATED_CONTENT_UID)['header']);
    }

    private function write(array $params): array
    {
        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertGreaterThan(0, (int)$GLOBALS['BE_USER']->workspace, 'WriteTable must operate in a workspace');
        return json_decode($result->content[0]->text, true);
    }

    private function review(string $action, string $table, int $uid): array
    {
        $result = GeneralUtility::makeInstance(ReviewChangeTool::class)->execute([
            'action' => $action,
            'table' => $table,
            'uid' => $uid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(json_decode($result->content[0]->text, true), $result->structuredContent);
        return $result->structuredContent;
    }

    private function getWorkspaceVersions(string $table, int $liveUid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder
            ->select('uid', 't3ver_state')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter((int)$GLOBALS['BE_USER']->workspace)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function getLiveRow(string $table, int $uid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAssociative();
    }
}
