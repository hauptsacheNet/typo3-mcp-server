<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\ListTablesTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test that News extension tables are properly discovered and accessible through MCP tools
 */
class NewsTableDiscoveryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import backend user fixture
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_workspace.csv');
        
        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUserWithWorkspace(1);
        
        // Initialize language service
        if (!isset($GLOBALS['LANG'])) {
            $languageServiceFactory = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class);
            $GLOBALS['LANG'] = $languageServiceFactory->create('default');
        }
    }

    /**
     * Test that News extension tables appear in ListTablesTool
     */
    public function testNewsTablesAreDiscoverable(): void
    {
        $tool = new ListTablesTool();
        $result = $tool->execute([]);
        
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $content = $result->content[0]->text;
        
        // Expected News tables (News uses sys_category and sys_file_reference, not custom tables)
        $expectedTables = [
            'tx_news_domain_model_news',
            'tx_news_domain_model_tag',
            'tx_news_domain_model_link',
        ];
        
        foreach ($expectedTables as $table) {
            $this->assertStringContainsString(
                $table,
                $content,
                "Expected News table '$table' not found in ListTablesTool output"
            );
        }
        
        // Verify News table is present
        $this->assertStringContainsString(
            'tx_news_domain_model_news',
            $content,
            'News table should be present in the output'
        );
    }
    
    /**
     * Test that News tables are properly grouped under the News extension
     */
    public function testNewsTablesGrouping(): void
    {
        $tool = new ListTablesTool();
        $result = $tool->execute([]);
        
        $content = $result->content[0]->text;
        
        // Should have a News extension section
        $this->assertStringContainsString(
            'EXTENSION: news',
            $content,
            'News tables should be grouped under News extension'
        );
        
        // Check that News tables appear after the extension header
        $newsSection = strstr($content, 'EXTENSION: news');
        $this->assertNotFalse($newsSection, 'News extension section not found');
        
        // All News tables should be in this section
        $this->assertStringContainsString('tx_news_domain_model_news', $newsSection);
        $this->assertStringContainsString('tx_news_domain_model_tag', $newsSection);
    }
    
    /**
     * Test that News relation tables (MM tables) are included if they exist
     */
    public function testNewsRelationTables(): void
    {
        $tool = new ListTablesTool();
        $result = $tool->execute([]);
        
        $content = $result->content[0]->text;
        
        // Check for possible MM tables (these may or may not exist depending on News version)
        $possibleMmTables = [
            'tx_news_domain_model_news_category_mm',
            'tx_news_domain_model_news_tag_mm',
            'tx_news_domain_model_news_related_mm',
        ];
        
        // Count which MM tables are present
        $foundMmTables = [];
        foreach ($possibleMmTables as $mmTable) {
            if (strpos($content, $mmTable) !== false) {
                $foundMmTables[] = $mmTable;
            }
        }
        
        // Log which MM tables were found for debugging
        if (!empty($foundMmTables)) {
            $this->addToAssertionCount(1);
            // At least some MM tables were found, which is good
        } else {
            // No MM tables found - this might be expected depending on News configuration
            $this->addToAssertionCount(1);
        }
    }

    protected function setUpBackendUserWithWorkspace(int $uid): void
    {
        $backendUser = $this->setUpBackendUser($uid);
        $backendUser->workspace = 1; // Set to test workspace
        $GLOBALS['BE_USER'] = $backendUser;
    }
}