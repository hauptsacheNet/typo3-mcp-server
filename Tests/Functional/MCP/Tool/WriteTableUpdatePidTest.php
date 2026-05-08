<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * Verify that setting `pid` in the data of an `action=update` call moves the
 * record to the new page. `pid` is not a TCA columns entry, so it bypasses
 * the normal "unknown field" rejection and is routed through DataHandler's
 * `move` cmdmap instead.
 */
class WriteTableUpdatePidTest extends AbstractFunctionalTest
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

    public function testUpdatePidMovesContentRecordToAnotherPage(): void
    {
        $sourcePage = $this->createPage('Source Page', '/move-source');
        $targetPage = $this->createPage('Target Page', '/move-target');

        $sourceUids = $this->createOrderedContent($sourcePage, ['Keep1', 'Mover', 'Keep2']);
        $targetUids = $this->createOrderedContent($targetPage, ['Existing']);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $sourceUids['Mover'],
            'data' => ['pid' => $targetPage],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($sourcePage, ['Keep1', 'Keep2']);
        // No position specified → the moved record lands at the top of the new page.
        $this->assertOrderOnPage($targetPage, ['Mover', 'Existing']);
    }

    public function testUpdatePidIsNotRejectedAsUnknownField(): void
    {
        // Regression test: the "reject unknown fields" guard added for fields
        // that lack a TCA columns entry must NOT swallow `pid`, since pid is
        // a special TYPO3 control field handled by DataHandler.
        $sourcePage = $this->createPage('Reject Source', '/reject-src');
        $targetPage = $this->createPage('Reject Target', '/reject-tgt');
        $uids = $this->createOrderedContent($sourcePage, ['A']);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uids['A'],
            'data' => ['pid' => $targetPage],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testUpdatePidWithPositionBottomLandsAtBottomOfTargetPage(): void
    {
        $sourcePage = $this->createPage('Pid+Position Source', '/pp-src');
        $targetPage = $this->createPage('Pid+Position Target', '/pp-tgt');

        $sourceUids = $this->createOrderedContent($sourcePage, ['Mover']);
        $this->createOrderedContent($targetPage, ['First', 'Second']);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $sourceUids['Mover'],
            'data' => ['pid' => $targetPage],
            'position' => 'bottom',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($targetPage, ['First', 'Second', 'Mover']);
        $this->assertOrderOnPage($sourcePage, []);
    }

    public function testUpdatePidWithPositionBottomMovesEvenWhenTargetPageIsEmpty(): void
    {
        // Regression: position=bottom resolved to "no last record" on the target
        // page → null destination → silent no-op, with the record stuck on its
        // original page even though pid was set.
        $sourcePage = $this->createPage('Empty-Target Source', '/et-src');
        $emptyTarget = $this->createPage('Empty-Target', '/et-tgt');

        $sourceUids = $this->createOrderedContent($sourcePage, ['Mover']);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $sourceUids['Mover'],
            'data' => ['pid' => $emptyTarget],
            'position' => 'bottom',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($sourcePage, []);
        $this->assertOrderOnPage($emptyTarget, ['Mover']);
    }

    public function testUpdatePidWithPositionAfterPlacesAfterReference(): void
    {
        $sourcePage = $this->createPage('After Source', '/after-src');
        $targetPage = $this->createPage('After Target', '/after-tgt');

        $sourceUids = $this->createOrderedContent($sourcePage, ['Mover']);
        $targetUids = $this->createOrderedContent($targetPage, ['First', 'Second', 'Third']);

        // Move 'Mover' to target page, immediately after 'First'.
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $sourceUids['Mover'],
            'data' => ['pid' => $targetPage],
            'position' => 'after:' . $targetUids['First'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($targetPage, ['First', 'Mover', 'Second', 'Third']);
    }

    public function testUpdatePidAlongsideFieldUpdate(): void
    {
        $sourcePage = $this->createPage('Combo Source', '/combo-src');
        $targetPage = $this->createPage('Combo Target', '/combo-tgt');

        $sourceUids = $this->createOrderedContent($sourcePage, ['Original Header']);

        // Rename the record AND move it in one call.
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $sourceUids['Original Header'],
            'data' => [
                'pid' => $targetPage,
                'header' => 'Renamed Header',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($sourcePage, []);
        $this->assertOrderOnPage($targetPage, ['Renamed Header']);
    }

    public function testUpdatePidToSamePageIsNoop(): void
    {
        // Setting pid to the record's current page should not change order
        // — DataHandler's move with positive destination puts the record at
        // the top of that page.
        $page = $this->createPage('Same Pid', '/same-pid');
        $uids = $this->createOrderedContent($page, ['A', 'B', 'C']);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uids['B'],
            'data' => ['pid' => $page],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // 'B' was moved to the top of the same page.
        $this->assertOrderOnPage($page, ['B', 'A', 'C']);
    }

    public function testUpdatePidMovesPageToAnotherParent(): void
    {
        // Pages also support move via pid — this changes the page-tree parent.
        $parentA = $this->createPage('Parent A', '/parent-a');
        $parentB = $this->createPage('Parent B', '/parent-b');

        $childUid = $this->createPage('Child', '/parent-a/child', $parentA);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $childUid,
            'data' => ['pid' => $parentB],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $readResult = $this->readTool->execute([
            'table' => 'pages',
            'uid' => $childUid,
        ]);
        $this->assertFalse($readResult->isError, json_encode($readResult->jsonSerialize()));
        $data = $this->extractJsonFromResult($readResult);
        $record = $data['records'][0] ?? $data;
        $this->assertSame($parentB, (int)$record['pid'], 'Page should now live under the new parent');
    }

    public function testUpdateWithTopLevelPidStillWorksAsCompatFallback(): void
    {
        // Top-level `pid` is no longer in the schema, but for backwards compat
        // with older callers (and LLMs trained on prior versions) the tool
        // still folds a top-level pid into data and performs the same move.
        $sourcePage = $this->createPage('Top-Level Pid Source', '/tlp-src');
        $targetPage = $this->createPage('Top-Level Pid Target', '/tlp-tgt');
        $uids = $this->createOrderedContent($sourcePage, ['Mover']);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uids['Mover'],
            'pid' => $targetPage,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertOrderOnPage($targetPage, ['Mover']);
        $this->assertOrderOnPage($sourcePage, []);
    }

    public function testUpdatePidOnlyWithoutOtherDataIsAllowed(): void
    {
        // When `pid` is the ONLY field provided, the schema's "data parameter
        // must contain record fields" guard must not reject the request — pid
        // is a legitimate, intentional change in this case.
        $sourcePage = $this->createPage('Only Pid Source', '/only-src');
        $targetPage = $this->createPage('Only Pid Target', '/only-tgt');

        $uids = $this->createOrderedContent($sourcePage, ['Lonely']);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uids['Lonely'],
            'data' => ['pid' => $targetPage],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertOrderOnPage($targetPage, ['Lonely']);
    }

    private function createPage(string $title, string $slug, ?int $parentUid = null): int
    {
        $pageResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $parentUid ?? $this->getRootPageUid(),
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
        $records = $readData['records'] ?? [];
        $actual = array_map(static fn(array $r): string => $r['header'], $records);
        $this->assertSame($expectedHeaders, $actual);
    }
}
