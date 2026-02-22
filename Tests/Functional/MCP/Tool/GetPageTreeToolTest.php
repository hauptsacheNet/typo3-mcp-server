<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\LanguageService;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
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
     * Test depth limitation with proper tree structure verification
     */
    public function testDepthLimitation(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        
        // Create a known page structure for testing
        $this->createTestPageStructure();
        
        // Test 1: Depth 1 - should only show immediate children
        $result = $tool->execute([
            'startPage' => 1000, // Our test root
            'depth' => 1
        ]);
        
        $content = $result->content[0]->text;
        
        // Verify only direct children are shown
        $this->assertStringContainsString('[1001] Level 1 - Page A', $content);
        $this->assertStringContainsString('[1002] Level 1 - Page B', $content);
        
        // Verify subpage count is shown
        $this->assertStringContainsString('(2 subpages)', $content); // Page A has 2 children
        $this->assertStringContainsString('(1 subpages)', $content); // Page B has 1 child (tool uses "subpages" even for 1)
        
        // Verify grandchildren are NOT shown
        $this->assertStringNotContainsString('[1003] Level 2 - Page A1', $content);
        $this->assertStringNotContainsString('[1004] Level 2 - Page A2', $content);
        $this->assertStringNotContainsString('[1005] Level 2 - Page B1', $content);
        
        // Test 2: Depth 2 - should show children and grandchildren
        $result = $tool->execute([
            'startPage' => 1000,
            'depth' => 2
        ]);
        
        $content = $result->content[0]->text;
        
        // Verify children are shown
        $this->assertStringContainsString('[1001] Level 1 - Page A', $content);
        $this->assertStringContainsString('[1002] Level 1 - Page B', $content);
        
        // Verify grandchildren are shown with proper indentation (includes - prefix)
        $this->assertStringContainsString('  - [1003] Level 2 - Page A1', $content);
        $this->assertStringContainsString('  - [1004] Level 2 - Page A2', $content);
        $this->assertStringContainsString('  - [1005] Level 2 - Page B1', $content);
        
        // Verify great-grandchildren are NOT shown
        $this->assertStringNotContainsString('[1006] Level 3 - Page A1a', $content);
        
        // But verify subpage count for pages that have deeper children
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (strpos($line, '[1003] Level 2 - Page A1') !== false) {
                $this->assertStringContainsString('(1 subpages)', $line, 'Page A1 should show it has 1 subpage');
            }
        }
        
        // Test 3: Depth 3 - should show full tree
        $result = $tool->execute([
            'startPage' => 1000,
            'depth' => 3
        ]);
        
        $content = $result->content[0]->text;
        
        // Verify all levels are shown with proper indentation
        $this->assertStringContainsString('[1001] Level 1 - Page A', $content);
        $this->assertStringContainsString('  - [1003] Level 2 - Page A1', $content);
        $this->assertStringContainsString('    - [1006] Level 3 - Page A1a', $content);
        
        // Verify proper nesting by checking indentation pattern
        $this->assertCorrectTreeStructure($content);
    }
    
    /**
     * Create a test page structure for depth testing
     */
    private function createTestPageStructure(): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');
        
        // Create root page
        $connection->insert('pages', [
            'uid' => 1000,
            'pid' => 0,
            'title' => 'Test Root',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root',
            'tstamp' => time(),
            'crdate' => time()
        ]);
        
        // Level 1 pages
        $connection->insert('pages', [
            'uid' => 1001,
            'pid' => 1000,
            'title' => 'Level 1 - Page A',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-a',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 100
        ]);
        
        $connection->insert('pages', [
            'uid' => 1002,
            'pid' => 1000,
            'title' => 'Level 1 - Page B',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-b',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 200
        ]);
        
        // Level 2 pages
        $connection->insert('pages', [
            'uid' => 1003,
            'pid' => 1001,
            'title' => 'Level 2 - Page A1',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-a/page-a1',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 100
        ]);
        
        $connection->insert('pages', [
            'uid' => 1004,
            'pid' => 1001,
            'title' => 'Level 2 - Page A2',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-a/page-a2',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 200
        ]);
        
        $connection->insert('pages', [
            'uid' => 1005,
            'pid' => 1002,
            'title' => 'Level 2 - Page B1',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-b/page-b1',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 100
        ]);
        
        // Level 3 page
        $connection->insert('pages', [
            'uid' => 1006,
            'pid' => 1003,
            'title' => 'Level 3 - Page A1a',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/test-root/page-a/page-a1/page-a1a',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 100
        ]);
    }
    
    /**
     * Verify tree structure has correct parent-child relationships
     */
    private function assertCorrectTreeStructure(string $content): void
    {
        $lines = explode("\n", $content);
        $currentIndent = -1;
        $indentStack = [];
        
        foreach ($lines as $line) {
            if (preg_match('/^(\s*)(?:- )?\[(\d+)\]/', $line, $matches)) {
                $indent = strlen($matches[1]) / 2; // Assuming 2 spaces per level
                $uid = (int)$matches[2];
                
                // Verify indentation increases by at most 1 level
                if ($currentIndent >= 0 && $indent > $currentIndent + 1) {
                    $this->fail("Invalid tree structure: Indentation jumped from level $currentIndent to $indent at UID $uid");
                }
                
                // Track parent-child relationships
                if ($indent > $currentIndent) {
                    // Going deeper
                    $indentStack[] = $uid;
                } elseif ($indent < $currentIndent) {
                    // Going back up
                    $levelsUp = $currentIndent - $indent;
                    for ($i = 0; $i < $levelsUp; $i++) {
                        array_pop($indentStack);
                    }
                }
                
                $currentIndent = $indent;
            }
        }
        
        $this->assertTrue(true, 'Tree structure is valid');
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

    /**
     * Test that subpages beyond the limit are truncated at depth > 1,
     * but first-layer children are never truncated.
     */
    public function testSubpageTruncation(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');

        // Create: grandparent -> parent (with 15 children)
        $connection->insert('pages', [
            'uid' => 2000,
            'pid' => 0,
            'title' => 'Truncation Test Root',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/truncation-root',
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        $connection->insert('pages', [
            'uid' => 2001,
            'pid' => 2000,
            'title' => 'Parent With Many Children',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/truncation-root/parent',
            'tstamp' => time(),
            'crdate' => time(),
            'sorting' => 100,
        ]);

        // Create 15 children of page 2001 (exceeds the 10-per-parent limit)
        for ($i = 1; $i <= 15; $i++) {
            $connection->insert('pages', [
                'uid' => 2100 + $i,
                'pid' => 2001,
                'title' => 'Child ' . $i,
                'deleted' => 0,
                'hidden' => 0,
                'doktype' => 1,
                'slug' => '/truncation-root/parent/child-' . $i,
                'tstamp' => time(),
                'crdate' => time(),
                'sorting' => $i * 100,
            ]);
        }

        // Test: depth=2 from grandparent should truncate second-layer children
        $result = $tool->execute([
            'startPage' => 2000,
            'depth' => 2,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // First layer (direct child of startPage) should NOT be truncated
        $this->assertStringContainsString('[2001] Parent With Many Children', $content);

        // Second layer: first 10 children should be shown
        for ($i = 1; $i <= 10; $i++) {
            $this->assertStringContainsString('[' . (2100 + $i) . '] Child ' . $i, $content);
        }

        // Children beyond the limit should NOT appear
        for ($i = 11; $i <= 15; $i++) {
            $this->assertStringNotContainsString('[' . (2100 + $i) . '] Child ' . $i, $content);
        }

        // Truncation notice should appear with actionable hint
        $this->assertStringContainsString('showing 10 of 15 subpages', $content);
        $this->assertStringContainsString('use GetPageTree with startPage: 2001 to see all', $content);
    }

    /**
     * Test that first-layer children are never truncated even with many pages.
     */
    public function testFirstLayerNotTruncated(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');

        // Create a parent page with 15 direct children (first layer)
        $connection->insert('pages', [
            'uid' => 3000,
            'pid' => 0,
            'title' => 'First Layer Test Root',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/first-layer-root',
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        for ($i = 1; $i <= 15; $i++) {
            $connection->insert('pages', [
                'uid' => 3000 + $i,
                'pid' => 3000,
                'title' => 'First Layer Child ' . $i,
                'deleted' => 0,
                'hidden' => 0,
                'doktype' => 1,
                'slug' => '/first-layer-root/child-' . $i,
                'tstamp' => time(),
                'crdate' => time(),
                'sorting' => $i * 100,
            ]);
        }

        // depth=1 from parent: all 15 first-layer children should appear
        $result = $tool->execute([
            'startPage' => 3000,
            'depth' => 1,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // All 15 children should be shown (no truncation for first layer)
        for ($i = 1; $i <= 15; $i++) {
            $this->assertStringContainsString('[' . (3000 + $i) . '] First Layer Child ' . $i, $content);
        }

        // No truncation notice should appear
        $this->assertStringNotContainsString('showing', $content);
        $this->assertStringNotContainsString('to see all', $content);
    }
}