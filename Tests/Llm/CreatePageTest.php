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

        $response1 = $this->callLlm($prompt);

        // LLM should explore first to understand the structure
        $this->assertToolCalled($response1, 'GetPageTree');

        // Execute GetPageTree
        $treeResult = $this->executeToolCall($response1->getToolCalls()[0]);
        $this->assertFalse($treeResult['isError'] ?? false);

        // Continue conversation
        $response2 = $this->continueWithToolResult($response1, $treeResult);

        // Now LLM should create the page
        $this->assertToolCalled($response2, 'WriteTable', [
            'action' => 'create',
            'table' => 'pages',
            'data' => [
                'title' => 'Test Page'
            ]
        ]);

        // Execute write and verify success
        if ($response2->hasToolCalls()) {
            $writeResult = $this->executeToolCall($response2->getToolCalls()[0]);

            $this->assertFalse($writeResult['isError'] ?? false,
                'WriteTable failed: ' . $writeResult['content']);

            $writeResultData = json_decode($writeResult['content'], true);
            $this->assertEquals('create', $writeResultData['action']);
            $this->assertEquals('pages', $writeResultData['table']);
            $this->assertArrayHasKey('uid', $writeResultData);
            $this->assertIsInt($writeResultData['uid']);
        }
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Create a new page called \'New Service\' under the home page" → GetPageTree, then WriteTable(create, pages, pid=1, title=New Service)')]
    public function testLlmCreatesPageUnderHome(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Create a new page called 'New Service' under the home page";

        $response1 = $this->callLlm($prompt);

        $this->assertToolCalled($response1, 'GetPageTree');

        $toolCalls = $response1->getToolCalls();
        $treeResult = $this->executeToolCall($toolCalls[0]);

        $this->assertStringContainsString('About Us', $treeResult['content']);
        $this->assertFalse($treeResult['isError'] ?? false);

        $response2 = $this->continueWithToolResult($response1, $treeResult);

        $this->assertToolCalled($response2, 'WriteTable', [
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => [
                'title' => 'New Service'
            ]
        ]);

        if ($response2->hasToolCalls()) {
            $writeResult = $this->executeToolCall($response2->getToolCalls()[0]);

            $this->assertFalse($writeResult['isError'] ?? false,
                'WriteTable failed: ' . $writeResult['content']);

            $response3 = $this->continueWithToolResult($response2, $writeResult);

            if ($response3->hasToolCalls()) {
                $verifyResult = $this->executeToolCall($response3->getToolCalls()[0]);
                $response4 = $this->continueWithToolResult($response3, $verifyResult);
                $finalContent = $response4->getContent();
            } else {
                $finalContent = $response3->getContent();
            }

            $writeResultData = json_decode($writeResult['content'], true);
            $this->assertEquals('create', $writeResultData['action']);
            $this->assertEquals('pages', $writeResultData['table']);
            $this->assertArrayHasKey('uid', $writeResultData);
            $this->assertIsInt($writeResultData['uid']);

            $this->assertNotEmpty($finalContent);
        }
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Create a Products page with slug \'products\' and navigation title \'Our Products\'" → GetPageTree, then WriteTable(create, pages) with title, nav_title, and slug')]
    public function testLlmCreatesPageWithMetadata(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Create a Products page with slug 'products' and navigation title 'Our Products'";

        $response1 = $this->callLlm($prompt);

        $this->assertToolCalled($response1, 'GetPageTree');

        $treeResult = $this->executeToolCall($response1->getToolCalls()[0]);

        $response2 = $this->continueWithToolResult($response1, $treeResult);

        $writeTableCalls = $response2->getToolCallsByName('WriteTable');
        $this->assertCount(1, $writeTableCalls, "Expected exactly one WriteTable call");

        $writeCall = $writeTableCalls[0]['arguments'];
        $this->assertEquals('create', $writeCall['action']);
        $this->assertEquals('pages', $writeCall['table']);

        $this->assertContains($writeCall['data']['title'], ['Products', 'Our Products'],
            "Title should be either 'Products' or 'Our Products'"
        );
        $this->assertEquals('Our Products', $writeCall['data']['nav_title']);

        $this->assertContains($writeCall['data']['slug'], ['products', '/products'],
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

        $acceptablePatterns = [
            ['GetPageTree'],
            ['GetPageTree', 'Search'],
            ['Search'],
        ];

        $this->assertFollowsPattern($response, $acceptablePatterns,
            "checking for duplicates before creating"
        );

        $writeTableCalls = $response->getToolCallsByName('WriteTable');
        $this->assertCount(0, $writeTableCalls,
            "LLM should not create duplicate 'About Us' page. " . $this->getToolCallsDebugString($response)
        );
    }
}
