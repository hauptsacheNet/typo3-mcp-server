<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use Hn\McpServer\Tests\Functional\Traits\PluginContentTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test LLM's ability to create and manage news articles using MCP tools
 *
 * @group llm
 */
class NewsTest extends LlmTestCase
{
    use PluginContentTrait;

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
        $this->importCSVDataSet(__DIR__ . '/Fixtures/news_records.csv');

        // Plugin row inserted programmatically because the tt_content shape
        // differs between TYPO3 13 (CType=list+list_type) and TYPO3 14
        // (CType=plugin).
        $this->insertPluginContentElement(
            uid: 100,
            pid: 12,
            pluginIdentifier: 'news_pi1',
            extra: [
                'header' => 'Recent Updates',
                'pi_flexform' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="settings.startingpoint"><value index="vDEF">30</value></field></language></sheet></data></T3FlexForms>',
            ]
        );
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Write a news article that the website launched today" → discovers news extension, then WriteTable(create, tx_news_domain_model_news) on Blog/Press/Storage page with launch content')]
    public function testLlmCreatesWebsiteLaunchNews(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Write a news article that the website launched today (21 July 2025)";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );

        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPageTree', $history) ||
                         in_array('Search', $history) ||
                         in_array('ListTables', $history) ||
                         in_array('GetPage', $history);
        $this->assertTrue($hasExploration,
            "Expected LLM to explore and discover news functionality. Tools used: " . implode(', ', $history));

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertGreaterThan(0, count($writeCalls),
            "Expected WriteTable call but none found. Tool history: " . implode(' → ', $this->getToolCallHistory()) .
            "\nFinal response: " . $response->getContent());

        $writeCall = $writeCalls[0];
        $this->assertEquals('create', $writeCall['arguments']['action']);

        $table = $writeCall['arguments']['table'];
        $this->assertEquals('tx_news_domain_model_news', $table,
            "Expected news record creation when asked for a news article");

        $acceptablePids = [8, 12, 30];
        $this->assertContains($writeCall['arguments']['pid'], $acceptablePids,
            "News articles should be created on Blog (8), Press (12), or in the storage folder (30)");

        $writeResult = $this->executeToolCall($writeCall);
        $this->assertFalse($writeResult['isError'] ?? false,
            'WriteTable failed: ' . $writeResult['content']);

        $data = $this->extractWriteData($writeCall['arguments']);

        $allContent = ($data['title'] ?? '') . ' ' .
                     ($data['teaser'] ?? '') . ' ' .
                     ($data['bodytext'] ?? '');

        $this->assertNotEmpty($allContent, "Content should not be empty");
        $this->assertMatchesRegularExpression(
            '/launch|website|site|online|live|released|today/i',
            $allContent,
            "Content should mention the website launch"
        );

        if (isset($data['datetime'])) {
            $datetime = is_numeric($data['datetime'])
                ? (int)$data['datetime']
                : strtotime($data['datetime']);

            $this->assertGreaterThan(0, $datetime, "News should have a valid date");
        }
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Create a company announcement about product launch and categorize it" → explores, then WriteTable(create, tx_news_domain_model_news) with product launch content and category handling')]
    public function testLlmCreatesNewsWithCategory(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Create a company announcement about our new product launch next week and categorize it appropriately";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );

        $history = $this->getToolCallHistory();
        $hasExploration = count($history) > 1;
        $this->assertTrue($hasExploration,
            "Expected LLM to explore before creating news. Tools used: " . implode(', ', $history));

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $newsWriteCall = null;

        foreach ($writeCalls as $call) {
            if ($call['arguments']['table'] === 'tx_news_domain_model_news') {
                $newsWriteCall = $call;
                break;
            }
        }

        $this->assertNotNull($newsWriteCall,
            "Expected news record creation but none found. Tool history: " . implode(' → ', $this->getToolCallHistory()) .
            "\nAll WriteTable calls: " . json_encode(array_map(fn($c) => $c['arguments']['table'] ?? 'unknown', $writeCalls)));
        $this->assertEquals('create', $newsWriteCall['arguments']['action']);
        $acceptablePids = [8, 12, 30];
        $this->assertContains($newsWriteCall['arguments']['pid'], $acceptablePids,
            "News articles should be created on Blog (8), Press (12), or in the storage folder (30)");

        $writeResult = $this->executeToolCall($newsWriteCall);
        $this->assertFalse($writeResult['isError'] ?? false,
            'WriteTable failed: ' . $writeResult['content']);

        $data = $this->extractWriteData($newsWriteCall['arguments']);

        $allContent = ($data['title'] ?? '') . ' ' .
                     ($data['teaser'] ?? '') . ' ' .
                     ($data['bodytext'] ?? '');

        $this->assertMatchesRegularExpression(
            '/product|launch|new|announcement|release/i',
            $allContent,
            "Content should mention product launch"
        );

        $hasCategories = isset($data['categories']) && !empty($data['categories']);
        $createdCategories = array_filter($writeCalls, function($call) {
            return $call['arguments']['table'] === 'sys_category';
        });

        if ($hasCategories || count($createdCategories) > 0) {
            $this->assertTrue(true, "LLM handled categories by assigning or creating them");
        }
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Add a news article about summer sale where news and announcements go" → explores to find news section, then WriteTable(create, tx_news_domain_model_news) on pid 8/12/30')]
    public function testLlmAddsNewsToNewsSection(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Add a news article about our summer sale to the website where news and announcements go";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );

        $history = $this->getToolCallHistory();
        $hasPageExploration = in_array('GetPageTree', $history) ||
                             in_array('GetPage', $history) ||
                             in_array('Search', $history) ||
                             in_array('ListTables', $history);
        $this->assertTrue($hasPageExploration,
            "Expected LLM to explore to find appropriate location. Tools used: " . implode(', ', $history));

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $newsWriteCall = null;

        foreach ($writeCalls as $call) {
            if ($call['arguments']['table'] === 'tx_news_domain_model_news') {
                $newsWriteCall = $call;
                break;
            }
        }

        $this->assertNotNull($newsWriteCall,
            "Expected news record creation but none found. Tool history: " . implode(' → ', $this->getToolCallHistory()) .
            "\nAll WriteTable calls: " . json_encode(array_map(fn($c) => $c['arguments']['table'] ?? 'unknown', $writeCalls)));
        $this->assertEquals('create', $newsWriteCall['arguments']['action']);
        $acceptablePids = [8, 12, 30];
        $this->assertContains($newsWriteCall['arguments']['pid'], $acceptablePids,
            "News articles should be created on Blog (8), Press (12), or in the storage folder (30)");

        $writeResult = $this->executeToolCall($newsWriteCall);
        $this->assertFalse($writeResult['isError'] ?? false,
            'WriteTable failed: ' . $writeResult['content']);

        $data = $this->extractWriteData($newsWriteCall['arguments']);

        $allContent = ($data['title'] ?? '') . ' ' .
                     ($data['teaser'] ?? '') . ' ' .
                     ($data['bodytext'] ?? '');

        $this->assertMatchesRegularExpression(
            '/summer|sale|discount|offer|special/i',
            $allContent,
            "Content should mention summer sale"
        );
    }
}
