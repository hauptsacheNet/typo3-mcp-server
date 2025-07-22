<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

/**
 * Test LLM's ability to create and manage news articles using MCP tools
 * 
 * @group llm
 */
class NewsTest extends LlmTestCase
{
    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import test data with pages, news storage, and categories
        $this->importCSVDataSet(__DIR__ . '/Fixtures/news_pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/news_categories.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/news_plugin.csv');
    }

    /**
     * Test that LLM can create a news article about website launch
     */
    public function testLlmCreatesWebsiteLaunchNews(): void
    {
        $prompt = "Write a news article that the website launched today (21 July 2025)";
        
        // Execute until WriteTable is found
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );
        
        // Verify responsible exploration - LLM should discover news context
        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPageTree', $history) || 
                         in_array('Search', $history) || 
                         in_array('ListTables', $history) ||
                         in_array('GetPage', $history);
        $this->assertTrue($hasExploration, 
            "Expected LLM to explore and discover news functionality. Tools used: " . implode(', ', $history));
        
        // Verify content creation - LLM may create either news or content
        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertGreaterThan(0, count($writeCalls), "Expected WriteTable call");
        
        $writeCall = $writeCalls[0];
        $this->assertEquals('create', $writeCall['arguments']['action']);
        
        // LLM might create news record or regular content
        $table = $writeCall['arguments']['table'];
        $this->assertContains($table, ['tx_news_domain_model_news', 'tt_content'], 
            "Expected either news record or content element creation");
        
        // Execute write and verify
        $writeResult = $this->executeToolCall($writeCall);
        $this->assertFalse($writeResult['isError'] ?? false, 
            'WriteTable failed: ' . $writeResult['content']);
        
        // Verify content includes launch information
        $data = $writeCall['arguments']['data'];
        
        // Collect all text fields based on table type
        $allContent = '';
        if ($table === 'tx_news_domain_model_news') {
            $allContent = ($data['title'] ?? '') . ' ' . 
                         ($data['teaser'] ?? '') . ' ' . 
                         ($data['bodytext'] ?? '');
        } else {
            $allContent = ($data['header'] ?? '') . ' ' . 
                         ($data['bodytext'] ?? '');
        }
        
        $this->assertNotEmpty($allContent, "Content should not be empty");
        $this->assertMatchesRegularExpression(
            '/launch|website|site|online|live|released|today/i', 
            $allContent,
            "Content should mention the website launch"
        );
        
        // Check date handling for news records
        if ($table === 'tx_news_domain_model_news' && isset($data['datetime'])) {
            $datetime = is_numeric($data['datetime']) 
                ? (int)$data['datetime'] 
                : strtotime($data['datetime']);
            
            // Just verify a date was set (LLM might use current date)
            $this->assertGreaterThan(0, $datetime, "News should have a valid date");
        }
    }

    /**
     * Test that LLM can create news with appropriate category
     */
    public function testLlmCreatesNewsWithCategory(): void
    {
        $prompt = "Create a company announcement about our new product launch next week and categorize it appropriately";
        
        // Execute until WriteTable is found
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );
        
        // Verify exploration
        $history = $this->getToolCallHistory();
        $hasExploration = count($history) > 1; // Should do more than just write
        $this->assertTrue($hasExploration, 
            "Expected LLM to explore before creating news. Tools used: " . implode(', ', $history));
        
        // Find content/news creation
        $writeCalls = $response->getToolCallsByName('WriteTable');
        $contentWriteCall = null;
        
        foreach ($writeCalls as $call) {
            if (in_array($call['arguments']['table'], ['tx_news_domain_model_news', 'tt_content'])) {
                $contentWriteCall = $call;
                break;
            }
        }
        
        $this->assertNotNull($contentWriteCall, "Expected content creation");
        
        // Execute and verify
        $writeResult = $this->executeToolCall($contentWriteCall);
        $this->assertFalse($writeResult['isError'] ?? false, 
            'WriteTable failed: ' . $writeResult['content']);
        
        // Verify content mentions product launch
        $data = $contentWriteCall['arguments']['data'];
        $table = $contentWriteCall['arguments']['table'];
        
        $allContent = '';
        if ($table === 'tx_news_domain_model_news') {
            $allContent = ($data['title'] ?? '') . ' ' . 
                         ($data['teaser'] ?? '') . ' ' . 
                         ($data['bodytext'] ?? '');
        } else {
            $allContent = ($data['header'] ?? '') . ' ' . 
                         ($data['bodytext'] ?? '');
        }
        
        $this->assertMatchesRegularExpression(
            '/product|launch|new|announcement|release/i',
            $allContent,
            "Content should mention product launch"
        );
        
        // For news records, check if LLM attempted category handling
        if ($table === 'tx_news_domain_model_news') {
            // LLM might assign categories or create them
            $hasCategories = isset($data['categories']) && !empty($data['categories']);
            $createdCategories = array_filter($writeCalls, function($call) {
                return $call['arguments']['table'] === 'sys_category';
            });
            
            // Either categories were assigned or created
            $this->assertTrue(
                $hasCategories || count($createdCategories) > 0,
                "Expected LLM to either assign existing categories or create new ones for news"
            );
        }
    }

    /**
     * Test that LLM can add news to press/blog/updates section
     */
    public function testLlmAddsNewsToNewsSection(): void
    {
        $prompt = "Add a news article about our summer sale to the website where news and announcements go";
        
        // Execute until WriteTable is found
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );
        
        // Verify exploration to find appropriate location
        $history = $this->getToolCallHistory();
        $hasPageExploration = in_array('GetPageTree', $history) || 
                             in_array('GetPage', $history) ||
                             in_array('Search', $history) ||
                             in_array('ListTables', $history);
        $this->assertTrue($hasPageExploration, 
            "Expected LLM to explore to find appropriate location. Tools used: " . implode(', ', $history));
        
        // Verify content creation
        $writeCalls = $response->getToolCallsByName('WriteTable');
        $contentWriteCall = null;
        
        foreach ($writeCalls as $call) {
            if (in_array($call['arguments']['table'], ['tx_news_domain_model_news', 'tt_content'])) {
                $contentWriteCall = $call;
                break;
            }
        }
        
        $this->assertNotNull($contentWriteCall, "Expected content creation");
        
        // Execute and verify
        $writeResult = $this->executeToolCall($contentWriteCall);
        $this->assertFalse($writeResult['isError'] ?? false, 
            'WriteTable failed: ' . $writeResult['content']);
        
        // Verify content mentions summer sale
        $data = $contentWriteCall['arguments']['data'];
        $table = $contentWriteCall['arguments']['table'];
        
        $allContent = '';
        if ($table === 'tx_news_domain_model_news') {
            $allContent = ($data['title'] ?? '') . ' ' . 
                         ($data['teaser'] ?? '') . ' ' . 
                         ($data['bodytext'] ?? '');
        } else {
            $allContent = ($data['header'] ?? '') . ' ' . 
                         ($data['bodytext'] ?? '');
        }
        
        $this->assertMatchesRegularExpression(
            '/summer|sale|discount|offer|special/i',
            $allContent,
            "Content should mention summer sale"
        );
        
        // For news records, verify storage location
        if ($table === 'tx_news_domain_model_news') {
            $pid = $data['pid'] ?? null;
            // LLM might create news in various locations
            if ($pid !== null) {
                // If pid is set, verify it's reasonable
                $this->assertGreaterThan(0, $pid, "News pid should be positive");
            }
        }
    }
}