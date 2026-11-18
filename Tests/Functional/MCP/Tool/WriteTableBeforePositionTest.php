<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use B13\Container\Tca\ContainerConfiguration;
use B13\Container\Tca\Registry;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * "before:UID" is resolved to "after the predecessor of UID". The predecessor must be
 * a real sibling of the reference record in the current workspace:
 *
 * - same column and, with b13/container, same container. Otherwise the container
 *   itself or a record of another column is picked, and the record is moved out of
 *   its container or into the wrong column.
 * - not a live record that has a version in the current workspace. Its live sorting
 *   is outdated once the record was moved in the workspace.
 */
class WriteTableBeforePositionTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'container',
    ];

    private const CONTAINER_CTYPE = 'mcptest_two_columns';
    private const LEFT_COLPOS = 200;
    private const RIGHT_COLPOS = 201;

    private WriteTableTool $writeTool;
    private ReadTableTool $readTool;

    protected function setUp(): void
    {
        parent::setUp();

        GeneralUtility::getContainer()->get(Registry::class)->configureContainer(new ContainerConfiguration(
            self::CONTAINER_CTYPE,
            'MCP Test Two Columns',
            'Container with two grid columns for MCP tests',
            [[
                ['name' => 'Left', 'colPos' => self::LEFT_COLPOS],
                ['name' => 'Right', 'colPos' => self::RIGHT_COLPOS],
            ]]
        ));

        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->readTool = GeneralUtility::makeInstance(ReadTableTool::class);
    }

    /**
     * The predecessor of the first child of a container used to be the container
     * itself, so "before:<first child>" moved the record behind the container.
     */
    public function testUpdateBeforeFirstChildKeepsRecordInContainer(): void
    {
        $pageUid = $this->createPage('Before First Child', '/before-first-child');
        $containerUid = $this->createContent($pageUid, 'Container', ['CType' => self::CONTAINER_CTYPE, 'colPos' => 0]);
        $a = $this->createChild($pageUid, $containerUid, self::LEFT_COLPOS, 'A');
        $this->createChild($pageUid, $containerUid, self::LEFT_COLPOS, 'B');
        $c = $this->createChild($pageUid, $containerUid, self::LEFT_COLPOS, 'C');

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $c,
            'position' => 'before:' . $a,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertSame(['C', 'A', 'B'], $this->getHeaders($pageUid, $containerUid, self::LEFT_COLPOS));
        $this->assertSame(['Container'], $this->getHeaders($pageUid, 0, 0), 'C must not be moved out of the container');
    }

    /**
     * The children of a container lie between the container and the next record of the
     * main column by sorting. "before:<record after the container>" used to pick the
     * last child as predecessor, and the moved record ended up inside the container.
     */
    public function testUpdateBeforeIgnoresChildrenOfOtherContainers(): void
    {
        $pageUid = $this->createPage('Before Behind Container', '/before-behind-container');
        $a = $this->createContent($pageUid, 'A', ['CType' => 'textmedia', 'colPos' => 0]);
        $containerUid = $this->createContent($pageUid, 'Container', ['CType' => self::CONTAINER_CTYPE, 'colPos' => 0]);
        $this->createChild($pageUid, $containerUid, self::LEFT_COLPOS, 'Child');
        $b = $this->createContent($pageUid, 'B', ['CType' => 'textmedia', 'colPos' => 0]);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $a,
            'position' => 'before:' . $b,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertSame(['Container', 'A', 'B'], $this->getHeaders($pageUid, 0, 0));
        $this->assertSame(['Child'], $this->getHeaders($pageUid, $containerUid, self::LEFT_COLPOS));
    }

    /**
     * WorkspaceRestriction returns a moved live record twice: the live record with its
     * old sorting and the move pointer with the new one. Here A and then D are moved to
     * the end in the workspace, so the live record of D lies directly below the
     * workspace position of A. It used to be picked as predecessor of A, so "move D
     * before A" became "move D behind itself" and nothing happened.
     */
    public function testUpdateBeforeIgnoresOutdatedLiveRecordOfMovedRecord(): void
    {
        [$pageUid, $a, $d] = $this->createLiveContentMovedInWorkspace();

        $this->moveRecord($d, 'before:' . $a);

        $this->assertSame(['B', 'C', 'D', 'A'], $this->getHeaders($pageUid, 0, 0));
    }

    /**
     * Same situation for a new record: the outdated live record of D was picked as
     * predecessor of A, and the new record was inserted behind D at the end of the page.
     */
    public function testCreateBeforeIgnoresOutdatedLiveRecordOfMovedRecord(): void
    {
        [$pageUid, $a] = $this->createLiveContentMovedInWorkspace();

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pageUid,
            'position' => 'before:' . $a,
            'data' => ['CType' => 'textmedia', 'header' => 'New', 'colPos' => 0],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertSame(['B', 'C', 'New', 'A', 'D'], $this->getHeaders($pageUid, 0, 0));
    }

    /**
     * Creates a live page with the live records A, B, C, D (records created through
     * the MCP are new workspace records and have no outdated live record) and moves A
     * behind D and then D behind A in the workspace.
     *
     * @return array{int, int, int} uids of the page, A and D
     */
    private function createLiveContentMovedInWorkspace(): array
    {
        $pageUid = $this->insertLiveRecord('pages', [
            'pid' => $this->getRootPageUid(),
            'title' => 'Live Page',
            'slug' => '/live-page',
            'doktype' => 1,
        ]);
        $uids = [];
        foreach (['A', 'B', 'C', 'D'] as $index => $header) {
            $uids[$header] = $this->insertLiveRecord('tt_content', [
                'pid' => $pageUid,
                'CType' => 'textmedia',
                'header' => $header,
                'colPos' => 0,
                'sorting' => ($index + 1) * 256,
            ]);
        }

        $this->moveRecord($uids['A'], 'after:' . $uids['D']);
        $this->moveRecord($uids['D'], 'after:' . $uids['A']);
        $this->assertSame(['B', 'C', 'A', 'D'], $this->getHeaders($pageUid, 0, 0));

        return [$pageUid, $uids['A'], $uids['D']];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertLiveRecord(string $table, array $data): int
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        $connection->insert($table, $data + ['tstamp' => time(), 'crdate' => time()]);
        return (int)$connection->lastInsertId();
    }

    private function moveRecord(int $uid, string $position): void
    {
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'position' => $position,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    private function createPage(string $title, string $slug): int
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => ['title' => $title, 'slug' => $slug, 'doktype' => 1],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return (int)$this->extractJsonFromResult($result)['uid'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createContent(int $pageUid, string $header, array $data): int
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pageUid,
            'position' => 'bottom',
            'data' => ['header' => $header] + $data,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return (int)$this->extractJsonFromResult($result)['uid'];
    }

    private function createChild(int $pageUid, int $containerUid, int $colPos, string $header): int
    {
        return $this->createContent($pageUid, $header, [
            'CType' => 'textmedia',
            'colPos' => $colPos,
            'tx_container_parent' => $containerUid,
        ]);
    }

    /**
     * Headers of the records in one column (and container) of a page, in the order
     * the MCP client sees them.
     *
     * @return string[]
     */
    private function getHeaders(int $pageUid, int $containerUid, int $colPos): array
    {
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'pid' => $pageUid,
            'limit' => 100,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $records = $this->extractJsonFromResult($result)['records'];

        $records = array_filter(
            $records,
            static fn(array $record): bool => (int)$record['colPos'] === $colPos
                && (int)($record['tx_container_parent'] ?? 0) === $containerUid
        );
        return array_values(array_map(static fn(array $record): string => (string)$record['header'], $records));
    }
}
