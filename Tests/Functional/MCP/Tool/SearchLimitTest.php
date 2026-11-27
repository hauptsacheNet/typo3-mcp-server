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
        $this->assertStringContainsString('More records match in: tt_content.', $text);
        // A table below the limit is listed in full and not reported as cut
        $this->assertSame(1, $this->countRecordsInSection($text, 'pages'), $text);
        $this->assertStringNotContainsString('More records match in: pages', $text);
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
        $this->assertStringContainsString('More records match in: tt_content.', $text);
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
