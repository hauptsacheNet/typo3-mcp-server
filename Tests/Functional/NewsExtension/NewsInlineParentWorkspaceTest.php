<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Writing an inline child must stage its inline parents in the workspace too.
 *
 * In the TYPO3 backend a content element of a news record is edited through the news
 * form, so saving it versions the news as well and the workspace module lists the news
 * as modified. WriteTable writes the child on its own, so it has to mark the parents
 * itself: for independent children (tt_content), for embedded children changed through
 * the parent, and up the whole inline chain.
 */
class NewsInlineParentWorkspaceTest extends FunctionalTestCase
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
    private const OTHER_NEWS_UID = 101;
    private const CONTENT_UID = 300;
    private const UNRELATED_CONTENT_UID = 301;
    private const SIBLING_CONTENT_UID = 302;
    private const LINK_UID = 201;
    private const CONTENT_ASSET_UID = 400;
    private const CONTENT_SECOND_ASSET_UID = 401;
    private const CONTENT_FLEXFORM_FILE_UID = 402;
    private const NEWS_MEDIA_UID = 403;
    private const LINK_FLEXFORM_FILE_UID = 404;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        // Everything below is live, the tool has to stage every change in a workspace.
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([self::NEWS_UID => 'Live news', self::OTHER_NEWS_UID => 'Other live news'] as $uid => $title) {
            $connectionPool->getConnectionForTable('tx_news_domain_model_news')->insert('tx_news_domain_model_news', [
                'uid' => $uid,
                'pid' => 1,
                'title' => $title,
                'tstamp' => 1734875000,
                'crdate' => 1734875000,
            ]);
        }
        $connectionPool->getConnectionForTable('tx_news_domain_model_link')->insert('tx_news_domain_model_link', [
            'uid' => self::LINK_UID,
            'pid' => 1,
            'parent' => self::NEWS_UID,
            'title' => 'Live link',
            'uri' => 'https://example.com/',
            'sorting' => 256,
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
        // File references point at their parent through uid_foreign, tablenames and fieldname.
        // The FlexForm one names a field that is no TCA column of tt_content.
        foreach ([
            [self::CONTENT_ASSET_UID, 'tt_content', self::CONTENT_UID, 'assets', 256],
            [self::CONTENT_SECOND_ASSET_UID, 'tt_content', self::CONTENT_UID, 'assets', 512],
            [self::CONTENT_FLEXFORM_FILE_UID, 'tt_content', self::CONTENT_UID, 'settings.image', 256],
            [self::NEWS_MEDIA_UID, 'tx_news_domain_model_news', self::OTHER_NEWS_UID, 'fal_media', 256],
            // A parent table without any file field in TCA, as with file fields in FlexForms only.
            [self::LINK_FLEXFORM_FILE_UID, 'tx_news_domain_model_link', self::LINK_UID, 'settings.image', 256],
        ] as [$uid, $tablenames, $uidForeign, $fieldname, $sorting]) {
            $connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
                'uid' => $uid,
                'pid' => 1,
                'uid_local' => 1,
                'uid_foreign' => $uidForeign,
                'tablenames' => $tablenames,
                'fieldname' => $fieldname,
                'sorting_foreign' => $sorting,
                'tstamp' => 1734875000,
                'crdate' => 1734875000,
            ]);
        }
    }

    public function testUpdatingContentElementMarksNewsAsModified(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => ['header' => 'Changed header'],
        ]);

        $this->assertWorkspaceVersionExists('tt_content', self::CONTENT_UID);
        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
        $this->assertNoWorkspaceVersion('tx_news_domain_model_news', self::OTHER_NEWS_UID);

        // The parent version is an unchanged copy, live stays untouched and reads stay transparent.
        $this->assertSame('Live news', $this->getLiveRow('tx_news_domain_model_news', self::NEWS_UID)['title']);
        $news = $this->readNews(self::NEWS_UID);
        $this->assertSame(self::NEWS_UID, (int)$news['uid']);
        $this->assertSame('Live news', $news['title']);
        $this->assertSame([self::CONTENT_UID, self::SIBLING_CONTENT_UID], array_map('intval', $news['content_elements']));
    }

    /**
     * Versioning a parent makes DataHandler version its children along and renumber the
     * sorting of their live rows, down to the file references of a sibling content
     * element. Live must stay exactly as it was.
     */
    public function testMarkingNewsAsModifiedLeavesLiveRowsUntouched(): void
    {
        $tables = ['tx_news_domain_model_news', 'tx_news_domain_model_link', 'tt_content', 'sys_file_reference'];
        $liveBefore = array_map($this->getLiveRows(...), $tables);

        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::SIBLING_CONTENT_UID,
            'data' => ['header' => 'Changed header'],
        ]);

        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
        $this->assertWorkspaceVersionExists('sys_file_reference', self::CONTENT_ASSET_UID);
        $this->assertSame($liveBefore, array_map($this->getLiveRows(...), $tables));
    }

    public function testUpdatingUnrelatedContentElementMarksNoNews(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::UNRELATED_CONTENT_UID,
            'data' => ['header' => 'Changed header'],
        ]);

        $this->assertWorkspaceVersionExists('tt_content', self::UNRELATED_CONTENT_UID);
        $this->assertNoWorkspaceVersion('tx_news_domain_model_news', self::NEWS_UID);
        $this->assertNoWorkspaceVersion('tx_news_domain_model_news', self::OTHER_NEWS_UID);
    }

    public function testCreatingContentElementMarksNewsAsModified(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => 1,
                'CType' => 'text',
                'header' => 'New content',
                'tx_news_related_news' => self::NEWS_UID,
            ],
        ]);

        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
    }

    public function testDeletingContentElementMarksNewsAsModified(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'delete',
            'uid' => self::CONTENT_UID,
        ]);

        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
        $this->assertSame([self::SIBLING_CONTENT_UID], array_map('intval', $this->readNews(self::NEWS_UID)['content_elements']));
    }

    public function testMovingContentElementToAnotherNewsMarksBothAsModified(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => ['tx_news_related_news' => self::OTHER_NEWS_UID],
        ]);

        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::OTHER_NEWS_UID);
    }

    /**
     * Listing another news' content element takes it away from that news.
     */
    public function testClaimingContentElementOfAnotherNewsMarksBothAsModified(): void
    {
        $this->write([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => self::OTHER_NEWS_UID,
            'data' => ['content_elements' => [self::CONTENT_UID]],
        ]);

        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::OTHER_NEWS_UID);
        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
        $this->assertSame([self::SIBLING_CONTENT_UID], array_map('intval', $this->readNews(self::NEWS_UID)['content_elements']));
    }

    public function testCreatingNewsWithContentElementOfAnotherNewsMarksThatNews(): void
    {
        $this->write([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'data' => ['pid' => 1, 'title' => 'New news', 'content_elements' => [self::CONTENT_UID]],
        ]);

        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
        $this->assertNoWorkspaceVersion('tx_news_domain_model_news', self::OTHER_NEWS_UID);
    }

    /**
     * Only an embedded child changes, the parent itself gets no field write.
     */
    public function testUpdatingOnlyEmbeddedLinkMarksNewsAsModified(): void
    {
        $this->write([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => self::NEWS_UID,
            'data' => [
                'related_links' => [
                    ['uid' => self::LINK_UID, 'title' => 'Changed link'],
                ],
            ],
        ]);

        $this->assertWorkspaceVersionExists('tx_news_domain_model_link', self::LINK_UID);
        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
    }

    /**
     * An image on the content element: the content element (with no field write of its
     * own) and the news above it are both modified.
     */
    public function testFileReferenceOnContentElementMarksWholeInlineChain(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => [
                'assets' => [
                    ['uid' => self::CONTENT_ASSET_UID, 'alternative' => 'Alt text'],
                    ['uid' => self::CONTENT_SECOND_ASSET_UID],
                    ['uid_local' => 1, 'alternative' => 'New asset'],
                ],
            ],
        ]);

        $this->assertWorkspaceVersionExists('sys_file_reference', self::CONTENT_ASSET_UID);
        $this->assertWorkspaceVersionExists('tt_content', self::CONTENT_UID);
        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
        $this->assertNoWorkspaceVersion('tx_news_domain_model_news', self::OTHER_NEWS_UID);
    }

    /**
     * A file reference written on its own finds its parent through tablenames and
     * uid_foreign, and from there the news above the content element.
     */
    public function testUpdatingFileReferenceDirectlyMarksWholeInlineChain(): void
    {
        $this->write([
            'table' => 'sys_file_reference',
            'action' => 'update',
            'uid' => self::CONTENT_ASSET_UID,
            'data' => ['alternative' => 'Alt text'],
        ]);

        $this->assertWorkspaceVersionExists('tt_content', self::CONTENT_UID);
        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
        $this->assertNoWorkspaceVersion('tx_news_domain_model_news', self::OTHER_NEWS_UID);
    }

    /**
     * tablenames decides the parent table: the same uid_foreign on another table is
     * another parent.
     */
    public function testUpdatingNewsMediaReferenceMarksOnlyThatNews(): void
    {
        $this->write([
            'table' => 'sys_file_reference',
            'action' => 'update',
            'uid' => self::NEWS_MEDIA_UID,
            'data' => ['alternative' => 'Alt text'],
        ]);

        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::OTHER_NEWS_UID);
        $this->assertNoWorkspaceVersion('tx_news_domain_model_news', self::NEWS_UID);
        $this->assertNoWorkspaceVersion('tt_content', self::CONTENT_UID);
    }

    /**
     * A reference from a FlexForm field names no TCA column in fieldname, tablenames
     * and uid_foreign still identify the parent.
     */
    public function testUpdatingFileReferenceOfTableWithoutFileFieldsMarksItsParent(): void
    {
        $this->write([
            'table' => 'sys_file_reference',
            'action' => 'update',
            'uid' => self::LINK_FLEXFORM_FILE_UID,
            'data' => ['alternative' => 'Alt text'],
        ]);

        $this->assertWorkspaceVersionExists('tx_news_domain_model_link', self::LINK_UID);
        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
    }

    /**
     * Relations may share a child table through different foreign field / table field
     * pairs. A second pair (registered here at runtime, last in TCA) must not replace the
     * tablenames + uid_foreign pair of file references.
     */
    public function testAnotherSharedRelationOnTheSameChildTableKeepsFileReferenceParents(): void
    {
        $GLOBALS['TCA']['tx_test_other_owner'] = [
            'ctrl' => [],
            'columns' => [
                'files' => [
                    'config' => [
                        'type' => 'inline',
                        'foreign_table' => 'sys_file_reference',
                        'foreign_field' => 'uid_local',
                        'foreign_table_field' => 'fieldname',
                    ],
                ],
            ],
        ];
        try {
            $this->write([
                'table' => 'sys_file_reference',
                'action' => 'update',
                'uid' => self::CONTENT_ASSET_UID,
                'data' => ['alternative' => 'Alt text'],
            ]);
        } finally {
            unset($GLOBALS['TCA']['tx_test_other_owner']);
        }

        $this->assertWorkspaceVersionExists('tt_content', self::CONTENT_UID);
        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
    }

    public function testUpdatingFlexFormFileReferenceMarksItsParent(): void
    {
        $this->write([
            'table' => 'sys_file_reference',
            'action' => 'update',
            'uid' => self::CONTENT_FLEXFORM_FILE_UID,
            'data' => ['alternative' => 'Alt text'],
        ]);

        $this->assertWorkspaceVersionExists('tt_content', self::CONTENT_UID);
        $this->assertWorkspaceVersionExists('tx_news_domain_model_news', self::NEWS_UID);
    }

    /**
     * A news record created in the workspace is listed there already, nothing to version.
     */
    public function testContentElementOfNewNewsDoesNotVersionTheNewRecord(): void
    {
        $newsUid = $this->write([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'data' => ['pid' => 1, 'title' => 'New news'],
        ])['uid'];

        $this->write([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => 1,
                'CType' => 'text',
                'header' => 'New content',
                'tx_news_related_news' => $newsUid,
            ],
        ]);

        $this->assertNoWorkspaceVersion('tx_news_domain_model_news', (int)$newsUid);
        $this->assertSame('New news', $this->readNews((int)$newsUid)['title']);
    }

    public function testPublishingAppliesTheChildChangeAndKeepsTheNewsUnchanged(): void
    {
        $this->write([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::CONTENT_UID,
            'data' => ['header' => 'Changed header'],
        ]);

        $workspaceId = (int)$GLOBALS['BE_USER']->workspace;
        $cmd = GeneralUtility::makeInstance(WorkspaceService::class)->getCmdArrayForPublishWS($workspaceId);
        $this->assertArrayHasKey('tx_news_domain_model_news', $cmd, 'The news must be part of the workspace');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();
        $this->assertEmpty($dataHandler->errorLog, 'Publishing failed: ' . json_encode($dataHandler->errorLog));

        $news = $this->getLiveRow('tx_news_domain_model_news', self::NEWS_UID);
        $this->assertSame('Live news', $news['title']);
        $content = $this->getLiveRow('tt_content', self::CONTENT_UID);
        $this->assertSame('Changed header', $content['header']);
        $this->assertSame(self::NEWS_UID, (int)$content['tx_news_related_news']);
    }

    private function write(array $params): array
    {
        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertGreaterThan(0, (int)$GLOBALS['BE_USER']->workspace, 'WriteTable must operate in a workspace');
        return json_decode($result->content[0]->text, true);
    }

    private function readNews(int $uid): array
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $uid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return json_decode($result->content[0]->text, true)['records'][0];
    }

    private function assertWorkspaceVersionExists(string $table, int $liveUid): void
    {
        $this->assertCount(1, $this->getWorkspaceVersions($table, $liveUid), "$table:$liveUid must have a workspace version");
    }

    private function assertNoWorkspaceVersion(string $table, int $liveUid): void
    {
        $this->assertCount(0, $this->getWorkspaceVersions($table, $liveUid), "$table:$liveUid must not have a workspace version");
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
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter((int)$GLOBALS['BE_USER']->workspace))
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function getLiveRows(string $table): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('t3ver_wsid', 0))
            ->orderBy('uid')
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
