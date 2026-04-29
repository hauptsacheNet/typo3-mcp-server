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
