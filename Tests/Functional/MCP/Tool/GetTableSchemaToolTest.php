<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetTableSchemaToolTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category.csv');
        
        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUser(1);
    }

    /**
     * Test getting basic table schema information
     */
    public function testGetBasicTableSchema(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for tt_content table
        $result = $tool->execute([
            'table' => 'tt_content'
        ]);
        
        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $content = $result->content[0]->text;
        
        // Verify essential schema information is present
        $this->assertStringContainsString('TABLE SCHEMA: tt_content', $content);
        $this->assertStringContainsString('CURRENT RECORD TYPE:', $content);
        $this->assertStringContainsString('FIELDS:', $content);
        $this->assertStringContainsString('header', $content);
        $this->assertStringContainsString('CType', $content);
    }

    /**
     * Test getting schema for a specific content type
     */
    public function testGetSchemaForSpecificType(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for textmedia content type
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia'
        ]);
        
        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);
        
        $content = $result->content[0]->text;
        
        // Verify type-specific information
        $this->assertStringContainsString('TABLE SCHEMA: tt_content', $content);
        $this->assertStringContainsString('Type: textmedia (Text & Media)', $content);
        $this->assertStringContainsString('header', $content);
        $this->assertStringContainsString('bodytext', $content);
    }

    /**
     * Test getting schema for pages table
     */
    public function testGetPagesTableSchema(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for pages table
        $result = $tool->execute([
            'table' => 'pages'
        ]);
        
        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);
        
        $content = $result->content[0]->text;
        
        // Verify pages table information
        $this->assertStringContainsString('TABLE SCHEMA: pages', $content);
        $this->assertStringContainsString('title', $content);
        $this->assertStringContainsString('slug', $content);
        $this->assertStringContainsString('doktype', $content);
    }

    /**
     * Test getting schema for sys_category table
     * NOTE: This test expects an error because sys_category doesn't have a type field
     * and there's a bug in the tool that doesn't handle this gracefully
     */
    public function testGetSysCategoryTableSchema(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for sys_category table
        $result = $tool->execute([
            'table' => 'sys_category'
        ]);
        
        // sys_category doesn't have a type field, but should work now
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertCount(1, $result->content);
        
        $content = $result->content[0]->text;
        
        // Verify basic schema information is present
        $this->assertStringContainsString('TABLE SCHEMA: sys_category', $content);
        $this->assertStringContainsString('title', $content);
        $this->assertStringContainsString('parent', $content);
    }

    /**
     * Test error handling for missing table parameter
     */
    public function testMissingTableParameter(): void
    {
        $tool = new GetTableSchemaTool();
        
        $result = $tool->execute([]);
        
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertStringContainsString('Table parameter is required', $result->content[0]->text);
    }

    /**
     * Test error handling for empty table parameter
     */
    public function testEmptyTableParameter(): void
    {
        $tool = new GetTableSchemaTool();
        
        $result = $tool->execute([
            'table' => ''
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertStringContainsString('Table parameter is required', $result->content[0]->text);
    }

    /**
     * Test error handling for invalid table
     */
    public function testInvalidTable(): void
    {
        $tool = new GetTableSchemaTool();
        
        $result = $tool->execute([
            'table' => 'nonexistent_table'
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertStringContainsString('Cannot access table \'nonexistent_table\'', $result->content[0]->text);
    }

    /**
     * Test error handling for table without TCA
     */
    public function testTableWithoutTca(): void
    {
        $tool = new GetTableSchemaTool();
        
        $result = $tool->execute([
            'table' => 'sys_log'
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertStringContainsString('Cannot access table \'sys_log\'', $result->content[0]->text);
    }

    /**
     * Test getting schema with invalid type parameter
     */
    public function testInvalidTypeParameter(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for tt_content with invalid type
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'nonexistent_type'
        ]);
        
        // The tool handles invalid types gracefully by returning an error message as content
        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);
        
        $content = $result->content[0]->text;
        
        // Should show an error about the invalid type
        $this->assertStringContainsString('ERROR:', $content);
        $this->assertStringContainsString('nonexistent_type', $content);
        $this->assertStringContainsString('does not exist', $content);
    }

    /**
     * Test schema output format and structure
     */
    public function testSchemaOutputFormat(): void
    {
        $tool = new GetTableSchemaTool();
        
        $result = $tool->execute([
            'table' => 'tt_content'
        ]);
        
        $this->assertFalse($result->isError);
        
        $content = $result->content[0]->text;
        
        // Verify schema structure contains expected sections
        $this->assertStringContainsString('TABLE SCHEMA: tt_content', $content);
        $this->assertStringContainsString('=======================================', $content);
        $this->assertStringContainsString('CONTROL FIELDS:', $content);
        $this->assertStringContainsString('CURRENT RECORD TYPE:', $content);
        $this->assertStringContainsString('FIELDS:', $content);
        
        // Verify field information is present
        $this->assertStringContainsString('Type:', $content);
        $this->assertStringContainsString('header', $content);
        $this->assertStringContainsString('CType', $content);
    }

    /**
     * Test workspace context is properly initialized
     */
    public function testWorkspaceContextInitialization(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Should work in workspace context
        $result = $tool->execute([
            'table' => 'pages'
        ]);
        
        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);
    }

    /**
     * Test that richtext fields are properly marked
     */
    public function testRichtextFieldsAreMarked(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for textmedia type which has richtext bodytext
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia'
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Verify bodytext field shows richtext indicator
        $this->assertMatchesRegularExpression('/bodytext.*\[Richtext\/HTML\]/', $content);
        
        // Verify typolink support is indicated
        $this->assertStringContainsString('[Supports typolinks', $content);
        $this->assertStringContainsString('t3://page?uid=123', $content);
        $this->assertStringContainsString('t3://record?identifier=table&uid=456', $content);
    }

    /**
     * Set up backend user with workspace
     */
    protected function setUpBackendUserWithWorkspace(int $uid): void
    {
        $backendUser = $this->setUpBackendUser($uid);
        $backendUser->workspace = 1; // Set to test workspace
        $GLOBALS['BE_USER'] = $backendUser;
    }
}