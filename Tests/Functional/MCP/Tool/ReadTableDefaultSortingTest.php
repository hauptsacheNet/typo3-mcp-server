<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * Regression tests for issue #138: a `default_sortby` TCA value that still
 * carries a leading "ORDER BY" prefix (which TYPO3 core itself accepts) must
 * not leak into the generated SQL.
 */
class ReadTableDefaultSortingTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private TableAccessService $tableAccessService;

    /** @var array<string, mixed> */
    private array $originalPagesCtrl = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tableAccessService = new TableAccessService();
        $this->originalPagesCtrl = $GLOBALS['TCA']['pages']['ctrl'] ?? [];
    }

    protected function tearDown(): void
    {
        // Restore the TCA we mutated so later tests see the original config.
        $GLOBALS['TCA']['pages']['ctrl'] = $this->originalPagesCtrl;

        parent::tearDown();
    }

    /**
     * The parser must strip a leading "ORDER BY" prefix just like
     * QueryHelper::parseOrderBy() does.
     */
    public function testParseDefaultSortingStripsOrderByPrefix(): void
    {
        $GLOBALS['TCA']['pages']['ctrl']['default_sortby'] = 'ORDER BY title';

        $this->assertSame(
            [['field' => 'title', 'direction' => 'ASC']],
            $this->tableAccessService->parseDefaultSorting('pages')
        );
    }

    /**
     * The prefix must be stripped regardless of casing/whitespace, and an
     * explicit direction must be preserved.
     */
    public function testParseDefaultSortingStripsPrefixWithDirection(): void
    {
        $GLOBALS['TCA']['pages']['ctrl']['default_sortby'] = 'order by title DESC';

        $this->assertSame(
            [['field' => 'title', 'direction' => 'DESC']],
            $this->tableAccessService->parseDefaultSorting('pages')
        );
    }

    /**
     * Multiple comma-separated fields keep working after prefix removal.
     */
    public function testParseDefaultSortingHandlesMultipleFields(): void
    {
        $GLOBALS['TCA']['pages']['ctrl']['default_sortby'] = 'ORDER BY sorting DESC, title';

        $this->assertSame(
            [
                ['field' => 'sorting', 'direction' => 'DESC'],
                ['field' => 'title', 'direction' => 'ASC'],
            ],
            $this->tableAccessService->parseDefaultSorting('pages')
        );
    }

    /**
     * A plain value (without the prefix) must still parse correctly.
     */
    public function testParseDefaultSortingWithoutPrefixIsUnchanged(): void
    {
        $GLOBALS['TCA']['pages']['ctrl']['default_sortby'] = 'title DESC';

        $this->assertSame(
            [['field' => 'title', 'direction' => 'DESC']],
            $this->tableAccessService->parseDefaultSorting('pages')
        );
    }

    /**
     * End-to-end: reading a table whose `default_sortby` carries an "ORDER BY"
     * prefix previously produced invalid SQL and failed with "Failed to select
     * record". It must now succeed and apply the sorting.
     */
    public function testReadTableWithOrderByPrefixedDefaultSortby(): void
    {
        // Force the default_sortby code path: remove the `sortby` ctrl field
        // (which otherwise takes precedence) and configure a prefixed sorting.
        unset($GLOBALS['TCA']['pages']['ctrl']['sortby']);
        $GLOBALS['TCA']['pages']['ctrl']['default_sortby'] = 'ORDER BY title';

        $tool = new ReadTableTool();
        $result = $tool->execute([
            'table' => 'pages',
            'pid' => 1,
            'includeRelations' => false,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = $this->extractJsonFromResult($result);
        $titles = array_column($data['records'], 'title');

        // The children of page 1 (hidden records included) must come back
        // alphabetically ascending thanks to "ORDER BY title".
        $this->assertSame(['About', 'Contact', 'Hidden Page', 'News'], $titles);
    }
}
