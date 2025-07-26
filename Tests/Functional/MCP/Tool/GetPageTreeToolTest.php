<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\LanguageService;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetPageTreeToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected bool $importDefaultFixtures = true;

    protected function setUp(): void
    {
        parent::setUp();
        
        if ($this->importDefaultFixtures) {
            // Import page fixtures
            $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        }
        
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        
        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUser(1);
    }

    /**
     * Test getting page tree directly through the tool
     */
    public function testGetPageTreeDirectly(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        
        // Test getting page tree from root (pid=0)
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 2
        ]);
        
        // Verify result structure
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $content = $result->content[0]->text;
        
        // Verify the tree contains expected pages
        $this->assertStringContainsString('[1] Home', $content);
        $this->assertStringContainsString('[2] About Us', $content);
        $this->assertStringContainsString('[6] Contact', $content);
        
        // Hidden page should now be included (always show hidden records)
        $this->assertStringContainsString('[3] Hidden Page', $content);
    }

    /**
     * Test getting page tree from a specific page
     */
    public function testGetPageTreeFromSpecificPage(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        
        // Get tree starting from page 1 (Home)
        $result = $tool->execute([
            'startPage' => 1,
            'depth' => 2
        ]);
        
        $content = $result->content[0]->text;
        
        // Should contain subpages of Home (now includes Contact)
        $this->assertStringContainsString('[2] About Us', $content);
        $this->assertStringNotContainsString('[1] Home', $content);
        $this->assertStringContainsString('[6] Contact', $content);
        
        // Should include sub-subpages
        $this->assertStringContainsString('[4] Our Team', $content);
        $this->assertStringContainsString('[5] Mission', $content);
    }

    /**
     * Test depth limitation
     */
    public function testDepthLimitation(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        
        // Get tree with depth 1
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 1
        ]);
        
        $content = $result->content[0]->text;
        
        // Should contain only root page (Home)
        $this->assertStringContainsString('[1] Home', $content);
        $this->assertStringNotContainsString('[6] Contact', $content); // Contact is now a subpage
        
        // Should show subpage count but not the actual subpages (Home now has 4 subpages including hidden)
        $this->assertStringContainsString('(4 subpages)', $content);
        
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
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tools = [new GetPageTreeTool($siteInformationService, $languageService)];
        $registry = new ToolRegistry($tools);
        
        // Get tool from registry
        $tool = $registry->getTool('GetPageTree');
        $this->assertNotNull($tool);
        $this->assertInstanceOf(GetPageTreeTool::class, $tool);
        
        // Execute through registry
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 1
        ]);
        
        $content = $result->content[0]->text;
        $this->assertStringContainsString('[1] Home', $content);
        $this->assertStringNotContainsString('[6] Contact', $content); // Contact is now a subpage of Home
    }

    /**
     * Test tool name extraction
     */
    public function testToolName(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        $this->assertEquals('GetPageTree', $tool->getName());
    }

    /**
     * Test tool schema
     */
    public function testToolSchema(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        $schema = $tool->getSchema();
        
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('description', $schema);
        $this->assertArrayHasKey('inputSchema', $schema);
        $this->assertArrayHasKey('properties', $schema['inputSchema']);
        $this->assertArrayHasKey('startPage', $schema['inputSchema']['properties']);
        $this->assertArrayHasKey('depth', $schema['inputSchema']['properties']);
    }
    
    /**
     * Test enhanced output with doktype labels
     */
    public function testEnhancedOutputWithDoktypeLabels(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        
        // Import content fixtures to have some records to count
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        
        // Test getting page tree from root
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 2
        ]);
        
        $content = $result->content[0]->text;
        
        // Verify doktype labels are included
        $this->assertStringContainsString('[1] Home [Page]', $content);
        $this->assertStringContainsString('[2] About Us [Page]', $content);
        
        // Verify record counts are included (page 1 has 3 content elements)
        $this->assertStringContainsString('[tt_content: 3]', $content);
    }
}