<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\SearchTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Search output must be valid UTF-8 and list every hit under the table it belongs to.
 *
 * Previews used to be cut by byte offsets, so an umlaut near the context boundary was
 * split in half. The HTTP transport encodes responses with JSON_THROW_ON_ERROR and
 * silently drops what it cannot encode, so such a search never answered at all.
 */
class SearchResultIntegrityTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->setUpBackendUser(1);

        // TYPO3 v13 reads search fields from ctrl, v14 derives them from the columns
        $GLOBALS['TCA']['tx_testnestedfiles_item']['ctrl']['searchFields'] = 'title';
    }

    public function testPreviewDoesNotSplitMultibyteCharacters(): void
    {
        // With byte offsets the 50 byte context before the match starts in the
        // middle of a two-byte "ä" and the one after it ends inside an "ü".
        $this->insertRecord('tt_content', [
            'uid' => 300,
            'pid' => 1,
            'CType' => 'text',
            'header' => 'Umlauts',
            'bodytext' => str_repeat('ä', 60) . ' TYPO3 ' . str_repeat('ü', 60),
        ]);

        $result = (new SearchTool())->execute(['terms' => ['typo3', 'MCP']]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize(), JSON_INVALID_UTF8_SUBSTITUTE));
        $text = $result->content[0]->text;
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'), 'Search output must be valid UTF-8');
        json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR);

        // The highlight keeps the casing found in the content, not the search term's
        $this->assertStringContainsString(
            '...' . str_repeat('ä', 49) . ' **TYPO3** ' . str_repeat('ü', 49) . '...',
            $text
        );
    }

    public function testInlineMatchesAreListedUnderTheParentTable(): void
    {
        $this->insertRecord('tt_content', [
            'uid' => 310,
            'pid' => 1,
            'CType' => 'textmedia',
            'header' => 'Element with items',
            'tx_testnestedfiles_items' => 1,
        ]);
        $this->insertRecord('tx_testnestedfiles_item', [
            'uid' => 1,
            'pid' => 1,
            'title' => 'Needle in an item',
            'tt_content_items' => 310,
        ]);
        // Matches directly and through its item
        $this->insertRecord('tt_content', [
            'uid' => 311,
            'pid' => 1,
            'CType' => 'textmedia',
            'header' => 'Needle in a header',
            'tx_testnestedfiles_items' => 1,
        ]);
        $this->insertRecord('tx_testnestedfiles_item', [
            'uid' => 2,
            'pid' => 1,
            'title' => 'Another needle item',
            'tt_content_items' => 311,
        ]);

        $result = (new SearchTool())->execute(['terms' => ['needle']]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;

        $this->assertStringNotContainsString('TABLE: Tt (tt)', $text);
        $this->assertSame(1, substr_count($text, '(tt_content)'), 'All tt_content hits share one table section: ' . $text);
        $this->assertStringContainsString("Found 2 record(s)", $text);
        $this->assertSame(1, substr_count($text, '[UID: 311]'), 'A parent that also matches directly is listed once: ' . $text);

        $this->assertMatchesRegularExpression(
            '/\[UID: 310\] Element with items\n(?:.*\n)*?\s+📎 Contains: Needle in an item/u',
            $text
        );
        $this->assertMatchesRegularExpression(
            '/\[UID: 311\] Needle in a header\n(?:.*\n)*?\s+📎 Contains: Another needle item/u',
            $text
        );
    }

    public function testFileHitsAreListedAsFilesNotAsReferencingMetadata(): void
    {
        $this->insertRecord('sys_file', [
            'uid' => 15,
            'pid' => 0,
            'name' => 'other.jpg',
            'identifier' => '/user_upload/other.jpg',
            'storage' => 1,
            'type' => 2,
        ]);
        $this->insertRecord('sys_file_metadata', ['uid' => 1, 'pid' => 0, 'file' => 5, 'title' => 'Team']);
        $this->insertRecord('sys_file_metadata', ['uid' => 2, 'pid' => 0, 'file' => 15, 'title' => 'Other']);

        $result = (new SearchTool())->execute(['terms' => ['team-photo']]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringContainsString('(sys_file)', $text);
        $this->assertStringContainsString('[UID: 5] team-photo.jpg', $text);
        $this->assertStringNotContainsString('Record #', $text);
        $this->assertStringNotContainsString('TABLE: Sys (sys)', $text);
    }

    public function testSelectRelationMatchesWholeUidsOnly(): void
    {
        $this->insertRecord('sys_file', [
            'uid' => 15,
            'pid' => 0,
            'name' => 'other.jpg',
            'identifier' => '/user_upload/other.jpg',
            'storage' => 1,
            'type' => 2,
        ]);
        $this->insertRecord('sys_file_metadata', ['uid' => 1, 'pid' => 0, 'file' => 5, 'title' => 'Team']);
        $this->insertRecord('sys_file_metadata', ['uid' => 2, 'pid' => 0, 'file' => 15, 'title' => 'Other']);

        $tool = new SearchTool();
        $method = new \ReflectionMethod($tool, 'findParentRecordsForInlineRecord');
        $parents = $method->invoke($tool, ['uid' => 5], 'sys_file', 'sys_file_metadata', '', 'file', 'select');

        $this->assertSame([1], array_map(static fn(array $record): int => (int)$record['uid'], $parents));
    }

    private function insertRecord(string $table, array $values): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table)
            ->insert($table, $values);
    }
}
