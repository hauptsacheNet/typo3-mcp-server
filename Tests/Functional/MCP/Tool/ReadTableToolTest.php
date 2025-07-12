<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ReadTableToolTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import enhanced page and content fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
    }

    /**
     * Test reading records by PID (page ID)
     */
    public function testReadRecordsByPid(): void
    {
        $tool = new ReadTableTool();
        
        // Read content elements from page 1 (Home)
        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        // Parse JSON result
        $data = json_decode($result->content[0]->text, true);
        $this->assertIsArray($data);
        $this->assertEquals('tt_content', $data['table']);
        $this->assertArrayHasKey('records', $data);
        
        // Should have 2 visible content elements (100, 101) but not hidden one (104)
        $this->assertCount(2, $data['records']);
        
        // Verify record structure
        $firstRecord = $data['records'][0];
        $this->assertArrayHasKey('uid', $firstRecord);
        $this->assertArrayHasKey('header', $firstRecord);
        $this->assertArrayHasKey('CType', $firstRecord);
        
        // Verify specific content
        $uids = array_column($data['records'], 'uid');
        $this->assertContains(100, $uids);
        $this->assertContains(101, $uids);
        $this->assertNotContains(104, $uids); // Hidden content should not be included
    }

    /**
     * Test reading records by PID with hidden records included
     */
    public function testReadRecordsByPidWithHidden(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'includeHidden' => true,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        
        // Should now have 3 content elements including the hidden one
        $this->assertCount(3, $data['records']);
        
        $uids = array_column($data['records'], 'uid');
        $this->assertContains(100, $uids);
        $this->assertContains(101, $uids);
        $this->assertContains(104, $uids); // Hidden content should now be included
    }

    /**
     * Test reading a single record by UID
     */
    public function testReadSingleRecordByUid(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        
        // Should have exactly one record
        $this->assertCount(1, $data['records']);
        
        $record = $data['records'][0];
        $this->assertEquals(100, $record['uid']);
        $this->assertEquals('Welcome Header', $record['header']);
        $this->assertEquals('textmedia', $record['CType']);
        $this->assertEquals(1, $record['pid']);
    }

    /**
     * Test reading from pages table
     */
    public function testReadPagesTable(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([
            'table' => 'pages',
            'pid' => 0, // Root level pages
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        
        $this->assertEquals('pages', $data['table']);
        $this->assertGreaterThan(0, count($data['records']));
        
        // Should include root page (Home) - Contact and News are now subpages
        $titles = array_column($data['records'], 'title');
        $this->assertContains('Home', $titles);
        
        // Contact and News should not be in root level anymore
        $this->assertNotContains('Contact', $titles);
        $this->assertNotContains('News', $titles);
        
        // Should not include hidden pages by default
        $this->assertNotContains('Hidden Page', $titles);
    }

    /**
     * Test pagination functionality
     */
    public function testReadWithPagination(): void
    {
        $tool = new ReadTableTool();
        
        // Test with limit
        $result = $tool->execute([
            'table' => 'pages',
            'limit' => 2,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        
        $this->assertLessThanOrEqual(2, count($data['records']));
        $this->assertEquals(2, $data['limit']);
        $this->assertEquals(0, $data['offset']);
        $this->assertArrayHasKey('hasMore', $data);
        $this->assertArrayHasKey('total', $data);
    }

    /**
     * Test pagination with offset
     */
    public function testReadWithOffset(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([
            'table' => 'pages',
            'limit' => 1,
            'offset' => 1,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        
        $this->assertEquals(1, $data['limit']);
        $this->assertEquals(1, $data['offset']);
    }

    /**
     * Test date field conversion
     */
    public function testDateFieldConversion(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([
            'table' => 'pages',
            'uid' => 1,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        
        $record = $data['records'][0];
        
        // Date fields should be converted to ISO format
        $this->assertArrayHasKey('tstamp', $record);
        $this->assertArrayHasKey('crdate', $record);
        
        // Should be ISO 8601 format strings, not timestamps
        if ($record['tstamp'] !== null) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string)$record['tstamp']);
        }
        if ($record['crdate'] !== null) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string)$record['crdate']);
        }
    }

    /**
     * Test error handling for invalid table
     */
    public function testReadFromInvalidTable(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([
            'table' => 'non_existent_table'
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist in TCA', $result->content[0]->text);
    }

    /**
     * Test error handling for missing table parameter
     */
    public function testMissingTableParameter(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Table name is required', $result->content[0]->text);
    }

    /**
     * Test reading with custom WHERE condition
     */
    public function testReadWithWhereCondition(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([
            'table' => 'tt_content',
            'where' => 'CType = "textmedia"',
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        
        // All returned records should have CType = textmedia
        foreach ($data['records'] as $record) {
            $this->assertEquals('textmedia', $record['CType']);
        }
    }

    /**
     * Test WHERE condition security (should block dangerous SQL)
     */
    public function testWhereConditionSecurity(): void
    {
        $tool = new ReadTableTool();
        
        // Try to inject dangerous SQL
        $result = $tool->execute([
            'table' => 'pages',
            'where' => 'uid = 1; DROP TABLE pages',
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('disallowed SQL keywords', $result->content[0]->text);
    }

    /**
     * Test tool schema
     */
    public function testToolSchema(): void
    {
        $tool = new ReadTableTool();
        $schema = $tool->getSchema();
        
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('description', $schema);
        $this->assertArrayHasKey('parameters', $schema);
        $this->assertArrayHasKey('properties', $schema['parameters']);
        
        // Check key parameters
        $properties = $schema['parameters']['properties'];
        $this->assertArrayHasKey('table', $properties);
        $this->assertArrayHasKey('pid', $properties);
        $this->assertArrayHasKey('uid', $properties);
        $this->assertArrayHasKey('limit', $properties);
        $this->assertArrayHasKey('includeRelations', $properties);
    }

    /**
     * Test reading records with sorting
     */
    public function testReadWithSorting(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        
        // Records should be sorted by sorting field (ascending)
        $this->assertCount(2, $data['records']);
        
        $sortingValues = array_column($data['records'], 'sorting');
        $this->assertEquals(256, $sortingValues[0]);
        $this->assertEquals(512, $sortingValues[1]);
    }

    /**
     * Test essential fields are always included
     */
    public function testEssentialFieldsIncluded(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([
            'table' => 'pages',
            'uid' => 1,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        
        $record = $data['records'][0];
        
        // Essential fields should always be present
        $this->assertArrayHasKey('uid', $record);
        $this->assertArrayHasKey('pid', $record);
        $this->assertArrayHasKey('tstamp', $record);
        $this->assertArrayHasKey('crdate', $record);
        
        // For pages, title should be included as it's the label field
        $this->assertArrayHasKey('title', $record);
    }
}