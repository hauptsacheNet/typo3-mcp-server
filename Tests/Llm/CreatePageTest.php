<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

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

    #[TestDox('Prompt "Create a new page titled \'Test Page\' below the startpage" → GetPageTree for exploration, then WriteTable(create, pages, title=Test Page)')]
    public function testLlmCreatesSimplePage(): void
    {
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
            
            // The write should succeed - if it fails, the test should fail
            $this->assertFalse($writeResult['isError'] ?? false, 
                'WriteTable failed: ' . $writeResult['content']);
            
            // Verify page was created
            $writeResultData = json_decode($writeResult['content'], true);
            $this->assertEquals('create', $writeResultData['action']);
            $this->assertEquals('pages', $writeResultData['table']);
            $this->assertArrayHasKey('uid', $writeResultData);
            $this->assertIsInt($writeResultData['uid']);
        }
    }

    #[TestDox('Prompt "Create a new page called \'New Service\' under the home page" → GetPageTree, then WriteTable(create, pages, pid=1, title=New Service)')]
    public function testLlmCreatesPageUnderHome(): void
    {
        // Realistic prompt without hints
        $prompt = "Create a new page called 'New Service' under the home page";
        
        $response1 = $this->callLlm($prompt);
        
        // Step 2: Assert it checks the page tree first
        $this->assertToolCalled($response1, 'GetPageTree');
        
        // Step 3: Execute the GetPageTree tool
        $toolCalls = $response1->getToolCalls();
        $treeResult = $this->executeToolCall($toolCalls[0]);
        
        // Verify we got page tree data
        $this->assertStringContainsString('About Us', $treeResult['content']);
        $this->assertFalse($treeResult['isError'] ?? false);
        
        // Step 4: Continue conversation with tree result
        $response2 = $this->continueWithToolResult($response1, $treeResult);
        
        // Step 5: Now LLM should create the page
        $this->assertToolCalled($response2, 'WriteTable', [
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => [
                'title' => 'New Service'
            ]
        ]);
        
        // Step 6: Execute the write and verify
        if ($response2->hasToolCalls()) {
            $writeResult = $this->executeToolCall($response2->getToolCalls()[0]);
            
            // The write should succeed - if it fails, the test should fail
            $this->assertFalse($writeResult['isError'] ?? false, 
                'WriteTable failed: ' . $writeResult['content']);
            
            // Continue conversation after successful write
            $response3 = $this->continueWithToolResult($response2, $writeResult);
            
            // LLM might want to verify the creation with another tool call
            if ($response3->hasToolCalls()) {
                // Execute verification tool call (likely GetPageTree)
                $verifyResult = $this->executeToolCall($response3->getToolCalls()[0]);
                $response4 = $this->continueWithToolResult($response3, $verifyResult);
                
                // Final response should mention the successful creation
                $finalContent = $response4->getContent();
            } else {
                // Direct response
                $finalContent = $response3->getContent();
            }
            
            // Verify the page was created (check the WriteTable result)
            $writeResultData = json_decode($writeResult['content'], true);
            $this->assertEquals('create', $writeResultData['action']);
            $this->assertEquals('pages', $writeResultData['table']);
            $this->assertArrayHasKey('uid', $writeResultData);
            $this->assertIsInt($writeResultData['uid']);
            
            // And verify LLM acknowledges the creation
            $this->assertNotEmpty($finalContent);
        }
    }

    #[TestDox('Prompt "Create a Products page with slug \'products\' and navigation title \'Our Products\'" → GetPageTree, then WriteTable(create, pages) with title, nav_title, and slug')]
    public function testLlmCreatesPageWithMetadata(): void
    {
        $prompt = "Create a Products page with slug 'products' and navigation title 'Our Products'";
        
        $response1 = $this->callLlm($prompt);
        
        // LLM should explore first
        $this->assertToolCalled($response1, 'GetPageTree');
        
        // Execute GetPageTree
        $treeResult = $this->executeToolCall($response1->getToolCalls()[0]);
        
        // Continue conversation
        $response2 = $this->continueWithToolResult($response1, $treeResult);
        
        // Now check WriteTable call
        $writeTableCalls = $response2->getToolCallsByName('WriteTable');
        $this->assertCount(1, $writeTableCalls, "Expected exactly one WriteTable call");
        
        $writeCall = $writeTableCalls[0]['arguments'];
        $this->assertEquals('create', $writeCall['action']);
        $this->assertEquals('pages', $writeCall['table']);
        
        // LLM might reasonably use either "Products" or "Our Products" as the title
        $this->assertContains($writeCall['data']['title'], ['Products', 'Our Products'],
            "Title should be either 'Products' or 'Our Products'"
        );
        $this->assertEquals('Our Products', $writeCall['data']['nav_title']);
        
        // Accept either 'products' or '/products' for slug
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

    #[TestDox('Prompt "Check if an \'About Us\' page exists, create it if it doesn\'t" → explores via GetPageTree/Search, does NOT call WriteTable because page already exists')]
    public function testLlmAvoidsDuplicates(): void
    {
        $prompt = "Check if an 'About Us' page exists, create it if it doesn't";
        
        $response = $this->callLlm($prompt);
        
        // LLM should check for existing pages using one of these patterns:
        // 1. GetPageTree → (no WriteTable because page exists)
        // 2. GetPageTree → Search → (no WriteTable because page exists)
        // 3. Search → (no WriteTable because page exists)
        
        $acceptablePatterns = [
            ['GetPageTree'],  // Found via tree
            ['GetPageTree', 'Search'],  // Tree then thorough search
            ['Search'],  // Direct search (less preferred but valid)
        ];
        
        $this->assertFollowsPattern($response, $acceptablePatterns, 
            "checking for duplicates before creating"
        );
        
        // Regardless of how they checked, they should NOT create a duplicate
        $writeTableCalls = $response->getToolCallsByName('WriteTable');
        $this->assertCount(0, $writeTableCalls, 
            "LLM should not create duplicate 'About Us' page. " . $this->getToolCallsDebugString($response)
        );
    }
}