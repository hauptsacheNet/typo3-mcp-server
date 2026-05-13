<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * Tests for the `copy` and `move` actions of WriteTableTool. Both wrap the
 * DataHandler cmdmap commands of the same name and must keep workspace UIDs
 * transparent to the caller (live UIDs in, live UIDs out).
 */
class WriteTableCopyMoveTest extends AbstractFunctionalTest
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

    public function testCopyDuplicatesRecordToTargetPage(): void
    {
        $sourcePage = $this->createPage('Copy Source', '/copy-src');
        $targetPage = $this->createPage('Copy Target', '/copy-tgt');
        $uids = $this->createOrderedContent($sourcePage, ['A']);

        $result = $this->writeTool->execute([
            'action' => 'copy',
            'table' => 'tt_content',
            'uid' => $uids['A'],
            'data' => ['pid' => $targetPage],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = $this->extractJsonFromResult($result);
        $this->assertSame('copy', $payload['action']);
        $this->assertSame($uids['A'], $payload['sourceUid']);
        $this->assertGreaterThan(0, $payload['uid']);
        $this->assertNotSame($uids['A'], $payload['uid'], 'Copy must produce a different UID');

        // Source page still has the original; target page now has the copy.
        $this->assertOrderOnPage($sourcePage, ['A']);
        $this->assertOrderOnPage($targetPage, ['A']);
    }

    public function testCopyToTargetPagePlacesAtBottomByDefault(): void
    {
        $sourcePage = $this->createPage('Copy Source 2', '/copy-src-2');
        $targetPage = $this->createPage('Copy Target 2', '/copy-tgt-2');
        $sourceUids = $this->createOrderedContent($sourcePage, ['X']);
        $this->createOrderedContent($targetPage, ['Existing1', 'Existing2']);

        $result = $this->writeTool->execute([
            'action' => 'copy',
            'table' => 'tt_content',
            'uid' => $sourceUids['X'],
            'data' => ['pid' => $targetPage],
            'position' => 'bottom',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($targetPage, ['Existing1', 'Existing2', 'X']);
    }

    public function testCopyWithPositionTopPlacesCopyAtTop(): void
    {
        $sourcePage = $this->createPage('Copy Top Source', '/copy-top-src');
        $targetPage = $this->createPage('Copy Top Target', '/copy-top-tgt');
        $sourceUids = $this->createOrderedContent($sourcePage, ['Banner']);
        $this->createOrderedContent($targetPage, ['Old1', 'Old2']);

        $result = $this->writeTool->execute([
            'action' => 'copy',
            'table' => 'tt_content',
            'uid' => $sourceUids['Banner'],
            'data' => ['pid' => $targetPage],
            'position' => 'top',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($targetPage, ['Banner', 'Old1', 'Old2']);
    }

    public function testCopyWithPositionAfterPlacesCopyBehindReference(): void
    {
        $pageUid = $this->createPage('Copy After Test', '/copy-after');
        $uids = $this->createOrderedContent($pageUid, ['A', 'B', 'C']);

        // Copy A so the copy lands right behind B → expect A, B, A(copy), C.
        // TYPO3's DataHandler prepends "(copy N)" on same-page copies via
        // the TCA `prependAtCopy` config (tt_content.header has it set).
        $result = $this->writeTool->execute([
            'action' => 'copy',
            'table' => 'tt_content',
            'uid' => $uids['A'],
            'position' => 'after:' . $uids['B'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($pageUid, ['A', 'B', 'A (copy 1)', 'C']);
    }

    public function testCopyWithoutPidOrPositionFails(): void
    {
        $pageUid = $this->createPage('Copy NoDest', '/copy-nodest');
        $uids = $this->createOrderedContent($pageUid, ['A']);

        $result = $this->writeTool->execute([
            'action' => 'copy',
            'table' => 'tt_content',
            'uid' => $uids['A'],
        ]);
        $this->assertTrue($result->isError, 'Copy without pid or position must fail');
    }

    public function testCopyRejectsDataFieldsOtherThanPid(): void
    {
        $pageUid = $this->createPage('Copy NoFields', '/copy-nofields');
        $uids = $this->createOrderedContent($pageUid, ['A']);

        $result = $this->writeTool->execute([
            'action' => 'copy',
            'table' => 'tt_content',
            'uid' => $uids['A'],
            'data' => ['pid' => $pageUid, 'header' => 'overridden'],
        ]);
        $this->assertTrue($result->isError, 'Copy with non-pid data fields must fail');
    }

    public function testCopyRequiresUid(): void
    {
        $pageUid = $this->createPage('Copy NoUid', '/copy-nouid');

        $result = $this->writeTool->execute([
            'action' => 'copy',
            'table' => 'tt_content',
            'data' => ['pid' => $pageUid],
        ]);
        $this->assertTrue($result->isError, 'Copy without uid must fail');
        $this->assertStringContainsString('Record UID is required for copy action', $result->content[0]->text);
    }

    public function testMoveToDifferentPageRelocatesRecord(): void
    {
        $sourcePage = $this->createPage('Move Source', '/move-src');
        $targetPage = $this->createPage('Move Target', '/move-tgt');
        $uids = $this->createOrderedContent($sourcePage, ['A', 'B']);

        $result = $this->writeTool->execute([
            'action' => 'move',
            'table' => 'tt_content',
            'uid' => $uids['B'],
            'data' => ['pid' => $targetPage],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = $this->extractJsonFromResult($result);
        $this->assertSame('move', $payload['action']);
        $this->assertSame($uids['B'], $payload['uid'], 'Move must keep the live UID stable');

        $this->assertOrderOnPage($sourcePage, ['A']);
        $this->assertOrderOnPage($targetPage, ['B']);
    }

    public function testMoveWithPositionReordersWithinSamePage(): void
    {
        $pageUid = $this->createPage('Move Reorder', '/move-reorder');
        $uids = $this->createOrderedContent($pageUid, ['A', 'B', 'C']);

        $result = $this->writeTool->execute([
            'action' => 'move',
            'table' => 'tt_content',
            'uid' => $uids['C'],
            'position' => 'top',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($pageUid, ['C', 'A', 'B']);
    }

    public function testMoveToDifferentPageWithPositionPlacesAtChosenSpot(): void
    {
        $sourcePage = $this->createPage('Move Combined Src', '/move-combo-src');
        $targetPage = $this->createPage('Move Combined Tgt', '/move-combo-tgt');
        $sourceUids = $this->createOrderedContent($sourcePage, ['Mover']);
        $targetUids = $this->createOrderedContent($targetPage, ['T1', 'T2']);

        $result = $this->writeTool->execute([
            'action' => 'move',
            'table' => 'tt_content',
            'uid' => $sourceUids['Mover'],
            'data' => ['pid' => $targetPage],
            'position' => 'after:' . $targetUids['T1'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($sourcePage, []);
        $this->assertOrderOnPage($targetPage, ['T1', 'Mover', 'T2']);
    }

    public function testMoveWithoutPidOrPositionFails(): void
    {
        $pageUid = $this->createPage('Move NoDest', '/move-nodest');
        $uids = $this->createOrderedContent($pageUid, ['A']);

        $result = $this->writeTool->execute([
            'action' => 'move',
            'table' => 'tt_content',
            'uid' => $uids['A'],
        ]);
        $this->assertTrue($result->isError, 'Move without pid or position must fail');
    }

    public function testMoveRejectsDataFieldsOtherThanPid(): void
    {
        $sourcePage = $this->createPage('Move Fields Src', '/move-fields-src');
        $targetPage = $this->createPage('Move Fields Tgt', '/move-fields-tgt');
        $uids = $this->createOrderedContent($sourcePage, ['A']);

        $result = $this->writeTool->execute([
            'action' => 'move',
            'table' => 'tt_content',
            'uid' => $uids['A'],
            'data' => ['pid' => $targetPage, 'header' => 'renamed during move'],
        ]);
        $this->assertTrue($result->isError, 'Move with non-pid data fields must fail');
    }

    public function testMoveRequiresUid(): void
    {
        $pageUid = $this->createPage('Move NoUid', '/move-nouid');

        $result = $this->writeTool->execute([
            'action' => 'move',
            'table' => 'tt_content',
            'data' => ['pid' => $pageUid],
        ]);
        $this->assertTrue($result->isError, 'Move without uid must fail');
        $this->assertStringContainsString('Record UID is required for move action', $result->content[0]->text);
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
