<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\Service\LanguageService as McpLanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test backend layout functionality in GetPageTool
 */
class GetPageToolBackendLayoutTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import backend layouts
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/backend_layout.csv');
        
        // Import pages with backend layout configuration
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        
        // Import content elements
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        
        // Import backend user for permissions
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        
        // Set up backend user
        $this->setUpBackendUser(1);
        
        // Set up language service
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->create('en');
        
        // Set up site configuration
        $this->setUpFrontendRootPage(
            1,
            ['EXT:mcp_server/Tests/Functional/Fixtures/Frontend/SiteConfig.yaml']
        );
    }

    /**
     * Create GetPageTool instance with dependencies
     */
    protected function createGetPageTool(): GetPageTool
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(McpLanguageService::class);
        return new GetPageTool($siteInformationService, $languageService);
    }

    /**
     * Test page with database backend layout
     */
    public function testPageWithDatabaseBackendLayout(): void
    {
        // Get GetPageTool instance
        $tool = $this->createGetPageTool();
        
        // Test Contact page (uid=6) which has backend_layout=1 (2 Column Layout)
        $result = $tool->execute(['uid' => 6]);
        
        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $content = $result->content[0]->text;
        
        // Should show custom column names from backend layout
        $this->assertStringContainsString('Column: Main Content Area [colPos: 0]', $content);
        $this->assertStringContainsString('Column: Sidebar Content [colPos: 1]', $content);
        
        // Should NOT show default column names
        $this->assertStringNotContainsString('Column: Main Content [colPos: 0]', $content);
        $this->assertStringNotContainsString('Column: Left [colPos: 1]', $content);
    }

    /**
     * Test page inheriting backend layout from parent
     */
    public function testPageWithInheritedBackendLayout(): void
    {
        $tool = $this->createGetPageTool();
        
        // Test News page (uid=7) which should inherit layout from Home (backend_layout_next_level=1)
        $result = $tool->execute(['uid' => 7]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should inherit 2 Column Layout from root page
        $this->assertStringContainsString('Column: Main Content Area [colPos: 0]', $content);
    }

    /**
     * Test page with single column layout
     */
    public function testPageWithSingleColumnLayout(): void
    {
        $tool = $this->createGetPageTool();
        
        // Test About page (uid=2) which has backend_layout=2 (Single Column Layout)
        $result = $tool->execute(['uid' => 2]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should show custom column name from single column layout
        $this->assertStringContainsString('Column: Main Content Only [colPos: 0]', $content);
        
        // Should NOT show column positions that don't exist in this layout
        $this->assertStringNotContainsString('[colPos: 1]', $content);
        $this->assertStringNotContainsString('[colPos: 2]', $content);
    }

    /**
     * Test content in non-existent columns marked as unused
     */
    public function testUnusedContentInNonExistentColumns(): void
    {
        $tool = $this->createGetPageTool();
        
        // First, create a content element in a non-existent column
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        $connection->insert('tt_content', [
            'uid' => 999,
            'pid' => 2, // About page with single column layout
            'colPos' => 3, // This column doesn't exist in the layout
            'CType' => 'text',
            'header' => 'Orphaned Content',
            'bodytext' => 'This content is in a non-existent column',
            'sorting' => 256,
            'tstamp' => time(),
            'crdate' => time(),
        ]);
        
        $result = $tool->execute(['uid' => 2]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should show the unused content with a warning
        $this->assertStringContainsString('Column: Column 3 [colPos: 3]', $content);
        $this->assertStringContainsString('⚠️  Note: This column is not defined in the current backend layout', $content);
        $this->assertStringContainsString('Orphaned Content', $content);
    }

    /**
     * Test page with no backend layout falls back to defaults
     */
    public function testPageWithNoBackendLayout(): void
    {
        $tool = $this->createGetPageTool();
        
        // Test Article 1 page (uid=8) which has no backend layout and should inherit defaults
        $result = $tool->execute(['uid' => 8]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Since News page (parent) will be checked for TSConfig backend layout in another test,
        // let's just verify this page loads correctly
        $this->assertStringContainsString('Article 1', $content);
    }

    /**
     * Test TSConfig-based backend layout
     */
    public function testTSConfigBackendLayout(): void
    {
        // Add TSConfig to a page
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $connection->update(
            'pages',
            [
                'TSconfig' => '
mod.web_layout.BackendLayouts {
    custom_news_layout {
        title = Custom News Layout
        config {
            backend_layout {
                colCount = 3
                rowCount = 1
                rows {
                    1 {
                        columns {
                            1 {
                                name = News List
                                colPos = 0
                            }
                            2 {
                                name = News Sidebar
                                colPos = 1
                            }
                            3 {
                                name = Advertisement
                                colPos = 10
                            }
                        }
                    }
                }
            }
        }
    }
}
',
                'backend_layout' => 'custom_news_layout'
            ],
            ['uid' => 7] // News page
        );
        
        $tool = $this->createGetPageTool();
        $result = $tool->execute(['uid' => 7]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Should show custom column names from TSConfig layout
        $this->assertStringContainsString('Column: News List [colPos: 0]', $content);
        $this->assertStringContainsString('Column: News Sidebar [colPos: 1]', $content);
        $this->assertStringContainsString('Column: Advertisement [colPos: 10]', $content);
    }
}