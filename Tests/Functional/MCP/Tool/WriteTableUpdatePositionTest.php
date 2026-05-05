<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * Verify that the `position` parameter is honoured on `action=update`,
 * matching the schema description that says "For update: omit to keep
 * current position, or specify to move the record."
 *
 * The schema-bottom case is already covered by WriteTableToolTest. This
 * class fills in the rest of the documented vocabulary on update:
 * top, before:UID and after:UID.
 */
class WriteTableUpdatePositionTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;
    private ReadTableTool $readTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeTool = new WriteTableTool();
        $this->readTool = new ReadTableTool();
    }

    public function testUpdatePositionTopMovesRecordToFront(): void
    {
        $pageUid = $this->createPage('Position Top Test', '/pos-top');
        $uids = $this->createOrderedContent($pageUid, ['A', 'B', 'C']);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uids['C'],
            'position' => 'top',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($pageUid, ['C', 'A', 'B']);
    }

    public function testUpdatePositionBeforeMovesRecordInFrontOfReference(): void
    {
        $pageUid = $this->createPage('Position Before Test', '/pos-before');
        $uids = $this->createOrderedContent($pageUid, ['A', 'B', 'C']);

        // Move C in front of B → expect A, C, B
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uids['C'],
            'position' => 'before:' . $uids['B'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($pageUid, ['A', 'C', 'B']);
    }

    public function testUpdatePositionAfterMovesRecordBehindReference(): void
    {
        $pageUid = $this->createPage('Position After Test', '/pos-after');
        $uids = $this->createOrderedContent($pageUid, ['A', 'B', 'C']);

        // Move A behind B → expect B, A, C
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uids['A'],
            'position' => 'after:' . $uids['B'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($pageUid, ['B', 'A', 'C']);
    }

    public function testUpdatePositionAlongsideDataChangeDoesBoth(): void
    {
        $pageUid = $this->createPage('Position + Data Test', '/pos-and-data');
        $uids = $this->createOrderedContent($pageUid, ['A', 'B', 'C']);

        // Re-headline B AND move it to top in the same call.
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uids['B'],
            'data' => ['header' => 'B renamed'],
            'position' => 'top',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($pageUid, ['B renamed', 'A', 'C']);
    }

    private function createPage(string $title, string $slug): int
    {
        $pageResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => ['title' => $title, 'slug' => $slug, 'doktype' => 1],
        ]);
        $this->assertFalse($pageResult->isError, json_encode($pageResult->jsonSerialize()));
        return (int)$this->extractJsonFromResult($pageResult)['uid'];
    }

    /**
     * @param string[] $headers
     * @return array<string, int>
     */
    private function createOrderedContent(int $pageUid, array $headers): array
    {
        $uids = [];
        foreach ($headers as $header) {
            $result = $this->writeTool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => $pageUid,
                'position' => 'bottom',
                'data' => ['CType' => 'textmedia', 'header' => $header, 'colPos' => 0],
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $uids[$header] = (int)$this->extractJsonFromResult($result)['uid'];
        }
        return $uids;
    }

    /**
     * @param string[] $expectedHeaders
     */
    private function assertOrderOnPage(int $pageUid, array $expectedHeaders): void
    {
        $readResult = $this->readTool->execute([
            'table' => 'tt_content',
            'pid' => $pageUid,
        ]);
        $this->assertFalse($readResult->isError, json_encode($readResult->jsonSerialize()));
        $readData = $this->extractJsonFromResult($readResult);
        $actual = array_map(static fn(array $r): string => $r['header'], $readData['records']);
        $this->assertSame($expectedHeaders, $actual);
    }
}
