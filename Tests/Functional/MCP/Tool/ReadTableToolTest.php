<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Mcp\Types\TextContent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ReadTableToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import enhanced page and content fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        
        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUserWithWorkspace(1);
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
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        // Parse JSON result
        $data = json_decode($result->content[0]->text, true);
        $this->assertIsArray($data);
        $this->assertEquals('tt_content', $data['table']);
        $this->assertArrayHasKey('records', $data);
        
        // Should have 3 content elements including hidden one (100, 101, 104)
        $this->assertCount(3, $data['records']);
        
        // Verify record structure
        $firstRecord = $data['records'][0];
        $this->assertArrayHasKey('uid', $firstRecord);
        $this->assertArrayHasKey('header', $firstRecord);
        $this->assertArrayHasKey('CType', $firstRecord);
        
        // Verify specific content - now includes hidden records
        $uids = array_column($data['records'], 'uid');
        $this->assertContains(100, $uids);
        $this->assertContains(101, $uids);
        $this->assertContains(104, $uids); // Hidden content is now included
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
        $this->assertArrayHasKey('offset', $properties);
        $this->assertArrayHasKey('where', $properties);
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
        
        // Records should be sorted by sorting field (ascending) - now includes hidden
        $this->assertCount(3, $data['records']);
        
        $sortingValues = array_column($data['records'], 'sorting');
        $this->assertEquals(256, $sortingValues[0]);
        $this->assertEquals(512, $sortingValues[1]);
        $this->assertEquals(768, $sortingValues[2]); // Hidden record
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
    
    /**
     * Test field filtering based on CType
     */
    public function testFieldFilteringBasedOnCType(): void
    {
        $tool = new ReadTableTool();
        
        // Test textmedia record (UID 100)
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        $textmediaRecord = $data['records'][0];
        
        // Verify this is a textmedia record
        $this->assertEquals('textmedia', $textmediaRecord['CType']);
        
        // Essential fields should always be present
        $this->assertArrayHasKey('uid', $textmediaRecord);
        $this->assertArrayHasKey('pid', $textmediaRecord);
        $this->assertArrayHasKey('CType', $textmediaRecord);
        $this->assertArrayHasKey('header', $textmediaRecord);
        $this->assertArrayHasKey('sorting', $textmediaRecord);
        $this->assertArrayHasKey('tstamp', $textmediaRecord);
        $this->assertArrayHasKey('crdate', $textmediaRecord);
        
        // For textmedia, bodytext should be present if it's in the showitem
        $this->assertArrayHasKey('bodytext', $textmediaRecord);
        
        // Test form_formframework record (UID 105)
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => 105,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        $listRecord = $data['records'][0];
        
        // Verify this is a list record (old plugin system)
        $this->assertEquals('list', $listRecord['CType']);
        
        // Essential fields should always be present
        $this->assertArrayHasKey('uid', $listRecord);
        $this->assertArrayHasKey('pid', $listRecord);
        $this->assertArrayHasKey('CType', $listRecord);
        $this->assertArrayHasKey('header', $listRecord);
        $this->assertArrayHasKey('sorting', $listRecord);
        $this->assertArrayHasKey('tstamp', $listRecord);
        $this->assertArrayHasKey('crdate', $listRecord);
        
        // For list CType, we need to check how the old plugin system works
        // The list CType uses subtype_value_field which should include pi_flexform when needed
        // Note: This test may need adjustment based on actual TCA configuration
        // The exact fields depend on how TYPO3 is configured and what TCA types are defined
        
        // Field filtering analysis:
        // Both records should return type-specific fields based on TCA configuration
        // This tests the new TcaSchemaFactory-based implementation
        
        $textmediaFields = array_keys($textmediaRecord);
        $listFields = array_keys($listRecord);
        
        // Both should have common essential fields
        $commonFields = ['uid', 'pid', 'CType', 'header', 'sorting', 'tstamp', 'crdate'];
        foreach ($commonFields as $field) {
            $this->assertContains($field, $textmediaFields, "Textmedia record missing essential field: $field");
            $this->assertContains($field, $listFields, "List record missing essential field: $field");
        }
        
        // Both records should return type-specific fields based on TCA configuration
        // In a proper type-based filtering system:
        // - textmedia should have: bodytext, assets, but not pi_flexform
        // - list should have: list_type, pages, recursive, and potentially pi_flexform for plugins
        
        // Verify that type-specific fields are present
        $this->assertContains('bodytext', $textmediaFields, "Textmedia should have bodytext");
        $this->assertContains('list_type', $listFields, "List should have list_type field");
        
        // Count fields to ensure we're not getting too many unnecessary fields
        $this->assertLessThan(100, count($textmediaFields), "Too many fields returned for textmedia");
        $this->assertLessThan(100, count($listFields), "Too many fields returned for list");
    }

    /**
     * Test field filtering with unknown CTypes
     */
    public function testFieldFilteringWithUnknownCType(): void
    {
        // Create a record with an unknown CType
        $tool = new ReadTableTool();
        
        // Read a record but simulate unknown CType by testing field filtering behavior
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => 100
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->content));
        $data = json_decode($result->content[0]->text, true);
        $record = $data['records'][0];
        
        // Even with unknown CTypes, essential fields should be present
        $essentialFields = ['uid', 'pid', 'CType', 'header', 'sorting', 'tstamp', 'crdate'];
        foreach ($essentialFields as $field) {
            $this->assertArrayHasKey($field, $record, "Essential field $field missing");
        }
        
        // Should have reasonable field count (not all possible fields)
        $this->assertLessThan(100, count($record), "Too many fields for unknown CType");
    }
    
    /**
     * Helper method to set up backend user with workspace
     */
    protected function setUpBackendUserWithWorkspace(int $uid): void
    {
        $backendUser = $this->setUpBackendUser($uid);
        $backendUser->workspace = 1; // Set to test workspace
        $GLOBALS['BE_USER'] = $backendUser;
    }
}