<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\PublishRecordTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PublishRecordToolTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;
    private PublishRecordTool $publishTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->publishTool = GeneralUtility::makeInstance(PublishRecordTool::class);
    }

    public function testPublishesNewWorkspaceRecord(): void
    {
        $pid = $this->getRootPageUid();

        $createResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => $pid,
                'CType' => 'textmedia',
                'header' => 'To be published',
                'colPos' => 0,
            ],
        ]);
        $this->assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));
        $uid = (int)$this->extractJsonFromResult($createResult)['uid'];

        // Before publish: live workspace must NOT show the record.
        $this->assertSame(
            0,
            $this->countLiveRecords('tt_content', $uid),
            'New workspace record should not be visible in live workspace yet'
        );

        $publishResult = $this->publishTool->execute(['table' => 'tt_content', 'uid' => $uid]);
        $this->assertFalse($publishResult->isError, json_encode($publishResult->jsonSerialize()));
        $payload = $this->extractJsonFromResult($publishResult);
        $this->assertTrue((bool)$payload['published'], 'Publish should succeed: ' . json_encode($payload));

        // After publish: record must be a live record (t3ver_wsid=0, t3ver_state=0).
        $liveRow = $this->fetchLiveRow('tt_content', $uid);
        $this->assertNotNull($liveRow, 'Published record should be queryable as live');
        $this->assertSame(0, (int)$liveRow['t3ver_wsid']);
        $this->assertSame(0, (int)$liveRow['t3ver_state']);
        $this->assertSame('To be published', $liveRow['header']);
    }

    public function testPublishesModifiedRecord(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $connection->insert('tt_content', [
            'pid' => $this->getRootPageUid(),
            'CType' => 'textmedia',
            'header' => 'Initial live header',
            'colPos' => 0,
        ]);
        $liveUid = (int)$connection->lastInsertId();

        $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $liveUid,
            'data' => ['header' => 'Edited via MCP'],
        ]);

        // Sanity: live row should still show the original until publish.
        $liveBefore = $this->fetchLiveRow('tt_content', $liveUid);
        $this->assertSame('Initial live header', $liveBefore['header'] ?? null);

        $publishResult = $this->publishTool->execute(['table' => 'tt_content', 'uid' => $liveUid]);
        $this->assertFalse($publishResult->isError, json_encode($publishResult->jsonSerialize()));
        $payload = $this->extractJsonFromResult($publishResult);
        $this->assertTrue((bool)$payload['published'], 'Publish should succeed: ' . json_encode($payload));

        $liveAfter = $this->fetchLiveRow('tt_content', $liveUid);
        $this->assertNotNull($liveAfter);
        $this->assertSame('Edited via MCP', $liveAfter['header']);
        $this->assertSame(0, (int)$liveAfter['t3ver_wsid']);
    }

    public function testReportsNoPendingChange(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $connection->insert('tt_content', [
            'pid' => $this->getRootPageUid(),
            'CType' => 'textmedia',
            'header' => 'Untouched live record',
            'colPos' => 0,
        ]);
        $liveUid = (int)$connection->lastInsertId();

        $publishResult = $this->publishTool->execute(['table' => 'tt_content', 'uid' => $liveUid]);
        $this->assertFalse($publishResult->isError, json_encode($publishResult->jsonSerialize()));
        $payload = $this->extractJsonFromResult($publishResult);

        $this->assertFalse((bool)$payload['published']);
        $this->assertStringContainsString('No pending workspace change', $payload['error'] ?? '');
    }

    private function countLiveRecords(string $table, int $uid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        return (int)$qb
            ->count('uid')
            ->from($table)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid)),
                $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter(0))
            )
            ->executeQuery()
            ->fetchOne();
    }

    private function fetchLiveRow(string $table, int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $row = $qb
            ->select('*')
            ->from($table)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid)),
                $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter(0))
            )
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: null;
    }
}
