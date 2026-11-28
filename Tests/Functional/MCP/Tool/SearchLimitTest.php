<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\SearchTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The "limit" parameter caps the records per table, including records that are
 * only found through matches in their inline children, and the output says how
 * many records were left out so the client can refine the search.
 */
class SearchLimitTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_nested_files',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        // TYPO3 v13 reads search fields from ctrl, v14 derives them from the columns
        $GLOBALS['TCA']['tx_testnestedfiles_item']['ctrl']['searchFields'] = 'title';
    }

    public function testLimitCapsContentRecordsAndReportsTheTotal(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->insertRecord('tt_content', [
                'uid' => 400 + $i,
                'pid' => 1,
                'CType' => 'text',
                'header' => 'Addressable TV teaser ' . $i,
                'sorting' => $i * 256,
            ]);
        }
        $this->insertRecord('pages', [
            'uid' => 400,
            'pid' => 1,
            'title' => 'Addressable TV',
            'doktype' => 1,
        ]);

        $result = (new SearchTool())->execute(['terms' => ['Addressable TV'], 'limit' => 5]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;

        $this->assertSame(5, $this->countRecordsInSection($text, 'tt_content'), $text);
        $this->assertStringContainsString('Found 12 record(s), showing 5 of 12', $text);
        $this->assertStringContainsString('More records (may) match in: tt_content.', $text);
        // A table below the limit is listed in full and not reported as cut
        $this->assertSame(1, $this->countRecordsInSection($text, 'pages'), $text);
        $this->assertStringNotContainsString('More records (may) match in: pages', $text);
        $this->assertStringContainsString('Total Results: 6', $text);
    }

    public function testDefaultLimitStillAppliesWithoutParameter(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->insertRecord('tt_content', [
                'uid' => 500 + $i,
                'pid' => 1,
                'CType' => 'text',
                'header' => 'Haystack ' . $i,
            ]);
        }

        $result = (new SearchTool())->execute(['terms' => ['Haystack']]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertSame(50, $this->countRecordsInSection($text, 'tt_content'), $text);
        $this->assertStringContainsString('Found 55 record(s), showing 50 of 55', $text);
    }

    public function testParentsFoundThroughInlineMatchesRespectTheLimit(): void
    {
        // Three elements match directly, four more only through one of their items
        for ($i = 1; $i <= 3; $i++) {
            $this->insertRecord('tt_content', [
                'uid' => 600 + $i,
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'Needle element ' . $i,
                'sorting' => $i * 256,
            ]);
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->insertRecord('tt_content', [
                'uid' => 610 + $i,
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'Element with items ' . $i,
                'tx_testnestedfiles_items' => 1,
                'sorting' => (10 + $i) * 256,
            ]);
            $this->insertRecord('tx_testnestedfiles_item', [
                'uid' => $i,
                'pid' => 1,
                'title' => 'Needle item ' . $i,
                'tt_content_items' => 610 + $i,
            ]);
        }

        $result = (new SearchTool())->execute(['terms' => ['needle'], 'limit' => 5]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;

        $this->assertSame(5, $this->countRecordsInSection($text, 'tt_content'), $text);
        // All direct matches are listed, the inline-only parents fill up the rest
        $this->assertStringContainsString('[UID: 601]', $text);
        $this->assertStringContainsString('[UID: 603]', $text);
        $this->assertStringContainsString('Found 7 record(s), showing 5 of 7', $text);
        $this->assertStringContainsString('More records (may) match in: tt_content.', $text);
    }

    public function testParentsOfCutInlineMatchesAreReportedAsPossiblyMore(): void
    {
        // Six elements match only through their item: the limit cuts the items to
        // five, so five parents are found and the sixth one is unknown
        for ($i = 1; $i <= 6; $i++) {
            $this->insertRecord('tt_content', [
                'uid' => 700 + $i,
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'Element ' . $i,
                'tx_testnestedfiles_items' => 1,
                'sorting' => $i * 256,
            ]);
            $this->insertRecord('tx_testnestedfiles_item', [
                'uid' => 10 + $i,
                'pid' => 1,
                'title' => 'Needle item ' . $i,
                'tt_content_items' => 700 + $i,
            ]);
        }

        $result = (new SearchTool())->execute(['terms' => ['needle'], 'limit' => 5]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;

        $this->assertSame(5, $this->countRecordsInSection($text, 'tt_content'), $text);
        $this->assertStringContainsString('Found 5 record(s), more may match', $text);
        $this->assertStringContainsString('More records (may) match in: tt_content.', $text);
    }

    public function testMovedParentDoesNotUseUpTheParentLimitTwice(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');
        $GLOBALS['BE_USER']->workspace = 1;

        // Two live elements reference record 7 in a comma-separated select field;
        // the first one is moved in the workspace (move pointer).
        $this->insertRecord('tt_content', ['uid' => 801, 'pid' => 1, 'CType' => 'text', 'header' => 'Moved', 'subheader' => '7', 'sorting' => 256]);
        $this->insertRecord('tt_content', [
            'uid' => 802, 'pid' => 2, 'CType' => 'text', 'header' => 'Moved', 'subheader' => '7', 'sorting' => 512,
            't3ver_oid' => 801, 't3ver_wsid' => 1, 't3ver_state' => 4,
        ]);
        $this->insertRecord('tt_content', ['uid' => 803, 'pid' => 1, 'CType' => 'text', 'header' => 'Other', 'subheader' => '7,8', 'sorting' => 768]);

        $tool = new SearchTool();
        $method = new \ReflectionMethod($tool, 'findParentRecordsForInlineRecord');
        $parents = $method->invoke($tool, ['uid' => 7], 'tx_testnestedfiles_item', 'tt_content', '', 'subheader', 'select', 2);

        $uids = array_map(static fn(array $record): int => (int)$record['uid'], $parents);
        sort($uids);
        $this->assertSame([801, 803], $uids, 'The moved element counts once and the other one still fits the limit');
    }

    public function testCutInlineMatchesWithoutFoundParentsAreReported(): void
    {
        // Six items match; the first five belong to deleted elements, so the five
        // items the limit lets through yield no parent, while the sixth would.
        for ($i = 1; $i <= 6; $i++) {
            $this->insertRecord('tt_content', [
                'uid' => 900 + $i,
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'Element ' . $i,
                'tx_testnestedfiles_items' => 1,
                'deleted' => $i <= 5 ? 1 : 0,
            ]);
            $this->insertRecord('tx_testnestedfiles_item', [
                'uid' => 20 + $i,
                'pid' => 1,
                'title' => 'Needle item ' . $i,
                'tt_content_items' => 900 + $i,
                'sorting' => $i * 256,
            ]);
        }

        $result = (new SearchTool())->execute(['terms' => ['needle'], 'limit' => 5]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringNotContainsString('No results found', $text);
        $this->assertStringContainsString('More records (may) match in: tt_content.', $text);
        $this->assertSame(0, $this->countRecordsInSection($text, 'tt_content'), $text);
    }

    public function testUncutInlineMatchesWithoutParentsStayAPlainEmptyResult(): void
    {
        $this->insertRecord('tx_testnestedfiles_item', [
            'uid' => 30,
            'pid' => 1,
            'title' => 'Needle item without element',
            'tt_content_items' => 999,
        ]);

        $result = (new SearchTool())->execute(['terms' => ['needle'], 'limit' => 5]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringContainsString('No results found', $text);
        $this->assertStringNotContainsString('More records', $text);
    }

    public function testInlineMatchFindsParentMovedInTheWorkspace(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');
        $GLOBALS['BE_USER']->workspace = 1;

        $this->insertRecord('tt_content', [
            'uid' => 951, 'pid' => 1, 'CType' => 'textmedia', 'header' => 'Moved element', 'tx_testnestedfiles_items' => 1,
        ]);
        // Move pointer: the element is moved to page 2 in workspace 1
        $this->insertRecord('tt_content', [
            'uid' => 952, 'pid' => 2, 'CType' => 'textmedia', 'header' => 'Moved element', 'tx_testnestedfiles_items' => 1,
            't3ver_oid' => 951, 't3ver_wsid' => 1, 't3ver_state' => 4,
        ]);
        $this->insertRecord('tx_testnestedfiles_item', [
            'uid' => 40, 'pid' => 1, 'title' => 'Needle item', 'tt_content_items' => 951,
        ]);

        $result = (new SearchTool())->execute(['terms' => ['needle']]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertSame(1, $this->countRecordsInSection($text, 'tt_content'), $text);
        $this->assertStringContainsString('[UID: 951] Moved element', $text);
    }

    public function testInvalidLimitIsRejected(): void
    {
        $result = (new SearchTool())->execute(['terms' => ['needle'], 'limit' => 0]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('limit must be a positive integer', $result->content[0]->text);
    }

    /**
     * Count the "• [UID: n]" entries in the section of the given table
     */
    private function countRecordsInSection(string $text, string $table): int
    {
        $sections = preg_split('/^TABLE: /m', $text);
        foreach ($sections as $section) {
            if (str_contains(strtok($section, "\n"), '(' . $table . ')')) {
                return preg_match_all('/^• \[UID: \d+\]/m', $section);
            }
        }
        return 0;
    }

    private function insertRecord(string $table, array $values): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table)
            ->insert($table, $values);
    }
}
