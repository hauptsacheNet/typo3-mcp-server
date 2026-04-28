<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Gridelements container children carry colPos = -1. That value is a Gridelements magic
 * value and is not declared in TCA select items, so the default TCA validator rejects it.
 * WriteTableTool recognises the container-child case (via tx_gridelements_container) and
 * bypasses the select validation; these tests exercise that behaviour.
 *
 * The real GridElementsTeam/gridelements extension is not loaded in tests — instead the
 * fixture extension at Tests/Functional/Fixtures/Extensions/gridelements_stub adds just
 * the two columns the MCP server inspects (tx_gridelements_container, tx_gridelements_columns).
 */
class GridelementsContainerChildTest extends FunctionalTestCase
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
        // Register fixture extension via absolute path so that its TCA overrides are applied
        // and the matching columns are created in tt_content. The path cannot live in the
        // class-property default (no __DIR__ there), so it is injected here.
        $this->testExtensionsToLoad[] = __DIR__ . '/../../Fixtures/Extensions/gridelements_stub';

        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * The minimal happy path: a parent "container" record exists on a page and a child
     * record points at it via tx_gridelements_container. colPos is not given by the
     * caller — the MCP should fill in -1 automatically.
     */
    public function testContainerChildCreatedWithoutColPosAutoFillsMinusOne(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $containerResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Container placeholder',
                'colPos' => 0,
            ],
        ]);
        $this->assertFalse($containerResult->isError, $this->errorText($containerResult));
        $containerUid = (int)json_decode($containerResult->content[0]->text, true)['uid'];

        $childResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Child in column 0',
                'bodytext' => 'text inside the container',
                'tx_gridelements_container' => $containerUid,
                'tx_gridelements_columns' => 0,
            ],
        ]);
        $this->assertFalse($childResult->isError, $this->errorText($childResult));
        $childUid = (int)json_decode($childResult->content[0]->text, true)['uid'];

        $this->assertSame(-1, $this->fetchColPos($childUid));
        $this->assertSame($containerUid, $this->fetchGridContainer($childUid));
    }

    /**
     * Caller explicitly sets colPos = -1 together with the container linkage. Both are
     * valid, so the write must succeed without the TCA select validator rejecting -1.
     */
    public function testExplicitMinusOneColPosWithContainerLinkageIsAccepted(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $containerResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Container', 'colPos' => 0],
        ]);
        $this->assertFalse($containerResult->isError, $this->errorText($containerResult));
        $containerUid = (int)json_decode($containerResult->content[0]->text, true)['uid'];

        $childResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Child',
                'colPos' => -1,
                'tx_gridelements_container' => $containerUid,
                'tx_gridelements_columns' => 1,
            ],
        ]);
        $this->assertFalse($childResult->isError, $this->errorText($childResult));
        $childUid = (int)json_decode($childResult->content[0]->text, true)['uid'];

        $this->assertSame(-1, $this->fetchColPos($childUid));
    }

    /**
     * colPos = -1 without any container linkage is meaningless and must still be rejected —
     * the bypass only kicks in for genuine container children. Guarding this case keeps the
     * TCA validator honest for normal records.
     */
    public function testOrphanColPosMinusOneWithoutContainerIsRejected(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Orphan',
                'colPos' => -1,
            ],
        ]);

        $this->assertTrue($result->isError, 'colPos=-1 must be rejected when no container linkage is provided');
    }

    /**
     * When an existing record already lives inside a container, the caller must be able to
     * touch unrelated fields (e.g. bodytext) via update without having to repeat the
     * container linkage or the magic colPos value.
     */
    public function testUpdateOfExistingChildWithoutContainerInDataKeepsMinusOne(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $containerResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Container', 'colPos' => 0],
        ]);
        $containerUid = (int)json_decode($containerResult->content[0]->text, true)['uid'];

        $childResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Child',
                'bodytext' => 'initial',
                'tx_gridelements_container' => $containerUid,
                'tx_gridelements_columns' => 0,
            ],
        ]);
        $childUid = (int)json_decode($childResult->content[0]->text, true)['uid'];
        $this->assertSame(-1, $this->fetchColPos($childUid));

        $updateResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $childUid,
            'data' => [
                'bodytext' => 'edited text',
            ],
        ]);
        $this->assertFalse($updateResult->isError, $this->errorText($updateResult));

        $this->assertSame(-1, $this->fetchColPos($childUid));
        $this->assertSame($containerUid, $this->fetchGridContainer($childUid));
    }

    /**
     * Moving a record INTO a container via update: caller sets tx_gridelements_container
     * to a non-zero value, MCP must auto-fix colPos from 0 to -1 so the record no longer
     * double-renders at the page level.
     */
    public function testUpdateThatAttachesRecordToContainerForcesColPosMinusOne(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $containerResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Container', 'colPos' => 0],
        ]);
        $containerUid = (int)json_decode($containerResult->content[0]->text, true)['uid'];

        $topLevelResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Top-level element',
                'colPos' => 0,
            ],
        ]);
        $topLevelUid = (int)json_decode($topLevelResult->content[0]->text, true)['uid'];
        $this->assertSame(0, $this->fetchColPos($topLevelUid));

        $moveResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $topLevelUid,
            'data' => [
                'tx_gridelements_container' => $containerUid,
                'tx_gridelements_columns' => 0,
            ],
        ]);
        $this->assertFalse($moveResult->isError, $this->errorText($moveResult));

        $this->assertSame(-1, $this->fetchColPos($topLevelUid));
    }

    /**
     * Moving a record OUT of a container: caller clears tx_gridelements_container to 0.
     * MCP must NOT force colPos = -1 in this case — the record goes back to being a
     * top-level element, and the caller is free to assign a real page colPos.
     */
    public function testUpdateThatDetachesRecordFromContainerAllowsNormalColPos(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $containerResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Container', 'colPos' => 0],
        ]);
        $containerUid = (int)json_decode($containerResult->content[0]->text, true)['uid'];

        $childResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Child',
                'tx_gridelements_container' => $containerUid,
                'tx_gridelements_columns' => 0,
            ],
        ]);
        $childUid = (int)json_decode($childResult->content[0]->text, true)['uid'];
        $this->assertSame(-1, $this->fetchColPos($childUid));

        $detachResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $childUid,
            'data' => [
                'tx_gridelements_container' => 0,
                'colPos' => 0,
            ],
        ]);
        $this->assertFalse($detachResult->isError, $this->errorText($detachResult));

        $this->assertSame(0, $this->fetchColPos($childUid));
        $this->assertSame(0, $this->fetchGridContainer($childUid));
    }

    private function fetchColPos(int $uid): int
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->select(['colPos'], 'tt_content', ['uid' => $uid])
            ->fetchAssociative();
        $this->assertIsArray($row, "tt_content record {$uid} not found");
        return (int)$row['colPos'];
    }

    private function fetchGridContainer(int $uid): int
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->select(['tx_gridelements_container'], 'tt_content', ['uid' => $uid])
            ->fetchAssociative();
        $this->assertIsArray($row, "tt_content record {$uid} not found");
        return (int)$row['tx_gridelements_container'];
    }

    private function errorText($result): string
    {
        return json_encode($result->jsonSerialize());
    }
}
