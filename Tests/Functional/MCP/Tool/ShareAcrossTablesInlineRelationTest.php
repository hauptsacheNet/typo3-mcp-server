<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers embedded inline relations that use TCA's `foreign_table_field` to record
 * the owning parent table (friendsoftypo3/content-blocks generates exactly this
 * shape for a "Collection" field configured with `shareAcrossTables: true`).
 *
 * This is a distinct code path from `foreign_match_fields`, which is the only
 * source WriteTableTool::processEmbeddedInlineRelations() reads today.
 */
class ShareAcrossTablesInlineRelationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_share_across_tables',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    public function testCreatingChildRecordsSetsTheOwningParentTable(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pageUid = json_decode($result->content[0]->text, true)['uid'];

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'header' => 'Accordion-like element',
                'CType' => 'text',
                'tx_testsat_items' => [
                    ['title' => 'First'],
                    ['title' => 'Second'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $contentUid = json_decode($result->content[0]->text, true)['uid'];

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_testsat_item');
        $queryBuilder->getRestrictions()->removeAll();
        $children = $queryBuilder
            ->select('uid', 'title', 'tablenames', 'fieldname', 'foreign_table_parent_uid')
            ->from('tx_testsat_item')
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        $this->assertCount(2, $children, 'Both child records should have been written.');

        foreach ($children as $child) {
            $this->assertSame(
                'tt_content',
                $child['tablenames'],
                sprintf(
                    'Child "%s" (uid %d) is missing tablenames="tt_content" — TYPO3 cannot resolve '
                    . 'the relation back to its parent without it, so the field renders empty in the '
                    . 'backend even though the row exists in the database.',
                    $child['title'],
                    $child['uid']
                )
            );
            $this->assertSame('tx_testsat_items', $child['fieldname']);
            $this->assertSame($contentUid, $child['foreign_table_parent_uid']);
        }

        // With tablenames correctly set, TYPO3's own relation resolution (as used by
        // ReadTableTool) must be able to find the children again.
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertArrayHasKey('tx_testsat_items', $record);
        $this->assertCount(2, $record['tx_testsat_items']);
    }
}
