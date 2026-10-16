<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\Event\BeforeRecordWriteEvent;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Verify that a `BeforeRecordWriteEvent` listener can reroute the target page
 * by editing `data.pid` before the create runs. The tool must re-read pid
 * from data after dispatching the event so listener-driven changes take
 * effect. Without that, the listener's pid mutation would be silently ignored
 * and the record would land on the originally requested page.
 */
class WriteTableBeforeWriteEventTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeTool = new WriteTableTool();
    }

    protected function tearDown(): void
    {
        // Drop any singleton instance we may have set so other tests in the
        // same process see the real container-resolved dispatcher again.
        GeneralUtility::removeSingletonInstance(
            EventDispatcherInterface::class,
            GeneralUtility::makeInstance(EventDispatcherInterface::class)
        );
        parent::tearDown();
    }

    public function testBeforeWriteListenerCanReroutePidOnCreate(): void
    {
        $requestedPid = 1; // "Home" in the standard fixtures
        $reroutedPid = 2;  // "About"

        $this->installDispatcher(function (object $event) use ($reroutedPid): void {
            if ($event instanceof BeforeRecordWriteEvent && $event->getAction() === 'create') {
                $data = $event->getData();
                $data['pid'] = $reroutedPid;
                $event->setData($data);
            }
        });

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => $requestedPid,
                'CType' => 'textmedia',
                'header' => 'Listener Reroute Test',
                'colPos' => 0,
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $payload = json_decode($result->content[0]->text, true);
        $newUid = (int)($payload['uid'] ?? 0);
        $this->assertGreaterThan(0, $newUid, 'Expected a created record uid in the response');

        // Confirm the record landed on the listener's chosen page, not the
        // page the caller originally specified.
        $record = BackendUtility::getRecord('tt_content', $newUid, 'pid');
        $this->assertNotNull($record, "Created record $newUid not found");
        $this->assertSame(
            $reroutedPid,
            (int)$record['pid'],
            'Listener edited data.pid but the record landed on the originally requested page — '
            . 'pid must be re-read from data after BeforeRecordWriteEvent dispatch.'
        );
    }

    private function installDispatcher(callable $listener): void
    {
        // Implement SingletonInterface so GeneralUtility::setSingletonInstance
        // accepts our anonymous dispatcher as a stand-in for the container's.
        $dispatcher = new class($listener) implements EventDispatcherInterface, SingletonInterface {
            public function __construct(private $listener) {}

            public function dispatch(object $event): object
            {
                ($this->listener)($event);
                return $event;
            }
        };

        GeneralUtility::setSingletonInstance(EventDispatcherInterface::class, $dispatcher);
    }
}
