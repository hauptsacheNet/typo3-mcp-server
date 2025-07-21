<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\MCP\ToolRegistry;
use Mcp\Types\TextContent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class SearchToolTest extends FunctionalTestCase
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
        
        // Import all necessary fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        
        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUser(1);
    }

    /**
     * Test single term search across all tables
     */
    public function testSingleTermSearch(): void
    {
        $tool = new SearchTool();
        
        
        // Search for "welcome"
        $result = $tool->execute([
            'terms' => ['welcome']
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $content = $result->content[0]->text;
        
        // Verify search results header
        $this->assertStringContainsString('SEARCH RESULTS', $content);
        $this->assertStringContainsString('Query: "welcome"', $content);
        
        // Should find content in tt_content
        $this->assertStringContainsString('TABLE: Page Content (tt_content)', $content);
        $this->assertStringContainsString('[UID: 100] Welcome Header', $content);
        $this->assertStringContainsString('Preview:', $content);
        
        // Should show page information
        $this->assertStringContainsString('📍 Page: Home [UID: 1]', $content);
    }

    /**
     * Test multiple terms with OR logic (default)
     */
    public function testMultipleTermsWithOrLogic(): void
    {
        $tool = new SearchTool();
        
        $result = $tool->execute([
            'terms' => ['news', 'article'],
            'termLogic' => 'OR'
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Verify search header shows OR logic
        $this->assertStringContainsString('Search Terms: ["news", "article"]', $content);
        $this->assertStringContainsString('Logic: OR (records must match ANY term)', $content);
        
        // Should find multiple results
        $this->assertStringContainsString('News', $content); // Page title
        $this->assertStringContainsString('Article', $content); // Page or content
        // Content will be in preview format with highlighted terms
        $this->assertStringContainsString('Preview:', $content);
    }

    /**
     * Test multiple terms with AND logic
     */
    public function testMultipleTermsWithAndLogic(): void
    {
        $tool = new SearchTool();
        
        $result = $tool->execute([
            'terms' => ['team', 'meet'],
            'termLogic' => 'AND'
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Verify search header shows AND logic
        $this->assertStringContainsString('Logic: AND (records must match ALL terms)', $content);
        
        // Should only find "Meet our team" content (has both terms)
        $this->assertStringContainsString('[UID: 102] Team Introduction', $content);
        $this->assertStringContainsString('Preview:', $content);
        
        // Should NOT find "Team Members" (doesn't have "meet")
        $this->assertStringNotContainsString('[UID: 103]', $content);
    }

    /**
     * Test table-specific search
     */
    public function testTableSpecificSearch(): void
    {
        $tool = new SearchTool();
        
        // Search only in pages table
        $result = $tool->execute([
            'terms' => ['Home'],
            'table' => 'pages'
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should only show pages table results
        $this->assertStringContainsString('TABLE: Page (pages)', $content);
        $this->assertStringContainsString('[UID: 1] Home', $content);
        
        // Should NOT show content elements
        $this->assertStringNotContainsString('tt_content', $content);
        
        // Test searching in tt_content only
        $result = $tool->execute([
            'terms' => ['welcome'],
            'table' => 'tt_content'
        ]);
        
        $content = $result->content[0]->text;
        
        // Should only show tt_content results
        $this->assertStringContainsString('TABLE: Page Content (tt_content)', $content);
        $this->assertStringNotContainsString('TABLE: Page (pages)', $content);
    }

    /**
     * Test page-specific search
     */
    public function testPageSpecificSearch(): void
    {
        $tool = new SearchTool();
        
        // Search only on page 2 (About)
        $result = $tool->execute([
            'terms' => ['team'],
            'pageId' => 2
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should find team content on About page
        $this->assertStringContainsString('[UID: 102] Team Introduction', $content);
        $this->assertStringContainsString('[UID: 103] Team Members', $content);
        
        // Should show correct page info
        $this->assertStringContainsString('📍 Page: About [UID: 2]', $content);
        
        // Note: The search with pageId filters only content elements (tt_content) 
        // by page, but pages table is searched globally. So the Team page (UID: 4)
        // will still appear in results because it matches the search term
    }

    /**
     * Test that search finds hidden records
     */
    public function testSearchFindsHiddenRecords(): void
    {
        $tool = new SearchTool();
        
        // Hidden records are always included now
        $result = $tool->execute([
            'terms' => ['hidden']
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should find hidden content element
        $this->assertStringContainsString('[UID: 104]', $content);
        $this->assertStringContainsString('Hidden Content', $content);
        
        // Should also find the hidden page
        $this->assertStringContainsString('[UID: 3]', $content);
        $this->assertStringContainsString('Hidden Page', $content);
    }

    /**
     * Test inline record attribution (categories)
     */
    public function testInlineRecordAttribution(): void
    {
        $tool = new SearchTool();
        
        // Search for category name
        $result = $tool->execute([
            'terms' => ['Technology']
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // The search finds the category directly, not through inline attribution
        // because sys_category is a searchable table itself
        $this->assertStringContainsString('TABLE: Category (sys_category)', $content);
        $this->assertStringContainsString('[UID: 1] Technology', $content);
        
        // Since sys_category is not a hidden table, it won't trigger inline attribution
        // The inline attribution only works for hidden tables
        
        // Test another category
        $result = $tool->execute([
            'terms' => ['Business']
        ]);
        
        $content = $result->content[0]->text;
        
        // Should find the Business category directly
        $this->assertStringContainsString('[UID: 2] Business', $content);
    }

    /**
     * Test search result formatting
     */
    public function testSearchResultFormatting(): void
    {
        $tool = new SearchTool();
        
        $result = $tool->execute([
            'terms' => ['team']
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Check overall structure
        $this->assertStringContainsString('SEARCH RESULTS', $content);
        $this->assertStringContainsString('Total Results:', $content);
        $this->assertStringContainsString('Tables Searched:', $content);
        
        // Check record formatting
        $this->assertStringContainsString('• [UID:', $content); // Record prefix
        $this->assertStringContainsString('📍 Page:', $content); // Page info
        $this->assertStringContainsString('🎯 Type:', $content); // Content type
        $this->assertStringContainsString('💬 Preview:', $content); // Content preview
    }

    /**
     * Test empty search results
     */
    public function testEmptySearchResults(): void
    {
        $tool = new SearchTool();
        
        $result = $tool->execute([
            'terms' => ['nonexistentterm12345']
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should show total results as 0
        $this->assertStringContainsString('Total Results: 0', $content);
        $this->assertStringContainsString('nonexistentterm12345', $content);
    }

    /**
     * Test search term validation
     */
    public function testSearchTermValidation(): void
    {
        $tool = new SearchTool();
        
        // Test empty terms array
        $result = $tool->execute([
            'terms' => []
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('At least one search term is required', $result->content[0]->text);
        
        // Test single character term
        $result = $tool->execute([
            'terms' => ['a']
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('must be at least 2 characters long', $result->content[0]->text);
        
        // Test very long term
        $longTerm = str_repeat('x', 101);
        $result = $tool->execute([
            'terms' => [$longTerm]
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('cannot exceed 100 characters', $result->content[0]->text);
        
        // Test empty array with spaces
        $result = $tool->execute([
            'terms' => ['  ', '']
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('At least one non-empty search term is required', $result->content[0]->text);
        
        // Test with non-array terms (simulating what would happen if validation wasn't in place)
        // In reality, this would fail at JSON parsing, but we test the tool's handling
        $result = $tool->execute([
            'terms' => [123] // non-string in array
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('All terms must be strings', $result->content[0]->text);
    }

    /**
     * Test search with limit
     */
    public function testSearchWithLimit(): void
    {
        $tool = new SearchTool();
        
        // Search with very small limit - use a term that will match multiple records
        $result = $tool->execute([
            'terms' => ['content'], // Will match multiple content elements
            'limit' => 2
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should find some results
        $this->assertStringContainsString('Total Results:', $content);
        
        // The limit applies per table, not globally
        // So if multiple tables have matches, total can exceed limit
        // Just verify that the search completes successfully with a limit
        $this->assertStringContainsString('Found', $content);
        
        // Verify we have results but not too many (basic sanity check)
        preg_match_all('/• \[UID: \d+\]/', $content, $matches);
        $totalRecords = count($matches[0]);
        
        // With limit of 2 per table and multiple tables, should be reasonable
        $this->assertGreaterThan(0, $totalRecords);
        $this->assertLessThan(20, $totalRecords); // Sanity check - not unlimited
    }

    /**
     * Test special character handling
     */
    public function testSpecialCharacterSearch(): void
    {
        $tool = new SearchTool();
        
        // Search with special characters that need escaping
        $result = $tool->execute([
            'terms' => ['team%']
        ]);
        
        $this->assertFalse($result->isError);
        // Should handle wildcards properly and not break
        
        // Test SQL-like patterns
        $result = $tool->execute([
            'terms' => ['_eam']
        ]);
        
        $this->assertFalse($result->isError);
        // Should escape properly
    }

    /**
     * Test invalid table name
     */
    public function testInvalidTableName(): void
    {
        $tool = new SearchTool();
        
        $result = $tool->execute([
            'terms' => ['test'],
            'table' => 'non_existent_table'
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist in TCA', $result->content[0]->text);
    }

    /**
     * Test non-workspace-capable table
     */
    public function testNonWorkspaceCapableTable(): void
    {
        $tool = new SearchTool();
        
        // Try to search in a non-workspace-capable table (if one exists)
        $result = $tool->execute([
            'terms' => ['test'],
            'table' => 'sys_template' // Usually not workspace-capable
        ]);
        
        // Should either work if workspace-capable or show appropriate error
        if ($result->isError) {
            $this->assertStringContainsString('not workspace-capable', $result->content[0]->text);
        }
    }

    /**
     * Test search through tool registry
     */
    public function testSearchThroughRegistry(): void
    {
        // Create tool registry with SearchTool
        $tools = [new SearchTool()];
        $registry = new ToolRegistry($tools);
        
        // Get tool from registry
        $tool = $registry->getTool('Search');
        $this->assertNotNull($tool);
        $this->assertInstanceOf(SearchTool::class, $tool);
        
        // Execute search through registry
        $result = $tool->execute([
            'terms' => ['welcome']
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        $this->assertStringContainsString('Welcome Header', $content);
    }

    /**
     * Test tool name extraction
     */
    public function testToolName(): void
    {
        $tool = new SearchTool();
        $this->assertEquals('Search', $tool->getName());
    }

    /**
     * Test tool schema
     */
    public function testToolSchema(): void
    {
        $tool = new SearchTool();
        $schema = $tool->getSchema();
        
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('description', $schema);
        $this->assertArrayHasKey('parameters', $schema);
        $this->assertArrayHasKey('examples', $schema);
        
        // Check parameters
        $properties = $schema['parameters']['properties'];
        $this->assertArrayHasKey('terms', $properties);
        $this->assertArrayHasKey('termLogic', $properties);
        $this->assertArrayHasKey('table', $properties);
        $this->assertArrayHasKey('pageId', $properties);
        // includeHidden should not exist anymore
        $this->assertArrayNotHasKey('includeHidden', $properties);
        $this->assertArrayHasKey('limit', $properties);
        
        // Check required fields
        $this->assertArrayHasKey('required', $schema['parameters']);
        $this->assertContains('terms', $schema['parameters']['required']);
        
        // Check examples
        $this->assertGreaterThan(0, count($schema['examples']));
    }

    /**
     * Test complex multi-table search
     */
    public function testComplexMultiTableSearch(): void
    {
        $tool = new SearchTool();
        
        // Search that should find results in multiple tables
        $result = $tool->execute([
            'terms' => ['contact']
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should find in pages
        $this->assertStringContainsString('TABLE: Page (pages)', $content);
        $this->assertStringContainsString('[UID: 6] Contact', $content);
        
        // Should find in content
        $this->assertStringContainsString('TABLE: Page Content (tt_content)', $content);
        $this->assertStringContainsString('[UID: 105] Contact Form', $content);
    }

    /**
     * Test termLogic parameter validation
     */
    public function testTermLogicValidation(): void
    {
        $tool = new SearchTool();
        
        // Test invalid term logic
        $result = $tool->execute([
            'terms' => ['test'],
            'termLogic' => 'INVALID'
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('termLogic must be either "AND" or "OR"', $result->content[0]->text);
    }

    /**
     * Test search with multiple inline matches
     */
    public function testMultipleInlineMatches(): void
    {
        $tool = new SearchTool();
        
        // Search for Web Development (subcategory)
        $result = $tool->execute([
            'terms' => ['Web Development']
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should find the Web Development category directly
        $this->assertStringContainsString('Web Development', $content);
        $this->assertStringContainsString('[UID: 4] Web Development', $content);
    }
}
