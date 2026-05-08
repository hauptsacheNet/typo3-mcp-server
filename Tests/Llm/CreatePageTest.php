<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test LLM's ability to create pages using MCP tools
 *
 * @group llm
 */
class CreatePageTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Import test data with basic page structure
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/pages.csv');
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Create a new page titled \'Test Page\' below the startpage" → GetPageTree for exploration, then WriteTable(create, pages, title=Test Page)')]
    public function testLlmCreatesSimplePage(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Create a new page titled 'Test Page' below the startpage";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );

        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPageTree', $history) ||
                         in_array('GetPage', $history) ||
                         in_array('Search', $history);
        $this->assertTrue($hasExploration,
            "Expected LLM to explore page structure. Tools used: " . implode(', ', $history));

        $this->assertToolCalled($response, 'WriteTable', [
            'action' => 'create',
            'table' => 'pages',
            'data' => [
                'title' => 'Test Page'
            ]
        ]);

        $writeCall = $response->getToolCallsByName('WriteTable')[0];
        $writeResult = $this->executeToolCall($writeCall);

        $this->assertFalse($writeResult['isError'] ?? false,
            'WriteTable failed: ' . $writeResult['content']);

        $writeResultData = json_decode($writeResult['content'], true);
        $this->assertEquals('create', $writeResultData['action']);
        $this->assertEquals('pages', $writeResultData['table']);
        $this->assertArrayHasKey('uid', $writeResultData);
        $this->assertIsInt($writeResultData['uid']);
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Create a new page called \'New Service\' under the home page" → GetPageTree, then WriteTable(create, pages, pid=1, title=New Service)')]
    public function testLlmCreatesPageUnderHome(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Create a new page called 'New Service' under the home page";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );

        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPageTree', $history) ||
                         in_array('GetPage', $history) ||
                         in_array('Search', $history);
        $this->assertTrue($hasExploration,
            "Expected LLM to explore page structure. Tools used: " . implode(', ', $history));

        $this->assertToolCalled($response, 'WriteTable', [
            'action' => 'create',
            'table' => 'pages',
            'data' => [
                'title' => 'New Service'
            ]
        ]);

        $writeCall = $response->getToolCallsByName('WriteTable')[0];
        $writeResult = $this->executeToolCall($writeCall);

        $this->assertFalse($writeResult['isError'] ?? false,
            'WriteTable failed: ' . $writeResult['content']);

        $writeResultData = json_decode($writeResult['content'], true);
        $this->assertEquals('create', $writeResultData['action']);
        $this->assertEquals('pages', $writeResultData['table']);
        $this->assertArrayHasKey('uid', $writeResultData);
        $this->assertIsInt($writeResultData['uid']);
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Create a Products page with slug \'products\' and navigation title \'Our Products\'" → GetPageTree, then WriteTable(create, pages) with title, nav_title, and slug')]
    public function testLlmCreatesPageWithMetadata(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Create a Products page with slug 'products' and navigation title 'Our Products'";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );

        $writeTableCalls = $response->getToolCallsByName('WriteTable');
        $this->assertGreaterThan(0, count($writeTableCalls), "Expected at least one WriteTable call");

        $writeCall = $writeTableCalls[0]['arguments'];
        $this->assertEquals('create', $writeCall['action']);
        $this->assertEquals('pages', $writeCall['table']);

        $data = $this->extractWriteData($writeCall);
        $this->assertContains($data['title'], ['Products', 'Our Products'],
            "Title should be either 'Products' or 'Our Products'"
        );
        $this->assertEquals('Our Products', $data['nav_title']);

        $this->assertContains($data['slug'], ['products', '/products'],
            "Slug should be either 'products' or '/products'"
        );
    }

    #[TestDox('Nested pages in a single prompt is skipped — LLM cannot get ID from first WriteTable for the second')]
    public function testLlmCreatesNestedPages(): void
    {
        $this->markTestSkipped(
            'Creating nested pages in a single prompt is challenging for LLMs ' .
            'as they cannot get the ID from the first WriteTable call. ' .
            'In real usage, this would be done in multiple steps with user feedback.'
        );
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Check if an \'About Us\' page exists, create it if it doesn\'t" → explores via GetPageTree/Search, does NOT call WriteTable because page already exists')]
    public function testLlmAvoidsDuplicates(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Check if an 'About Us' page exists, create it if it doesn't";

        $response = $this->callLlm($prompt);

        $currentResponse = $response;
        $history = [];
        $iterations = 0;
        while ($iterations < 5 && $currentResponse->hasToolCalls()) {
            foreach ($currentResponse->getToolCalls() as $toolCall) {
                $history[] = $toolCall['name'];
            }
            if ($currentResponse->getToolCallsByName('WriteTable')) {
                break;
            }
            $currentResponse = $this->executeAndContinue($currentResponse);
            $iterations++;
        }

        $hasExploration = in_array('GetPageTree', $history) ||
                         in_array('GetPage', $history) ||
                         in_array('ReadTable', $history) ||
                         in_array('Search', $history);
        $this->assertTrue($hasExploration,
            "Expected LLM to explore before deciding. Tools used: " . implode(', ', $history));

        $writeTableCalls = $currentResponse->getToolCallsByName('WriteTable');
        $this->assertCount(0, $writeTableCalls,
            "LLM should not create duplicate 'About Us' page. Tool history: " . implode(' → ', $history)
        );
    }
}
