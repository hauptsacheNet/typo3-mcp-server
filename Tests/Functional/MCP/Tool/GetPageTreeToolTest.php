<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\MCP\ToolRegistry;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetPageTreeToolTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import page fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
    }

    /**
     * Test getting page tree directly through the tool
     */
    public function testGetPageTreeDirectly(): void
    {
        $tool = new GetPageTreeTool();
        
        // Test getting page tree from root (pid=0)
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 2,
            'includeHidden' => false
        ]);
        
        // Verify result structure
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $content = $result->content[0]->text;
        
        // Verify the tree contains expected pages
        $this->assertStringContainsString('[1] Home', $content);
        $this->assertStringContainsString('[2] About Us', $content);
        $this->assertStringContainsString('[6] Contact', $content);
        
        // Hidden page should not be included
        $this->assertStringNotContainsString('[3] Hidden Page', $content);
    }

    /**
     * Test getting page tree with hidden pages included
     */
    public function testGetPageTreeWithHiddenPages(): void
    {
        $tool = new GetPageTreeTool();
        
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 2,
            'includeHidden' => true
        ]);
        
        $content = $result->content[0]->text;
        
        // Now hidden page should be included
        $this->assertStringContainsString('[3] Hidden Page [HIDDEN]', $content);
    }

    /**
     * Test getting page tree from a specific page
     */
    public function testGetPageTreeFromSpecificPage(): void
    {
        $tool = new GetPageTreeTool();
        
        // Get tree starting from page 1 (Home)
        $result = $tool->execute([
            'startPage' => 1,
            'depth' => 2,
            'includeHidden' => false
        ]);
        
        $content = $result->content[0]->text;
        
        // Should only contain subpages of Home
        $this->assertStringContainsString('[2] About Us', $content);
        $this->assertStringNotContainsString('[1] Home', $content);
        $this->assertStringNotContainsString('[6] Contact', $content);
        
        // Should include sub-subpages
        $this->assertStringContainsString('[4] Our Team', $content);
        $this->assertStringContainsString('[5] Mission', $content);
    }

    /**
     * Test depth limitation
     */
    public function testDepthLimitation(): void
    {
        $tool = new GetPageTreeTool();
        
        // Get tree with depth 1
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 1,
            'includeHidden' => false
        ]);
        
        $content = $result->content[0]->text;
        
        // Should contain root pages
        $this->assertStringContainsString('[1] Home', $content);
        $this->assertStringContainsString('[6] Contact', $content);
        
        // Should show subpage count but not the actual subpages
        $this->assertStringContainsString('(1 subpages)', $content);
        
        // Should not contain second-level pages
        $this->assertStringNotContainsString('[2] About Us', $content);
        $this->assertStringNotContainsString('[4] Our Team', $content);
    }

    /**
     * Test getting page tree through ToolRegistry
     */
    public function testGetPageTreeThroughRegistry(): void
    {
        // Create tool registry with the GetPageTreeTool
        $tools = [new GetPageTreeTool()];
        $registry = new ToolRegistry($tools);
        
        // Get tool from registry
        $tool = $registry->getTool('GetPageTree');
        $this->assertNotNull($tool);
        $this->assertInstanceOf(GetPageTreeTool::class, $tool);
        
        // Execute through registry
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 1,
            'includeHidden' => false
        ]);
        
        $content = $result->content[0]->text;
        $this->assertStringContainsString('[1] Home', $content);
        $this->assertStringContainsString('[6] Contact', $content);
    }

    /**
     * Test tool name extraction
     */
    public function testToolName(): void
    {
        $tool = new GetPageTreeTool();
        $this->assertEquals('GetPageTree', $tool->getName());
    }

    /**
     * Test tool schema
     */
    public function testToolSchema(): void
    {
        $tool = new GetPageTreeTool();
        $schema = $tool->getSchema();
        
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('description', $schema);
        $this->assertArrayHasKey('parameters', $schema);
        $this->assertArrayHasKey('properties', $schema['parameters']);
        $this->assertArrayHasKey('startPage', $schema['parameters']['properties']);
        $this->assertArrayHasKey('depth', $schema['parameters']['properties']);
        $this->assertArrayHasKey('includeHidden', $schema['parameters']['properties']);
    }
}