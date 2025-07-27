<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\LanguageService;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetPageTreeToolPluginHintsTest extends FunctionalTestCase
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
        
        // Import LLM test fixtures that include pages with storage folders and news plugin
        $this->importCSVDataSet(__DIR__ . '/../../../Llm/Fixtures/news_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Llm/Fixtures/news_plugin.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        
        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUser(1);
    }
    
    /**
     * Test plugin storage hints
     */
    public function testPluginStorageHints(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        
        // Get page tree including the page with news plugin
        $result = $tool->execute([
            'startPage' => 1,  // Start from Welcome page which has Press as child
            'depth' => 1
        ]);
        
        $content = $result->content[0]->text;
        
        // Verify plugin hint is shown for the Press page (pid 12) which has a news plugin pointing to pid 30
        $this->assertStringContainsString('[12] Press [Page]', $content);
        $this->assertStringContainsString('[news plugin → pid:30]', $content);
        
        // Verify system folder is shown with correct doktype
        $this->assertStringContainsString('[20] System [System Folder]', $content);
    }
    
    /**
     * Test complete enhanced output
     */
    public function testEnhancedOutputComplete(): void
    {
        $siteInformationService = GeneralUtility::makeInstance(SiteInformationService::class);
        $languageService = GeneralUtility::makeInstance(LanguageService::class);
        $tool = new GetPageTreeTool($siteInformationService, $languageService);
        
        // Get deeper tree to see storage folder and its contents
        $result = $tool->execute([
            'startPage' => 0,
            'depth' => 3
        ]);
        
        $content = $result->content[0]->text;
        
        // Verify doktype labels
        $this->assertStringContainsString('[1] Welcome [Page]', $content);
        $this->assertStringContainsString('[20] System [System Folder]', $content);
        $this->assertStringContainsString('[30] Content Storage [System Folder]', $content);
        
        // Verify record counts - the news plugin fixture creates a tt_content record
        $this->assertStringContainsString('[tt_content: 1]', $content);
        
        // Verify plugin hints
        $this->assertStringContainsString('[news plugin → pid:30]', $content);
    }
}