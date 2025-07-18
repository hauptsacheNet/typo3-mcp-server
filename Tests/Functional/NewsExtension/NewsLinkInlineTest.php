<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test embedded inline relations with tx_news_domain_model_link (hideTable=true)
 */
class NewsLinkInlineTest extends FunctionalTestCase
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
        // Don't import workspace fixture - let WorkspaceContextService handle it
        $this->setUpBackendUser(1);        
        // Initialize language service
        if (!isset($GLOBALS['LANG'])) {
            $languageServiceFactory = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class);
            $GLOBALS['LANG'] = $languageServiceFactory->create('default');
        }
        // Don't set workspace - let the MCP tools handle it automatically
    }

    /**
     * Test creating news with embedded related_links
     */
    public function testCreateNewsWithEmbeddedLinks(): void
    {
        // The WorkspaceContextService will handle workspace creation automatically
        
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create news with embedded links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with embedded links',
                'bodytext' => 'This news has related links',
                'related_links' => [
                    [
                        'title' => 'External link',
                        'uri' => 'https://example.com',
                        'description' => 'Link to external website'
                    ],
                    [
                        'title' => 'Internal page',
                        'uri' => 't3://page?uid=42',
                        'description' => 'Link to internal page'
                    ],
                    [
                        'title' => 'Email link',
                        'uri' => 'mailto:info@example.com'
                    ]
                ]
            ],
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Read the news record (ReadTableTool will automatically use the same workspace)
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $response = json_decode($result->content[0]->text, true);
        $this->assertArrayHasKey('records', $response, 'Response should have records key. Got: ' . json_encode($response));
        $this->assertNotEmpty($response['records'], 'Records array should not be empty');
        $news = $response['records'][0];
        
        // Verify related_links contains full embedded records
        $this->assertArrayHasKey('related_links', $news, 'News should have related_links field');
        $this->assertIsArray($news['related_links']);
        $this->assertCount(3, $news['related_links']);
        
        // Create a map of links by title for order-independent testing
        $linksByTitle = [];
        foreach ($news['related_links'] as $link) {
            $this->assertIsArray($link);
            $this->assertArrayHasKey('title', $link);
            $linksByTitle[$link['title']] = $link;
        }
        
        // Verify External link
        $this->assertArrayHasKey('External link', $linksByTitle);
        $externalLink = $linksByTitle['External link'];
        $this->assertArrayHasKey('uid', $externalLink);
        $this->assertArrayHasKey('uri', $externalLink);
        $this->assertArrayHasKey('description', $externalLink);
        $this->assertArrayHasKey('parent', $externalLink);
        $this->assertEquals('https://example.com', $externalLink['uri']);
        $this->assertEquals('Link to external website', $externalLink['description']);
        $this->assertEquals($newsUid, $externalLink['parent']);
        
        // Verify Internal page link
        $this->assertArrayHasKey('Internal page', $linksByTitle);
        $internalLink = $linksByTitle['Internal page'];
        $this->assertEquals('t3://page?uid=42', $internalLink['uri']);
        $this->assertEquals('Link to internal page', $internalLink['description']);
        $this->assertEquals($newsUid, $internalLink['parent']);
        
        // Verify Email link (no description)
        $this->assertArrayHasKey('Email link', $linksByTitle);
        $emailLink = $linksByTitle['Email link'];
        $this->assertEquals('mailto:info@example.com', $emailLink['uri']);
        $this->assertEquals($newsUid, $emailLink['parent']);
    }

    /**
     * Test updating news with embedded links
     */
    public function testUpdateNewsWithEmbeddedLinks(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create initial news
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News to update with links',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Update with embedded links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'related_links' => [
                    [
                        'title' => 'Documentation',
                        'uri' => 'https://docs.typo3.org',
                        'description' => 'TYPO3 documentation'
                    ],
                    [
                        'title' => 'GitHub',
                        'uri' => 'https://github.com/typo3/typo3',
                    ]
                ]
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Read and verify
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertCount(2, $news['related_links']);
        
        // Create a map by title to check order-independently
        $linksByTitle = [];
        foreach ($news['related_links'] as $link) {
            $linksByTitle[$link['title']] = $link;
        }
        
        $this->assertArrayHasKey('Documentation', $linksByTitle);
        $this->assertArrayHasKey('GitHub', $linksByTitle);
        $this->assertEquals('https://docs.typo3.org', $linksByTitle['Documentation']['uri']);
        $this->assertEquals('TYPO3 documentation', $linksByTitle['Documentation']['description']);
        $this->assertEquals('https://github.com/typo3/typo3', $linksByTitle['GitHub']['uri']);
    }

    /**
     * Test removing all embedded links
     */
    public function testRemoveAllEmbeddedLinks(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create news with links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with links to remove',
                'related_links' => [
                    ['title' => 'Link 1', 'uri' => 'https://example1.com'],
                    ['title' => 'Link 2', 'uri' => 'https://example2.com']
                ]
            ],
        ]);
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Update with empty array to remove all links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'related_links' => []  // Empty array removes all
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Verify links are removed
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertArrayHasKey('related_links', $news, 'Should have related_links field');
        $this->assertEmpty($news['related_links'], 'related_links should be empty when all are removed');
    }

    /**
     * Test embedded links respect sorting
     */
    public function testEmbeddedLinksSorting(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create news with links in specific order
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with sorted links',
                'related_links' => [
                    ['title' => 'First link', 'uri' => 'https://first.com'],
                    ['title' => 'Second link', 'uri' => 'https://second.com'],
                    ['title' => 'Third link', 'uri' => 'https://third.com']
                ]
            ],
        ]);
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Read and verify order
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertCount(3, $news['related_links']);
        
        // Verify all links are present (order may vary)
        $titles = array_column($news['related_links'], 'title');
        $this->assertContains('First link', $titles);
        $this->assertContains('Second link', $titles);
        $this->assertContains('Third link', $titles);
    }

    /**
     * Test validation errors for embedded links
     */
    public function testEmbeddedLinksValidationErrors(): void
    {
        // Test validation for embedded relations
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Test non-array value
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with invalid links',
                'related_links' => 'not-an-array'
            ],
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('must be an array', $result->jsonSerialize()['content'][0]->text);
        
        // Test array with non-array items
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with invalid link items',
                'related_links' => [
                    'not-an-array',
                    ['title' => 'Valid link', 'uri' => 'https://example.com']
                ]
            ],
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('must contain record data arrays', $result->jsonSerialize()['content'][0]->text);
        
        // Test empty record data
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with empty link',
                'related_links' => [
                    []  // Empty array
                ]
            ],
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('is empty', $result->jsonSerialize()['content'][0]->text);
    }
}