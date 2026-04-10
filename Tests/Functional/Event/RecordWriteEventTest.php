<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Event;

use Hn\McpServer\Event\AfterRecordWriteEvent;
use Hn\McpServer\Event\BeforeRecordWriteEvent;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stateful test listener for BeforeRecordWriteEvent
 */
class BeforeRecordWriteTestListener
{
    public static bool $dispatched = false;
    public static string $table = '';
    public static string $action = '';
    public static bool $shouldVeto = false;
    public static string $vetoReason = '';
    public static ?string $overrideTitle = null;

    public static function reset(): void
    {
        self::$dispatched = false;
        self::$table = '';
        self::$action = '';
        self::$shouldVeto = false;
        self::$vetoReason = '';
        self::$overrideTitle = null;
    }

    public function __invoke(BeforeRecordWriteEvent $event): void
    {
        self::$dispatched = true;
        self::$table = $event->getTable();
        self::$action = $event->getAction();

        if (self::$shouldVeto) {
            $event->veto(self::$vetoReason);
        }

        if (self::$overrideTitle !== null) {
            $data = $event->getData();
            $data['title'] = self::$overrideTitle;
            $event->setData($data);
        }
    }
}

/**
 * Stateful test listener for AfterRecordWriteEvent
 */
class AfterRecordWriteTestListener
{
    public static bool $dispatched = false;
    public static int $uid = 0;
    public static string $action = '';

    public static function reset(): void
    {
        self::$dispatched = false;
        self::$uid = 0;
        self::$action = '';
    }

    public function __invoke(AfterRecordWriteEvent $event): void
    {
        self::$dispatched = true;
        self::$uid = $event->getUid();
        self::$action = $event->getAction();
    }
}

/**
 * Tests for BeforeRecordWriteEvent and AfterRecordWriteEvent
 */
class RecordWriteEventTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new WriteTableTool();

        BeforeRecordWriteTestListener::reset();
        AfterRecordWriteTestListener::reset();

        // Register test listeners in the DI container so ListenerProvider can resolve them
        $container = GeneralUtility::getContainer();
        $container->set(BeforeRecordWriteTestListener::class, new BeforeRecordWriteTestListener());
        $container->set(AfterRecordWriteTestListener::class, new AfterRecordWriteTestListener());

        $listenerProvider = $container->get(ListenerProvider::class);
        $listenerProvider->addListener(BeforeRecordWriteEvent::class, BeforeRecordWriteTestListener::class);
        $listenerProvider->addListener(AfterRecordWriteEvent::class, AfterRecordWriteTestListener::class);
    }

    /**
     * BeforeRecordWriteEvent is dispatched on create
     */
    public function testBeforeRecordWriteEventDispatchedOnCreate(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => ['title' => 'Event test page'],
        ]);

        $this->assertSuccessfulToolResult($result);
        $this->assertTrue(BeforeRecordWriteTestListener::$dispatched, 'BeforeRecordWriteEvent should be dispatched');
        $this->assertEquals('pages', BeforeRecordWriteTestListener::$table);
        $this->assertEquals('create', BeforeRecordWriteTestListener::$action);
    }

    /**
     * BeforeRecordWriteEvent veto stops the write operation
     */
    public function testBeforeRecordWriteEventVetoStopsOperation(): void
    {
        BeforeRecordWriteTestListener::$shouldVeto = true;
        BeforeRecordWriteTestListener::$vetoReason = 'Policy violation: no pages allowed';

        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => ['title' => 'Should not be created'],
        ]);

        $this->assertToolError($result, 'vetoed');
    }

    /**
     * BeforeRecordWriteEvent can modify data before write
     */
    public function testBeforeRecordWriteEventCanModifyData(): void
    {
        BeforeRecordWriteTestListener::$overrideTitle = 'Modified by listener';

        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => ['title' => 'Original title'],
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        // Use ReadTableTool to read the record (handles workspace overlay)
        $readTool = new \Hn\McpServer\MCP\Tool\Record\ReadTableTool();
        $readResult = $readTool->execute([
            'table' => 'pages',
            'uid' => $data['uid'],
            'fields' => ['title'],
        ]);
        $readData = json_decode($readResult->content[0]->text, true);
        $this->assertEquals('Modified by listener', $readData['records'][0]['title']);
    }

    /**
     * AfterRecordWriteEvent is dispatched after successful create
     */
    public function testAfterRecordWriteEventDispatchedOnCreate(): void
    {
        $result = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => ['title' => 'After event test'],
        ]);

        $this->assertSuccessfulToolResult($result);
        $this->assertTrue(AfterRecordWriteTestListener::$dispatched, 'AfterRecordWriteEvent should be dispatched');
        $this->assertGreaterThan(0, AfterRecordWriteTestListener::$uid);
        $this->assertEquals('create', AfterRecordWriteTestListener::$action);
    }

    /**
     * AfterRecordWriteEvent is dispatched on delete
     */
    public function testAfterRecordWriteEventDispatchedOnDelete(): void
    {
        $createResult = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => ['title' => 'To be deleted'],
        ]);
        $createData = $this->extractJsonFromResult($createResult);

        AfterRecordWriteTestListener::reset();

        $result = $this->tool->execute([
            'action' => 'delete',
            'table' => 'pages',
            'uid' => $createData['uid'],
        ]);

        $this->assertSuccessfulToolResult($result);
        $this->assertEquals('delete', AfterRecordWriteTestListener::$action);
    }

    /**
     * AfterRecordWriteEvent is NOT dispatched when BeforeEvent vetoes
     */
    public function testAfterEventNotDispatchedOnVeto(): void
    {
        BeforeRecordWriteTestListener::$shouldVeto = true;
        BeforeRecordWriteTestListener::$vetoReason = 'Blocked';

        $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => ['title' => 'Vetoed page'],
        ]);

        $this->assertFalse(AfterRecordWriteTestListener::$dispatched, 'AfterRecordWriteEvent should NOT fire on veto');
    }
}
