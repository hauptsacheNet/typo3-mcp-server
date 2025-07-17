<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test inline relations with content elements (independent records without hideTable)
 */
class NewsContentElementsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    protected array $testExtensionsToLoad = [
        'news',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Test that news content elements return only UIDs (not full records)
     */
    public function testNewsContentElementsReturnOnlyUids(): void
    {
        // Create a page first
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pageUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Create a news record
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with content elements',
                'bodytext' => 'Main news text',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];

        // Create content elements related to the news
        $contentUids = [];
        for ($i = 1; $i <= 3; $i++) {
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => 1,
                'data' => [
                    'header' => "Content element $i",
                    'bodytext' => "This is content element $i for the news",
                    'CType' => 'text',
                    'tx_news_related_news' => $newsUid,
                    'sorting' => $i * 256,
                ],
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $contentUids[] = json_decode($result->content[0]->text, true)['uid'];
        }

        // Read the news record
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $resultData = $result->jsonSerialize();
        $this->assertArrayHasKey('content', $resultData);
        $this->assertNotEmpty($resultData['content']);
        
        $decodedContent = json_decode($resultData['content'][0]->text, true);
        $this->assertNotNull($decodedContent, 'Content should be valid JSON');
        
        // Debug output
        if (empty($decodedContent['records'])) {
            echo "Debug - Read result: " . json_encode($decodedContent, JSON_PRETTY_PRINT) . "\n";
            echo "Debug - News UID: " . $newsUid . "\n";
        }
        
        $this->assertArrayHasKey('records', $decodedContent, 'Result should have records');
        $this->assertNotEmpty($decodedContent['records'], 'Should have at least one record');
        
        $news = $decodedContent['records'][0];
        
        // Verify content_elements contains only UIDs (not full records)
        $this->assertArrayHasKey('content_elements', $news, 'News should have content_elements field');
        $this->assertIsArray($news['content_elements'], 'content_elements should be an array');
        $this->assertCount(3, $news['content_elements'], 'Should have 3 content elements');
        
        // Check that we get UIDs only, not full records
        foreach ($news['content_elements'] as $element) {
            $this->assertIsInt($element, 'Content element should be an integer UID, not a full record');
            $this->assertContains($element, $contentUids, 'UID should be one of our created content elements');
        }
        
        // Verify the UIDs are in the correct order (based on sorting)
        $this->assertEquals($contentUids, $news['content_elements'], 'Content elements should be in sorting order');
    }

    /**
     * Test that news without content elements doesn't include the field
     */
    public function testNewsWithoutContentElementsReturnsEmptyArray(): void
    {
        // Create a news record
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News without content elements',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];

        // Read the news record
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        
        // Verify content_elements is not present (since no relations exist)
        $this->assertArrayNotHasKey('content_elements', $news, 'News without content elements should not have the field');
    }

    /**
     * Test that content elements respect workspace visibility
     */
    public function testContentElementsRespectWorkspaces(): void
    {
        // Create a workspace
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/workspace.csv');
        $GLOBALS['BE_USER']->workspace = 1;

        // Create a news record in live workspace
        $GLOBALS['BE_USER']->workspace = 0;
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with workspace content',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];

        // Create content element in live workspace
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'header' => 'Live content element',
                'CType' => 'text',
                'tx_news_related_news' => $newsUid,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $liveContentUid = json_decode($result->content[0]->text, true)['uid'];

        // Switch to workspace and create new content element
        $GLOBALS['BE_USER']->workspace = 1;
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'header' => 'Workspace content element',
                'CType' => 'text',
                'tx_news_related_news' => $newsUid,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $workspaceContentUid = json_decode($result->content[0]->text, true)['uid'];

        // Read the news record in workspace
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        
        // Should see both content elements in workspace
        $this->assertArrayHasKey('content_elements', $news);
        $this->assertCount(2, $news['content_elements'], 'Should see both live and workspace content elements');
        $this->assertContains($liveContentUid, $news['content_elements']);
        $this->assertContains($workspaceContentUid, $news['content_elements']);
    }
}