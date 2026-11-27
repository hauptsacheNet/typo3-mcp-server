<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Counter column and sys_refindex of the parent after embedded children are linked.
 */
class EmbeddedRelationBookkeepingTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_nested_files',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->setUpBackendUser(1);
    }

    public function testCreateCountsAndIndexesFileReferences(): void
    {
        $uid = $this->createContent(['assets' => [['uid_local' => 1], ['uid_local' => 2]]]);

        $this->assertSame(2, $this->fetchCounter('tt_content', $uid, 'assets'));
        $this->assertSame(2, $this->countRefindexRows('tt_content', $uid, 'assets', 'sys_file_reference'));
    }

    public function testCreateCountsAndIndexesNestedFileReferences(): void
    {
        $uid = $this->createContent(['tx_testnestedfiles_items' => [
            ['title' => 'First item', 'file' => [['uid_local' => 1]]],
            ['title' => 'Second item', 'file' => [['uid_local' => 2]]],
        ]]);

        $this->assertSame(2, $this->fetchCounter('tt_content', $uid, 'tx_testnestedfiles_items'));
        $this->assertSame(2, $this->countRefindexRows('tt_content', $uid, 'tx_testnestedfiles_items', 'tx_testnestedfiles_item'));

        foreach ($this->fetchItemUids($uid) as $itemUid) {
            $this->assertSame(1, $this->fetchCounter('tx_testnestedfiles_item', $itemUid, 'file'));
            $this->assertSame(1, $this->countRefindexRows('tx_testnestedfiles_item', $itemUid, 'file', 'sys_file_reference'));
        }
    }

    public function testUpdateRecountsAndReindexesReplacedFileReferences(): void
    {
        $uid = $this->createContent(['assets' => [['uid_local' => 1]]]);

        $this->updateContent($uid, ['assets' => [['uid_local' => 2], ['uid_local' => 3]]]);

        $this->assertSame(2, $this->fetchCounter('tt_content', $uid, 'assets'));
        $this->assertSame(2, $this->countRefindexRows('tt_content', $uid, 'assets', 'sys_file_reference'));
    }

    public function testPublishingTheParentTakesItsFileReferencesLive(): void
    {
        $uid = $this->createContent(['assets' => [['uid_local' => 1]]]);

        $this->publish('tt_content', $uid);

        $references = $this->fetchReferences('tt_content', $uid, 'assets');
        $this->assertCount(1, $references);
        $this->assertSame(0, (int)$references[0]['t3ver_wsid'], 'File reference must be published with its parent');
    }

    public function testUpdateOfPublishedRecordBooksTheVersionAndLeavesLiveUntouched(): void
    {
        $uid = $this->createContent(['assets' => [['uid_local' => 1]]]);
        $this->publish('tt_content', $uid);

        $this->updateContent($uid, ['header' => 'Changed', 'assets' => [['uid_local' => 2], ['uid_local' => 3]]]);

        $this->assertSame(1, $this->fetchCounter('tt_content', $uid, 'assets'), 'Live row must not be written');
        $versionUid = $this->fetchVersionUid('tt_content', $uid);
        $this->assertSame(2, $this->fetchCounter('tt_content', $versionUid, 'assets'));
        // The two new references plus the delete placeholder of the replaced one, which has to be published too.
        $this->assertSame(3, $this->countRefindexRows('tt_content', $versionUid, 'assets', 'sys_file_reference'));

        $this->publish('tt_content', $uid);

        $liveReferences = array_filter(
            $this->fetchReferences('tt_content', $uid, 'assets'),
            static fn(array $reference): bool => (int)$reference['t3ver_wsid'] === 0
        );
        $this->assertCount(2, $liveReferences, 'New file references must be published with the parent version');
    }

    public function testUpdateOfPublishedNestedItemBooksTheItemVersionAndLeavesLiveUntouched(): void
    {
        $uid = $this->createContent(['tx_testnestedfiles_items' => [
            ['title' => 'Item', 'file' => [['uid_local' => 1]]],
        ]]);
        $this->publish('tt_content', $uid);
        [$itemUid] = $this->fetchItemUids($uid, 1);

        $this->updateContent($uid, ['tx_testnestedfiles_items' => [
            ['uid' => $itemUid, 'title' => 'Item', 'file' => [['uid_local' => 2]]],
        ]]);

        $this->assertSame(1, $this->fetchCounter('tx_testnestedfiles_item', $itemUid, 'file'), 'Live item must not be written');
        $itemVersionUid = $this->fetchVersionUid('tx_testnestedfiles_item', $itemUid);
        $this->assertSame(1, $this->fetchCounter('tx_testnestedfiles_item', $itemVersionUid, 'file'));
        // The new reference plus the delete placeholder of the replaced one.
        $this->assertSame(2, $this->countRefindexRows('tx_testnestedfiles_item', $itemVersionUid, 'file', 'sys_file_reference'));
    }

    private function createContent(array $relations): int
    {
        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => ['CType' => 'textmedia', 'header' => 'Bookkeeping'] + $relations,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return (int)json_decode($result->content[0]->text, true)['uid'];
    }

    private function updateContent(int $uid, array $data): void
    {
        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $uid,
            'data' => $data,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * Publishes the workspace state of a record: a new record itself, or the version of a live one.
     */
    private function publish(string $table, int $uid): void
    {
        $workspace = (int)$GLOBALS['BE_USER']->workspace;
        $this->assertGreaterThan(0, $workspace, 'The tool must have written into a workspace');
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect($workspace));

        $swapWith = (int)$this->fetchRow($table, $uid)['t3ver_wsid'] === $workspace ? $uid : $this->fetchVersionUid($table, $uid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['version' => ['action' => 'publish', 'swapWith' => $swapWith]]]]);
        $dataHandler->process_cmdmap();
        $this->assertSame([], $dataHandler->errorLog);
    }

    private function fetchVersionUid(string $table, int $liveUid): int
    {
        $version = BackendUtility::getWorkspaceVersionOfRecord((int)$GLOBALS['BE_USER']->workspace, $table, $liveUid, 'uid');
        $this->assertIsArray($version, 'A workspace version must exist');

        return (int)$version['uid'];
    }

    private function fetchCounter(string $table, int $uid, string $field): int
    {
        return (int)$this->fetchRow($table, $uid)[$field];
    }

    private function fetchRow(string $table, int $uid): array
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)
            ->select(['*'], $table, ['uid' => $uid])
            ->fetchAssociative();
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * Filtered by workspace, the way the workspace dependency resolver reads sys_refindex.
     */
    private function countRefindexRows(string $table, int $uid, string $field, string $refTable): int
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_refindex')
            ->count('*', 'sys_refindex', [
                'tablename' => $table,
                'recuid' => $uid,
                'field' => $field,
                'ref_table' => $refTable,
                'workspace' => (int)$GLOBALS['BE_USER']->workspace,
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchReferences(string $table, int $uid, string $field): array
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference')
            ->select(['uid', 't3ver_wsid'], 'sys_file_reference', ['tablenames' => $table, 'uid_foreign' => $uid, 'fieldname' => $field, 'deleted' => 0])
            ->fetchAllAssociative();
    }

    /**
     * @return list<int>
     */
    private function fetchItemUids(int $contentUid, int $expected = 2): array
    {
        $uids = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_testnestedfiles_item')
            ->select(['uid'], 'tx_testnestedfiles_item', ['tt_content_items' => $contentUid, 'deleted' => 0, 't3ver_oid' => 0])
            ->fetchFirstColumn();
        $this->assertCount($expected, $uids);

        return array_map('intval', $uids);
    }
}
