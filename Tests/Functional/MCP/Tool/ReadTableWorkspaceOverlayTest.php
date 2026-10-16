<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Verify that ReadTable returns workspace-overlaid values for records that have
 * been modified in the current workspace, instead of leaking the live values.
 */
class ReadTableWorkspaceOverlayTest extends AbstractFunctionalTest
{
    private ReadTableTool $readTool;
    private WriteTableTool $writeTool;
    private WorkspaceContextService $workspaceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->readTool = new ReadTableTool();
        $this->writeTool = new WriteTableTool();
        $this->workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
    }

    public function testReadReturnsWorkspaceValueAfterScalarFieldUpdate(): void
    {
        $liveUid = 100;

        // Sanity-check the live state.
        $live = $this->readSingle('tt_content', $liveUid, ['CType', 'header']);
        $this->assertSame('textmedia', $live['CType']);
        $this->assertSame('Welcome Header', $live['header']);

        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $this->assertGreaterThan(0, $GLOBALS['BE_USER']->workspace);

        $writeResult = $this->writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $liveUid,
            'data' => [
                'CType' => 'text',
                'header' => 'Patched in workspace',
            ],
        ]);
        $this->assertFalse($writeResult->isError, json_encode($writeResult->jsonSerialize()));

        $workspaceView = $this->readSingle('tt_content', $liveUid, ['CType', 'header']);

        $this->assertSame(
            'text',
            $workspaceView['CType'],
            'ReadTable must report the workspace-overlaid CType, not the live value.'
        );
        $this->assertSame(
            'Patched in workspace',
            $workspaceView['header'],
            'ReadTable must report the workspace-overlaid header, not the live value.'
        );
    }

    public function testReadByPidReturnsSingleRowPerLogicalRecordInWorkspace(): void
    {
        $liveUid = 100;

        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $writeResult = $this->writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $liveUid,
            'data' => ['header' => 'WS Header'],
        ]);
        $this->assertFalse($writeResult->isError, json_encode($writeResult->jsonSerialize()));

        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'fields' => ['uid', 'header'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode($result->content[0]->text, true);

        $rowsForLive = array_values(array_filter(
            $payload['records'],
            static fn(array $record): bool => (int)$record['uid'] === $liveUid
        ));

        $this->assertCount(
            1,
            $rowsForLive,
            'Workspace-aware ReadTable must collapse live + workspace rows into a single logical record.'
        );
        $this->assertSame('WS Header', $rowsForLive[0]['header']);
    }

    public function testReadReturnsLiveValueWhenNoWorkspaceVersionExists(): void
    {
        $liveUid = 101;

        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $view = $this->readSingle('tt_content', $liveUid, ['CType', 'header']);
        $this->assertSame('textmedia', $view['CType']);
        $this->assertSame('About Section', $view['header']);
    }

    public function testReadHidesRecordsDeletedInCurrentWorkspace(): void
    {
        $liveUid = 100;

        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $deleteResult = $this->writeTool->execute([
            'table' => 'tt_content',
            'action' => 'delete',
            'uid' => $liveUid,
        ]);
        $this->assertFalse($deleteResult->isError, json_encode($deleteResult->jsonSerialize()));

        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'uid' => $liveUid,
            'fields' => ['uid', 'header'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode($result->content[0]->text, true);

        $this->assertSame(
            [],
            $payload['records'],
            'A record marked for deletion in the current workspace must not appear in ReadTable output.'
        );
    }

    /**
     * Reordering live records in a workspace stages move pointers (t3ver_state=4).
     * A pid listing must return each record once, in the workspace order, with a
     * total that matches the returned rows.
     */
    public function testReadByPidReturnsLiveRecordsReorderedInWorkspaceOnceInNewOrder(): void
    {
        // Live order on page 1: 100 (256), 101 (512), 104 (768)
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $writeResult = $this->writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => 104,
            'position' => 'top',
        ]);
        $this->assertFalse($writeResult->isError, json_encode($writeResult->jsonSerialize()));

        $payload = $this->readByPid(1);
        $this->assertSame([104, 100, 101], array_map('intval', array_column($payload['records'], 'uid')));
        $this->assertSame(3, $payload['total']);
    }

    /**
     * A live record moved to another page in the workspace must disappear from the
     * old page and show up once, under its live uid, on the new page.
     */
    public function testReadByPidFollowsLiveRecordMovedToAnotherPageInWorkspace(): void
    {
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $writeResult = $this->writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => 101,
            'data' => ['pid' => 2],
        ]);
        $this->assertFalse($writeResult->isError, json_encode($writeResult->jsonSerialize()));

        $sourcePage = $this->readByPid(1);
        $this->assertSame([100, 104], array_map('intval', array_column($sourcePage['records'], 'uid')));
        $this->assertSame(2, $sourcePage['total']);

        $targetPage = $this->readByPid(2);
        $targetUids = array_map('intval', array_column($targetPage['records'], 'uid'));
        $this->assertSame(1, count(array_keys($targetUids, 101, true)), 'Moved record must appear exactly once: ' . json_encode($targetUids));
        $this->assertSame(count($targetUids), $targetPage['total']);

        $moved = $this->readSingle('tt_content', 101, ['uid', 'pid', 'header']);
        $this->assertSame(101, (int)$moved['uid']);
        $this->assertSame(2, (int)$moved['pid']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readByPid(int $pid): array
    {
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'pid' => $pid,
            'fields' => ['uid', 'header'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return json_decode($result->content[0]->text, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function readSingle(string $table, int $uid, array $fields): array
    {
        $result = $this->readTool->execute([
            'table' => $table,
            'uid' => $uid,
            'fields' => $fields,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode($result->content[0]->text, true);
        $this->assertCount(1, $payload['records'], 'Expected exactly one record for uid ' . $uid);
        return $payload['records'][0];
    }
}
