<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP;

use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Mcp\Types\CallToolRequestParams;
use Mcp\Types\CallToolResult;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;

/**
 * `mcp:server` serves a whole client session from one PHP process. Each tool
 * call must see the time it runs at, not the time the process started, or
 * DataHandler stamps every write with the process start time.
 */
class ToolCallRequestTimeTest extends AbstractFunctionalTest
{
    /** @var callable(CallToolRequestParams): CallToolResult */
    private $callTool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->callTool = $this->get(McpServerFactory::class)->createServer()->getHandlers()['tools/call'];

        // Request time as TYPO3 left it in a process that started two hours ago
        $this->simulateProcessStartedAt(time() - 7200);
    }

    #[Test]
    public function toolCallsWriteWithTheCurrentTimeInsteadOfTheProcessStart(): void
    {
        $before = time();
        $created = $this->call('WriteTable', [
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'Written long after process start',
                'colPos' => 0,
            ],
        ]);
        $uid = (int)json_decode($created->content[0]->text, true)['uid'];
        $this->call('WriteTable', [
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['header' => 'Updated long after process start'],
        ]);
        $after = time();

        $record = BackendUtility::getRecord('tt_content', $uid, 'crdate,tstamp');
        $this->assertTimestampsBetween($before, $after, [$record['crdate'], $record['tstamp']]);
        $this->assertTimestampsBetween($before, $after, $this->getLogTimestamps('tt_content', $uid));
    }

    #[Test]
    public function toolCallRefreshesGlobalsAndDateAspect(): void
    {
        $before = time();
        $this->call('ListTables', []);
        $after = time();

        $now = $GLOBALS['EXEC_TIME'];
        $this->assertTimestampsBetween($before, $after, [$now]);

        // Access times are rounded down to the minute, as in TYPO3's bootstrap
        $minute = $now - $now % 60;
        $this->assertSame(
            [
                'ACCESS_TIME' => $minute,
                'SIM_EXEC_TIME' => $now,
                'SIM_ACCESS_TIME' => $minute,
                'date' => $now,
            ],
            [
                'ACCESS_TIME' => $GLOBALS['ACCESS_TIME'],
                'SIM_EXEC_TIME' => $GLOBALS['SIM_EXEC_TIME'],
                'SIM_ACCESS_TIME' => $GLOBALS['SIM_ACCESS_TIME'],
                'date' => $this->get(Context::class)->getPropertyFromAspect('date', 'timestamp'),
            ]
        );
    }

    private function simulateProcessStartedAt(int $timestamp): void
    {
        $GLOBALS['EXEC_TIME'] = $timestamp;
        $GLOBALS['ACCESS_TIME'] = $timestamp - $timestamp % 60;
        $GLOBALS['SIM_EXEC_TIME'] = $GLOBALS['EXEC_TIME'];
        $GLOBALS['SIM_ACCESS_TIME'] = $GLOBALS['ACCESS_TIME'];
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . $timestamp)));
    }

    private function call(string $tool, array $arguments): CallToolResult
    {
        $result = ($this->callTool)(new CallToolRequestParams($tool, $arguments));
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return $result;
    }

    /**
     * @param list<int> $timestamps
     */
    private function assertTimestampsBetween(int $from, int $to, array $timestamps): void
    {
        $this->assertNotSame([], $timestamps);
        foreach ($timestamps as $timestamp) {
            $this->assertGreaterThanOrEqual($from, $timestamp);
            $this->assertLessThanOrEqual($to, $timestamp);
        }
    }

    /**
     * @return list<int>
     */
    private function getLogTimestamps(string $table, int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');

        return array_map('intval', $queryBuilder
            ->select('tstamp')
            ->from('sys_log')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($uid))
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn());
    }
}
