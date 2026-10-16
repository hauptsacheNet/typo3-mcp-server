<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Verify that ReadTable works on non-workspace tables that are exposed
 * via additionalReadOnlyTables (e.g. sys_file) even while the backend user
 * is operating inside a workspace.
 *
 * Regression: when the user was in a workspace, ReadTable's uid filter
 * referenced the workspace-only column t3ver_oid via an OR clause. For
 * tables without versioningWS that column does not exist, so MySQL/MariaDB
 * aborted the query with "Unknown column 't3ver_oid'", surfaced to the
 * caller as "Failed to count record" / "Failed to select record".
 *
 * The generated SQL is asserted here — SQLite's "double-quoted identifier
 * falls back to string literal" quirk would otherwise hide the missing
 * column on the functional test database and let the broken SQL execute.
 */
class ReadTableNonWorkspaceTableTest extends AbstractFunctionalTest
{
    private ReadTableTool $readTool;
    private WorkspaceContextService $workspaceService;
    private EventDispatcherInterface $originalDispatcher;
    private CapturingEventDispatcher $capturingDispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->readTool = new ReadTableTool();
        $this->workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');

        $this->originalDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $this->capturingDispatcher = new CapturingEventDispatcher($this->originalDispatcher);
        GeneralUtility::setSingletonInstance(EventDispatcherInterface::class, $this->capturingDispatcher);
    }

    protected function tearDown(): void
    {
        GeneralUtility::setSingletonInstance(EventDispatcherInterface::class, $this->originalDispatcher);
        parent::tearDown();
    }

    public function testReadNonWorkspaceTableByUidInWorkspaceContextProducesNoWorkspaceColumnsInSql(): void
    {
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $this->assertGreaterThan(
            0,
            $GLOBALS['BE_USER']->workspace,
            'Test prerequisite: backend user must be inside a workspace.'
        );

        $result = $this->readTool->execute([
            'table' => 'sys_file',
            'uid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $captured = $this->capturingDispatcher->capturedSqlForTable('sys_file');
        $this->assertArrayHasKey('count', $captured, 'Count query should have been dispatched.');
        $this->assertArrayHasKey('select', $captured, 'Select query should have been dispatched.');

        foreach ($captured as $type => $sql) {
            $this->assertStringNotContainsString(
                't3ver_oid',
                $sql,
                "The {$type} query against a non-workspace table must not reference t3ver_oid; "
                . "this column does not exist on sys_file and the reference breaks MySQL/MariaDB. SQL: {$sql}"
            );
            $this->assertStringNotContainsString('t3ver_wsid', $sql, "Got SQL: {$sql}");
            $this->assertStringNotContainsString('t3ver_state', $sql, "Got SQL: {$sql}");
        }
    }

    public function testReadNonWorkspaceTableByUidInWorkspaceContextReturnsRecord(): void
    {
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $this->assertGreaterThan(0, $GLOBALS['BE_USER']->workspace);

        $result = $this->readTool->execute([
            'table' => 'sys_file',
            'uid' => 1,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = json_decode($result->content[0]->text, true);
        $this->assertSame(1, $payload['total']);
        $this->assertCount(1, $payload['records']);
        $this->assertSame(1, $payload['records'][0]['uid']);
    }

    public function testReadNonWorkspaceTableByMultipleUidsInWorkspaceContextReturnsRecords(): void
    {
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $this->assertGreaterThan(0, $GLOBALS['BE_USER']->workspace);

        $result = $this->readTool->execute([
            'table' => 'sys_file',
            'uid' => [1, 3],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = json_decode($result->content[0]->text, true);
        $this->assertSame(2, $payload['total']);
        $uids = array_column($payload['records'], 'uid');
        sort($uids);
        $this->assertSame([1, 3], $uids);
    }
}

/**
 * Test-only PSR-14 dispatcher decorator that records the SQL emitted on
 * every BeforeRecordReadEvent, then forwards the event to the real
 * dispatcher so production listeners (mount restrictions, etc.) still run.
 */
final class CapturingEventDispatcher implements EventDispatcherInterface, SingletonInterface
{
    /** @var array<string, array<string, string>> Keyed by table → query type → SQL */
    private array $sqlByTable = [];

    public function __construct(private readonly EventDispatcherInterface $inner) {}

    public function dispatch(object $event): object
    {
        if ($event instanceof BeforeRecordReadEvent) {
            $this->sqlByTable[$event->getTable()][$event->getQueryType()] = $event->getQueryBuilder()->getSQL();
        }
        return $this->inner->dispatch($event);
    }

    /** @return array<string, string> */
    public function capturedSqlForTable(string $table): array
    {
        return $this->sqlByTable[$table] ?? [];
    }
}
