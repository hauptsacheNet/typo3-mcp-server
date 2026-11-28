<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A group field without MM table stores its relations as a comma separated list in the
 * column (tt_content.records of the "Insert records" element). ReadTable returns them as
 * a list of uids, the same notation WriteTable takes.
 */
class GroupRelationReadTest extends FunctionalTestCase
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
        $this->setUpBackendUser(1);
    }

    public function testReadsLiveGroupValueAsUidList(): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        foreach ([501 => 'First', 502 => 'Second'] as $uid => $header) {
            $connection->insert('tt_content', ['uid' => $uid, 'pid' => 1, 'CType' => 'text', 'header' => $header]);
        }
        $connection->insert('tt_content', [
            'uid' => 503,
            'pid' => 1,
            'CType' => 'shortcut',
            'header' => 'Shortcut',
            'records' => 'tt_content_502,501',
        ]);
        $connection->insert('tt_content', [
            'uid' => 504,
            'pid' => 1,
            'CType' => 'shortcut',
            'header' => 'Empty shortcut',
            'records' => '',
        ]);

        $records = $this->readRecords([503, 504]);
        $this->assertSame([502, 501], $records[503]['records']);
        $this->assertSame([], $records[504]['records']);
    }

    /**
     * A group with "multiple" may relate the same record twice on purpose; without it
     * the record is listed once.
     */
    public function testRepeatedItemsAreKeptOnlyForMultipleGroups(): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        foreach ([501 => 'First', 502 => 'Second'] as $uid => $header) {
            $connection->insert('tt_content', ['uid' => $uid, 'pid' => 1, 'CType' => 'text', 'header' => $header]);
        }
        $connection->insert('tt_content', [
            'uid' => 503,
            'pid' => 1,
            'CType' => 'shortcut',
            'header' => 'Shortcut',
            'records' => '501,502,501',
        ]);

        $this->assertSame([501, 502], $this->readRecords([503])[503]['records']);

        $GLOBALS['TCA']['tt_content']['columns']['records']['config']['multiple'] = true;
        $this->assertSame([501, 502, 501], $this->readRecords([503])[503]['records']);
    }

    public function testWrittenGroupValueReadsBackInWorkspace(): void
    {
        $writeTool = new WriteTableTool();
        $uids = [];
        foreach (['First', 'Second'] as $header) {
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => 1,
                'data' => ['CType' => 'text', 'header' => $header],
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $uids[] = (int)json_decode($result->content[0]->text, true)['uid'];
        }

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => ['CType' => 'shortcut', 'header' => 'Shortcut', 'records' => [$uids[1], $uids[0]]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $shortcutUid = (int)json_decode($result->content[0]->text, true)['uid'];
        $this->assertGreaterThan(0, (int)$GLOBALS['BE_USER']->workspace);

        $this->assertSame([$uids[1], $uids[0]], $this->readRecords([$shortcutUid])[$shortcutUid]['records']);
    }

    /**
     * @param int[] $uids
     * @return array<int, array<string, mixed>> Records by uid
     */
    private function readRecords(array $uids): array
    {
        $result = (new ReadTableTool())->execute(['table' => 'tt_content', 'uid' => $uids]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $records = [];
        foreach (json_decode($result->content[0]->text, true)['records'] as $record) {
            $records[(int)$record['uid']] = $record;
        }
        $this->assertCount(count($uids), $records);

        return $records;
    }
}
