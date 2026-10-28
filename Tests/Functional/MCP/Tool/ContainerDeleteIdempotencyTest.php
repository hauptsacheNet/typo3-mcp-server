<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers the report that retrying a delete on a record in a workspace produced
 * side effects on unrelated workspace drafts (originally seen with Gridelements
 * containers and their children).
 *
 * The MCP cannot control DataHandler cascade or extension hooks, but it CAN
 * guarantee that retrying a delete is a no-op once a delete placeholder exists.
 * The re-entry into DataHandler's cmdmap on an already-placeholdered record is
 * what triggered the destructive cascade in the original report — making the
 * second call short-circuit removes the trigger entirely.
 */
class ContainerDeleteIdempotencyTest extends FunctionalTestCase
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

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Sibling-draft survival: an unrelated workspace draft staged before the delete
     * must remain intact across both the initial delete and the retry. The original
     * regression saw children drafts disappear when the delete was retried.
     */
    public function testRetryingDeleteDoesNotClobberUnrelatedWorkspaceDrafts(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);

        $targetUid = 100; // tt_content from fixture, live
        $siblingUid = 101; // sibling record on the same page, also live

        // Stage a workspace edit on the sibling — this is what we want to protect.
        $stageSiblingDraft = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $siblingUid,
            'data' => ['bodytext' => 'Sibling edited in workspace'],
        ]);
        $this->assertFalse($stageSiblingDraft->isError, json_encode($stageSiblingDraft->jsonSerialize()));

        $wsid = $workspaceService->getCurrentWorkspace();
        $this->assertGreaterThan(0, $wsid, 'Test must run inside a real workspace');

        $siblingDraftCount = fn(): int => $this->countWorkspaceVersions('tt_content', $siblingUid, $wsid);
        $this->assertSame(1, $siblingDraftCount(), 'Baseline: sibling has exactly one workspace draft');

        // First delete — creates the delete placeholder.
        $firstDelete = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'delete',
            'uid' => $targetUid,
        ]);
        $this->assertFalse($firstDelete->isError, json_encode($firstDelete->jsonSerialize()));
        $this->assertSame(1, $this->countDeletePlaceholders('tt_content', $targetUid, $wsid), 'First delete creates one placeholder');
        $this->assertSame(1, $siblingDraftCount(), 'Sibling draft survives the first delete');

        // Retry — the regression-prone path. A second cmdmap on an already-placeholdered
        // record is what wiped unrelated drafts in the original report.
        $retryDelete = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'delete',
            'uid' => $targetUid,
        ]);
        $this->assertFalse($retryDelete->isError, json_encode($retryDelete->jsonSerialize()));
        $this->assertSame(1, $this->countDeletePlaceholders('tt_content', $targetUid, $wsid), 'Retry must not duplicate the placeholder');
        $this->assertSame(1, $siblingDraftCount(), 'Sibling draft must survive the retried delete');
    }

    private function countWorkspaceVersions(string $table, int $liveUid, int $wsid): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')
            ->from($table)
            ->where(
                $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter($liveUid, ParameterType::INTEGER)),
                $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter($wsid, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();
    }

    private function countDeletePlaceholders(string $table, int $liveUid, int $wsid): int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')
            ->from($table)
            ->where(
                $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter($liveUid, ParameterType::INTEGER)),
                $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter($wsid, ParameterType::INTEGER)),
                $qb->expr()->eq('t3ver_state', $qb->createNamedParameter(2, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();
    }
}
