<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Files that sit on an embedded child record, e.g. a Collection item of a Content Block.
 *
 * extractInlineRelations() only inspects the top level of the written record, so such a
 * field used to reach DataHandler as an array, got cast to the string "Array" and the
 * reference was silently never created.
 */
class NestedFileRelationTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->setUpBackendUser(1);
    }

    public function testCreateAttachesFileToEmbeddedChild(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $pageUid = $this->createPage($writeTool);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => $pageUid,
                'CType' => 'textmedia',
                'header' => 'Element with nested files',
                'tx_testnestedfiles_items' => [
                    ['title' => 'First item', 'file' => [['uid_local' => 1]]],
                    ['title' => 'Second item', 'file' => [['uid_local' => 2]]],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $contentUid = json_decode($result->content[0]->text, true)['uid'];
        $items = $this->fetchItems($contentUid);
        $this->assertCount(2, $items, 'Both collection items are created');

        $itemsByTitle = [];
        foreach ($items as $item) {
            $itemsByTitle[$item['title']] = $item;
        }

        $this->assertFileReference($itemsByTitle['First item']['uid'], 1);
        $this->assertFileReference($itemsByTitle['Second item']['uid'], 2);
    }

    public function testUpdateAttachesFileToExistingEmbeddedChild(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $pageUid = $this->createPage($writeTool);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => $pageUid,
                'CType' => 'textmedia',
                'header' => 'Element without files yet',
                'tx_testnestedfiles_items' => [
                    ['title' => 'Item to be filled'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $contentUid = json_decode($result->content[0]->text, true)['uid'];
        $items = $this->fetchItems($contentUid);
        $this->assertCount(1, $items);
        $itemUid = (int)$items[0]['uid'];

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'tx_testnestedfiles_items' => [
                    ['uid' => $itemUid, 'file' => [['uid_local' => 1]]],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertFileReference($itemUid, 1);
    }

    /**
     * Replacing the file of a child must end up with one reference, not two.
     */
    public function testUpdateReplacesExistingFileOfEmbeddedChild(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $pageUid = $this->createPage($writeTool);

        $contentUid = $this->createElementWithFile($writeTool, $pageUid, 'Element', 'Item', 1);
        $items = $this->fetchItems($contentUid);
        $itemUid = (int)$items[0]['uid'];
        $this->assertFileReference($itemUid, 1);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'tx_testnestedfiles_items' => [
                    ['uid' => $itemUid, 'file' => [['uid_local' => 2]]],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertFileReference($itemUid, 2);
    }

    /**
     * Sending the uid of an existing reference patches it instead of adding a second one.
     */
    public function testUpdatePatchesExistingReferenceByUid(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $pageUid = $this->createPage($writeTool);

        $contentUid = $this->createElementWithFile($writeTool, $pageUid, 'Element', 'Item', 1);
        $itemUid = (int)$this->fetchItems($contentUid)[0]['uid'];
        $referenceUid = $this->fetchReferenceUid($itemUid);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'tx_testnestedfiles_items' => [
                    ['uid' => $itemUid, 'file' => [['uid' => $referenceUid, 'alternative' => 'Patched']]],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertFileReference($itemUid, 1);
        $this->assertSame($referenceUid, $this->fetchReferenceUid($itemUid), 'The reference is patched, not replaced');
    }

    /**
     * A reference of another record must not be claimed through a nested payload.
     */
    public function testForeignReferenceUidIsRejected(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $pageUid = $this->createPage($writeTool);

        $otherContentUid = $this->createElementWithFile($writeTool, $pageUid, 'Other element', 'Other item', 1);
        $otherReferenceUid = $this->fetchReferenceUid((int)$this->fetchItems($otherContentUid)[0]['uid']);

        $contentUid = $this->createElementWithFile($writeTool, $pageUid, 'Element', 'Item', 2);
        $itemUid = $this->findItemUidByTitle($this->fetchItems($contentUid), 'Item');

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'tx_testnestedfiles_items' => [
                    ['uid' => $itemUid, 'file' => [['uid' => $otherReferenceUid]]],
                ],
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not belong to this record', $result->content[0]->text);
    }

    /**
     * A value that is not a list of records is passed on unchanged instead of being
     * swallowed by the relation handling.
     */
    public function testNonListValueIsPassedThrough(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $pageUid = $this->createPage($writeTool);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => $pageUid,
                'CType' => 'textmedia',
                'header' => 'Element with an empty file value',
                'tx_testnestedfiles_items' => [
                    ['title' => 'Item', 'file' => ''],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $contentUid = (int)json_decode($result->content[0]->text, true)['uid'];
        $items = $this->fetchItems($contentUid);
        $this->assertCount(1, $items);
        $this->assertSame(0, $this->countFileReferences((int)$items[0]['uid']));
    }

    /**
     * The child keeps working as before when it carries no file at all.
     */
    public function testChildWithoutFileStaysUntouched(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $pageUid = $this->createPage($writeTool);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => $pageUid,
                'CType' => 'textmedia',
                'header' => 'Element without files',
                'tx_testnestedfiles_items' => [
                    ['title' => 'Plain item'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $contentUid = json_decode($result->content[0]->text, true)['uid'];
        $items = $this->fetchItems($contentUid);
        $this->assertCount(1, $items);
        $this->assertSame('Plain item', $items[0]['title']);
        $this->assertSame(0, $this->countFileReferences((int)$items[0]['uid']));
    }

    /**
     * A malformed entry must be reported instead of being dropped - on update that would
     * delete the reference that is already there.
     */
    public function testScalarEntryIsRejected(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $pageUid = $this->createPage($writeTool);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => $pageUid,
                'CType' => 'textmedia',
                'header' => 'Element with a malformed file entry',
                'tx_testnestedfiles_items' => [
                    ['title' => 'Item', 'file' => [1]],
                ],
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('must be an object', $result->content[0]->text);
    }

    /**
     * The collected links are per write. Two writes through the same tool instance must not
     * see each other's entries.
     */
    public function testSecondWriteOnSameInstanceKeepsLinksApart(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $pageUid = $this->createPage($writeTool);

        $first = $this->createElementWithFile($writeTool, $pageUid, 'First element', 'Item A', 1);
        $second = $this->createElementWithFile($writeTool, $pageUid, 'Second element', 'Item B', 2);

        $items = $this->fetchItems($first);
        $this->assertCount(2, $items, 'Both elements contributed one item');

        $byTitle = [];
        foreach ($items as $item) {
            $byTitle[$item['title']] = $item;
        }

        $this->assertFileReference($byTitle['Item A']['uid'], 1);
        $this->assertFileReference($byTitle['Item B']['uid'], 2);
        $this->assertNotSame($byTitle['Item A']['tt_content_items'], $byTitle['Item B']['tt_content_items']);
    }

    protected function createElementWithFile(
        WriteTableTool $writeTool,
        int $pageUid,
        string $header,
        string $itemTitle,
        int $fileUid
    ): int {
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'data' => [
                'pid' => $pageUid,
                'CType' => 'textmedia',
                'header' => $header,
                'tx_testnestedfiles_items' => [
                    ['title' => $itemTitle, 'file' => [['uid_local' => $fileUid]]],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return (int)json_decode($result->content[0]->text, true)['uid'];
    }

    protected function createPage(WriteTableTool $writeTool): int
    {
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'data' => [
                'pid' => 0,
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return (int)json_decode($result->content[0]->text, true)['uid'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchItems(int $contentUid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_testnestedfiles_item');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'title', 'tt_content_items')
            ->from('tx_testnestedfiles_item')
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        // Asserting the parent pointer here keeps a regression in the child link from
        // showing up as a confusing file reference failure further down.
        foreach ($rows as $row) {
            $this->assertGreaterThan(0, (int)$row['tt_content_items'], 'Item is linked to its parent');
        }

        return $rows;
    }

    /**
     * A reference the frontend can resolve needs uid_local, uid_foreign, tablenames and
     * fieldname - a row missing tablenames exists but renders nothing.
     */
    protected function assertFileReference(int $itemUid, int $expectedFileUid): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid_local', 'uid_foreign', 'tablenames', 'fieldname')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $itemUid),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $this->assertCount(1, $rows, 'Exactly one reference for item ' . $itemUid);
        $this->assertSame($expectedFileUid, (int)$rows[0]['uid_local']);
        $this->assertSame($itemUid, (int)$rows[0]['uid_foreign']);
        $this->assertSame('tx_testnestedfiles_item', $rows[0]['tablenames']);
        $this->assertSame('file', $rows[0]['fieldname']);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    protected function findItemUidByTitle(array $items, string $title): int
    {
        foreach ($items as $item) {
            if ($item['title'] === $title) {
                return (int)$item['uid'];
            }
        }

        $this->fail('No item titled "' . $title . '"');
    }

    protected function fetchReferenceUid(int $itemUid): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->select('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $itemUid),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchOne();
    }

    protected function countFileReferences(int $itemUid): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $itemUid),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchOne();
    }
}
