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
        
        // Verify all UIDs are present (order might vary)
        sort($contentUids);
        $actualUids = $news['content_elements'];
        sort($actualUids);
        $this->assertEquals($contentUids, $actualUids, 'Should have all created content elements');
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
        
        // Verify content_elements is an empty array (since no relations exist)
        $this->assertArrayHasKey('content_elements', $news, 'News should have content_elements field');
        $this->assertIsArray($news['content_elements'], 'content_elements should be an array');
        $this->assertEmpty($news['content_elements'], 'News without content elements should have empty array');
    }
}