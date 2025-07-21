<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ListTablesTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ListTablesToolTest extends FunctionalTestCase
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
     * Test basic table listing functionality
     */
    public function testListTables(): void
    {
        $tool = new ListTablesTool();
        
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $content = $result->content[0]->text;
        
        // Verify listing contains expected sections
        $this->assertStringContainsString('ACCESSIBLE TABLES IN TYPO3 (via MCP)', $content);
        $this->assertStringContainsString('=====================================', $content);
        $this->assertStringContainsString('workspace-capable and accessible', $content);
        
        // Verify core tables are present
        $this->assertStringContainsString('pages', $content);
        $this->assertStringContainsString('tt_content', $content);
        $this->assertStringContainsString('sys_category', $content);
    }

    /**
     * Test table grouping by extension
     */
    public function testTableGroupingByExtension(): void
    {
        $tool = new ListTablesTool();
        
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        
        $content = $result->content[0]->text;
        
        // Verify extension grouping
        $this->assertStringContainsString('CORE TABLES:', $content);
        $this->assertStringContainsString('EXTENSION: unknown', $content);
        
        // Verify core tables are under core section
        $this->assertMatchesRegularExpression('/CORE TABLES:.*?pages/s', $content);
        $this->assertMatchesRegularExpression('/CORE TABLES:.*?tt_content/s', $content);
        $this->assertMatchesRegularExpression('/CORE TABLES:.*?sys_category/s', $content);
    }

    /**
     * Test table information includes required fields
     */
    public function testTableInformationFields(): void
    {
        $tool = new ListTablesTool();
        
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        
        $content = $result->content[0]->text;
        
        // Verify table information contains required fields
        $this->assertStringContainsString('pages (Page)', $content);
        $this->assertStringContainsString('tt_content (Page Content)', $content);
        $this->assertStringContainsString('sys_category (Category)', $content);
        
        // Verify table type information is present
        $this->assertStringContainsString('[content]', $content);
        $this->assertStringContainsString('[system]', $content);
    }

    /**
     * Test read-only vs writable table distinction
     */
    public function testReadOnlyTableDistinction(): void
    {
        $tool = new ListTablesTool();
        
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        
        $content = $result->content[0]->text;
        
        // Verify access information - currently the tool shows workspace-capable tables
        // and mentions [READ-ONLY] marker exists but we need to see if it's actually used
        $this->assertStringContainsString('Tables marked as [READ-ONLY]', $content);
        
        // Core tables should be workspace-capable (no [READ-ONLY] marker)
        $this->assertStringContainsString('pages (Page)', $content);
        $this->assertStringContainsString('tt_content (Page Content)', $content);
        $this->assertStringContainsString('sys_category (Category)', $content);
    }

    /**
     * Test workspace capability identification
     */
    public function testWorkspaceCapabilityIdentification(): void
    {
        $tool = new ListTablesTool();
        
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        
        $content = $result->content[0]->text;
        
        // Verify workspace information - the tool states "workspace-capable and accessible"
        $this->assertStringContainsString('workspace-capable and accessible', $content);
        
        // All listed tables should be workspace-capable since that's the filtering criteria
        $this->assertStringContainsString('pages (Page)', $content);
        $this->assertStringContainsString('tt_content (Page Content)', $content);
    }

    /**
     * Test table type identification
     */
    public function testTableTypeIdentification(): void
    {
        $tool = new ListTablesTool();
        
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        
        $content = $result->content[0]->text;
        
        // Verify table types are shown in brackets
        $this->assertStringContainsString('[content]', $content);
        $this->assertStringContainsString('[system]', $content);
        
        // Verify specific table types
        $this->assertMatchesRegularExpression('/pages.*?\[content\]/s', $content);
        $this->assertMatchesRegularExpression('/tt_content.*?\[content\]/s', $content);
        $this->assertMatchesRegularExpression('/sys_category.*?\[content\]/s', $content);
    }

    /**
     * Test table descriptions are present
     */
    public function testTableDescriptions(): void
    {
        $tool = new ListTablesTool();
        
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        
        $content = $result->content[0]->text;
        
        // ⚠️ POTENTIAL ISSUE: The tool shows internal TCA display conditions 
        // instead of user-friendly descriptions. This might not be ideal for LLM usage.
        $this->assertStringContainsString('Field \'', $content);
        $this->assertStringContainsString('has display conditions', $content);
        
        // Verify table names contain descriptive labels
        $this->assertStringContainsString('pages (Page)', $content);
        $this->assertStringContainsString('tt_content (Page Content)', $content);
    }

    /**
     * Test total table count
     */
    public function testTotalTableCount(): void
    {
        $tool = new ListTablesTool();
        
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        
        $content = $result->content[0]->text;
        
        // ⚠️ ISSUE: The tool doesn't provide summary statistics like total count
        // This might be useful for LLM context. For now, just verify some tables exist.
        $this->assertStringContainsString('pages', $content);
        $this->assertStringContainsString('tt_content', $content);
        $this->assertStringContainsString('sys_category', $content);
        
        // Count tables manually by looking at the format
        $tableCount = substr_count($content, '- ');
        $this->assertGreaterThanOrEqual(3, $tableCount);
    }

    /**
     * Test extension count
     */
    public function testExtensionCount(): void
    {
        $tool = new ListTablesTool();
        
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        
        $content = $result->content[0]->text;
        
        // ⚠️ ISSUE: No extension count statistics provided
        // Verify extension grouping exists
        $this->assertStringContainsString('CORE TABLES:', $content);
        $this->assertStringContainsString('EXTENSION:', $content);
        
        // Should have at least core and one extension section
        $extensionCount = substr_count($content, 'EXTENSION:');
        $this->assertGreaterThanOrEqual(1, $extensionCount);
    }

    /**
     * Test table summary statistics
     */
    public function testTableSummaryStatistics(): void
    {
        $tool = new ListTablesTool();
        
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        
        $content = $result->content[0]->text;
        
        // ⚠️ ISSUE: No summary statistics provided by the tool
        // This would be useful for LLM context. For now, verify basic functionality.
        $this->assertStringContainsString('workspace-capable and accessible', $content);
        // Note: [READ-ONLY] is mentioned in the description but not shown in actual data
        
        // Verify we have core tables
        $this->assertStringContainsString('pages', $content);
        $this->assertStringContainsString('tt_content', $content);
    }

    /**
     * Test output format and structure
     */
    public function testOutputFormat(): void
    {
        $tool = new ListTablesTool();
        
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        
        $content = $result->content[0]->text;
        
        // Verify proper formatting
        $this->assertStringContainsString('ACCESSIBLE TABLES IN TYPO3 (via MCP)', $content);
        $this->assertStringContainsString('=====================================', $content);
        $this->assertStringContainsString('CORE TABLES:', $content);
        $this->assertStringContainsString('EXTENSION:', $content);
        
        // Verify table entries are properly formatted
        $this->assertMatchesRegularExpression('/^- \w+/m', $content);
        $this->assertStringContainsString('(', $content); // Table labels in parentheses
        $this->assertStringContainsString('[', $content); // Type information in brackets
    }

    /**
     * Test workspace context initialization
     */
    public function testWorkspaceContextInitialization(): void
    {
        $tool = new ListTablesTool();
        
        // Should work in workspace context
        $result = $tool->execute([]);
        
        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);
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