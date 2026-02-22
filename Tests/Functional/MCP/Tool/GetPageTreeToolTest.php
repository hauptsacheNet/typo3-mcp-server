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
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

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
     * Test that adaptive depth limits the tree when page count exceeds budget.
     *
     * Creates a wide tree: root → 60 children → 2 grandchildren each (120).
     * Level 1 = 60 pages (under 100 budget), Level 2 = +120 = 180 total (over 100).
     * Expected: both levels shown (level that pushes over budget is included),
     * but no further expansion. A depth-limited note should appear.
     */
    public function testAdaptiveDepthLimitsOnLargeTree(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);

        $this->createWidePageTree();

        // Request deep depth (default would be 10), but the adaptive algorithm should limit it
        $result = $tool->execute([
            'startPage' => 2000,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // Level 1 pages should be shown
        $this->assertStringContainsString('[2001]', $content, 'First level-1 page should be shown');
        $this->assertStringContainsString('[2060]', $content, 'Last level-1 page should be shown');

        // Level 2 pages should also be shown (the level that pushed us over budget is included)
        $this->assertStringContainsString('[3001]', $content, 'First level-2 page should be shown');
        $this->assertStringContainsString('[3120]', $content, 'Last level-2 page should be shown');

        // Level 3 pages should NOT be shown (budget exceeded, no further expansion)
        $this->assertStringNotContainsString('[4001]', $content, 'Level-3 pages should not be shown');

        // The depth-limited note should be present
        $this->assertStringContainsString('Tree depth limited to', $content, 'Should show depth limitation note');
        $this->assertStringContainsString('Use startPage to explore specific subtrees', $content);

        // Level 2 pages should show subpage counts (they have children we didn't expand)
        $this->assertStringContainsString('(1 subpages)', $content, 'Level-2 pages should show subpage counts');
    }

    /**
     * Test that small trees are NOT depth-limited even with high default depth.
     */
    public function testAdaptiveDepthDoesNotLimitSmallTree(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);

        $this->createTestPageStructure();

        // Use default depth (no depth parameter) — should show entire tree without limitation
        $result = $tool->execute([
            'startPage' => 1000,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // All levels should be shown
        $this->assertStringContainsString('[1001] Level 1 - Page A', $content);
        $this->assertStringContainsString('[1003] Level 2 - Page A1', $content);
        $this->assertStringContainsString('[1006] Level 3 - Page A1a', $content);

        // No depth limitation note
        $this->assertStringNotContainsString('Tree depth limited', $content, 'Small tree should not show depth limitation note');
    }

    /**
     * Test that explicit depth parameter is respected as upper bound.
     */
    public function testExplicitDepthIsRespectedAsUpperBound(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);

        $this->createTestPageStructure();

        // Request depth=2 explicitly for a small tree — should stop at depth 2 without budget note
        $result = $tool->execute([
            'startPage' => 1000,
            'depth' => 2,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // Level 1 and 2 shown
        $this->assertStringContainsString('[1001] Level 1 - Page A', $content);
        $this->assertStringContainsString('[1003] Level 2 - Page A1', $content);

        // Level 3 NOT shown (depth limit, not budget limit)
        $this->assertStringNotContainsString('[1006] Level 3 - Page A1a', $content);

        // No budget limitation note (stopped by explicit depth, not budget)
        $this->assertStringNotContainsString('Tree depth limited', $content);
    }

    /**
     * Create a wide page tree for adaptive depth testing.
     *
     * Structure:
     * - [2000] Wide Root (pid=0)
     *   - [2001..2060] 60 level-1 pages (pid=2000)
     *     - [3001..3120] 2 level-2 pages each (pid=200X), 120 total
     *       - [4001..4120] 1 level-3 page each (pid=300X), 120 total
     */
    private function createWidePageTree(): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');

        $connection->insert('pages', [
            'uid' => 2000,
            'pid' => 0,
            'title' => 'Wide Root',
            'deleted' => 0,
            'hidden' => 0,
            'doktype' => 1,
            'slug' => '/wide-root',
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        // 60 level-1 pages
        for ($i = 1; $i <= 60; $i++) {
            $connection->insert('pages', [
                'uid' => 2000 + $i,
                'pid' => 2000,
                'title' => 'L1 Page ' . $i,
                'deleted' => 0,
                'hidden' => 0,
                'doktype' => 1,
                'slug' => '/wide-root/l1-page-' . $i,
                'tstamp' => time(),
                'crdate' => time(),
                'sorting' => $i * 10,
            ]);
        }

        // 2 level-2 pages per level-1 page (120 total)
        $l2Uid = 3001;
        for ($parent = 1; $parent <= 60; $parent++) {
            for ($child = 1; $child <= 2; $child++) {
                $connection->insert('pages', [
                    'uid' => $l2Uid,
                    'pid' => 2000 + $parent,
                    'title' => 'L2 Page ' . $l2Uid,
                    'deleted' => 0,
                    'hidden' => 0,
                    'doktype' => 1,
                    'slug' => '/wide-root/l1-page-' . $parent . '/l2-page-' . $l2Uid,
                    'tstamp' => time(),
                    'crdate' => time(),
                    'sorting' => $child * 10,
                ]);
                $l2Uid++;
            }
        }

        // 1 level-3 page per level-2 page (120 total) — these should NOT be shown
        for ($l2 = 3001; $l2 <= 3120; $l2++) {
            $connection->insert('pages', [
                'uid' => $l2 + 1000, // 4001..4120
                'pid' => $l2,
                'title' => 'L3 Page ' . ($l2 + 1000),
                'deleted' => 0,
                'hidden' => 0,
                'doktype' => 1,
                'slug' => '/deep/l3-page-' . ($l2 + 1000),
                'tstamp' => time(),
                'crdate' => time(),
                'sorting' => 10,
            ]);
        }
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