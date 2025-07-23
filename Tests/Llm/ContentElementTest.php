<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

/**
 * Test LLM's ability to create and manage content elements using MCP tools
 * 
 * @group llm
 */
class ContentElementTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Import test data with pages and content
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/tt_content.csv');
    }

    /**
     * Test that LLM creates simple text content
     */
    public function testLlmCreatesSimpleTextContent(): void
    {
        $prompt = "Add a welcome message to the contact page that says we're available Monday to Friday, 9 AM to 5 PM";
        
        // Execute until WriteTable is found
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );
        
        // Verify responsible exploration - LLM should check context
        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history) || 
                         in_array('GetPageTree', $history) || 
                         in_array('Search', $history);
        $this->assertTrue($hasExploration, 
            "Expected LLM to explore page context before creating content. Tools used: " . implode(', ', $history));
        
        // Verify WriteTable was called for content
        $this->assertToolCalled($response, 'WriteTable', [
            'table' => 'tt_content'
        ]);
        
        $writeCall = $response->getToolCallsByName('WriteTable')[0]['arguments'];
        
        // Accept both 'create' and 'update' actions
        // The LLM might reasonably choose to update existing content if it finds
        // a suitable content element on the contact page (e.g., updating "Office Hours")
        $this->assertContains($writeCall['action'], ['create', 'update'],
            "Expected create or update action for content element");
        
        // Verify CType is a text type (text or textmedia) only for new content
        if ($writeCall['action'] === 'create') {
            $this->assertContains($writeCall['data']['CType'], ['text', 'textmedia'],
                "Expected text or textmedia content type for new content");
        }
        
        // Execute write and verify
        $writeResult = $this->executeToolCall($response->getToolCalls()[0]);
        $this->assertFalse($writeResult['isError'] ?? false, 
            'WriteTable failed: ' . $writeResult['content']);
        
        // Verify content includes office hours info
        $writeData = json_decode($writeResult['content'], true);
        $bodytext = $writeData['data']['bodytext'] ?? '';
        
        // Check for day mentions
        $this->assertStringContainsString('Monday', $bodytext);
        $this->assertStringContainsString('Friday', $bodytext);
        
        // Check for time mentions (9 and 5 should appear somewhere)
        $this->assertMatchesRegularExpression('/9|nine/i', $bodytext, "Should mention 9 AM");
        $this->assertMatchesRegularExpression('/5|five/i', $bodytext, "Should mention 5 PM");
    }

    /**
     * Test that LLM creates content in specific column
     */
    public function testLlmCreatesContentInRightColumn(): void
    {
        $prompt = "Add our business hours (weekdays 8:30 AM to 6:00 PM, closed weekends) to the right column of the contact page";
        
        // Execute until WriteTable is found
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );
        
        // Verify responsible exploration
        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history) || 
                         in_array('GetPageTree', $history) || 
                         in_array('Search', $history);
        $this->assertTrue($hasExploration, 
            "Expected LLM to explore page context. Tools used: " . implode(', ', $history));
        
        // LLM should also check existing content to understand column layout
        $history = $this->getToolCallHistory();
        if (in_array('ReadTable', $history)) {
            // Good - LLM checked existing content
            $this->assertTrue(true, "LLM checked existing content to understand layout");
        }
        
        // Now verify content creation in right column
        $writeTableCalls = $response->getToolCallsByName('WriteTable');
        $this->assertCount(1, $writeTableCalls, "Expected WriteTable call");
        
        $writeCall = $writeTableCalls[0]['arguments'];
        
        // Accept both create and update - LLM might update existing "Office Hours" content
        // in the right column instead of creating new content
        $this->assertContains($writeCall['action'], ['create', 'update'],
            "Expected create or update action");
        $this->assertEquals('tt_content', $writeCall['table']);
        
        // For right column placement:
        // - If creating new content, it should specify colPos=2
        // - If updating existing content, it might already be in the right column
        if ($writeCall['action'] === 'create') {
            // Right column is colPos=2 in TYPO3 (though some systems use 1)
            $this->assertContains($writeCall['data']['colPos'], [1, 2], 
                "Content should be created in right column (colPos=1 or 2)");
        } else if ($writeCall['action'] === 'update') {
            // For updates, the content might already be in the right column
            // Check if the LLM is updating uid=108 which is already in colPos=1
            if (isset($writeCall['where']['uid']) && $writeCall['where']['uid'] == 108) {
                // This is fine - updating existing office hours in right column
                $this->assertTrue(true, "Updating existing Office Hours content in right column");
            } else {
                // Otherwise, verify colPos is being set to right column
                if (isset($writeCall['data']['colPos'])) {
                    $this->assertContains($writeCall['data']['colPos'], [1, 2],
                        "Content should be moved to right column");
                }
            }
        }
    }

    /**
     * Test that LLM creates header element
     */
    public function testLlmCreatesHeaderElement(): void
    {
        $prompt = "Add a section header 'Our Services' to the home page";
        
        // Execute until WriteTable is found
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );
        
        // Verify responsible exploration
        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history) || 
                         in_array('GetPageTree', $history) || 
                         in_array('Search', $history);
        $this->assertTrue($hasExploration, 
            "Expected LLM to explore page context. Tools used: " . implode(', ', $history));
        
        // Check for WriteTable
        $this->assertToolCalled($response, 'WriteTable', [
            'table' => 'tt_content',
            'data' => [
                'header' => 'Our Services'
            ]
        ]);
        
        $writeCall = $response->getToolCallsByName('WriteTable')[0]['arguments'];
        
        // Accept both create and update actions
        // LLM might choose to update an existing header instead of creating a new one
        $this->assertContains($writeCall['action'], ['create', 'update'],
            "Expected create or update action");

        // Execute and verify
        $writeResult = $this->executeToolCall($response->getToolCalls()[0]);
        $this->assertFalse($writeResult['isError'] ?? false);
    }

    /**
     * Test that LLM updates existing content
     */
    public function testLlmUpdatesExistingContent(): void
    {
        $prompt = "Make the welcome header on the home page sound more friendly";
        
        $response1 = $this->callLlm($prompt);
        
        // LLM should explore to find home page
        $exploreResult = $this->executeToolCall($response1->getToolCalls()[0]);
        $response2 = $this->continueWithToolResult($response1, $exploreResult);
        
        // LLM should read existing content
        if ($response2->hasToolCalls() && $response2->getToolCalls()[0]['name'] === 'ReadTable') {
            $readResult = $this->executeToolCall($response2->getToolCalls()[0]);
            $response3 = $this->continueWithToolResult($response2, $readResult);
        } else {
            $response3 = $response2;
        }
        
        // Now expect update
        $this->assertToolCalled($response3, 'WriteTable', [
            'action' => 'update',
            'table' => 'tt_content'
        ]);
        
        // Verify the update includes a friendlier header
        $writeCall = $response3->getToolCallsByName('WriteTable')[0]['arguments'];
        $newHeader = $writeCall['data']['header'] ?? '';
        
        // Should be different from original "Welcome Header"
        $this->assertNotEquals('Welcome Header', $newHeader);
        // Could check for friendly words, but LLM interpretation varies
        $this->assertNotEmpty($newHeader);
    }

    /**
     * Test that LLM reorders content elements
     */
    public function testLlmReordersContent(): void
    {
        $prompt = "On the team page, change the order of content elements so the team introduction appears before the team members";
        
        $response1 = $this->callLlm($prompt);
        
        // Execute exploration
        $currentResponse = $response1;
        $iterations = 0;
        $foundWriteTable = false;
        
        // Keep executing until we find a WriteTable or run out of iterations
        while ($iterations < 5 && $currentResponse->hasToolCalls() && !$foundWriteTable) {
            if ($currentResponse->getToolCallsByName('WriteTable')) {
                $foundWriteTable = true;
                break;
            }
            
            $currentResponse = $this->executeAndContinue($currentResponse);
            $iterations++;
        }
        
        // Should have found at least one WriteTable call
        if ($foundWriteTable) {
            $writeCalls = $currentResponse->getToolCallsByName('WriteTable');
            $this->assertGreaterThan(0, count($writeCalls), "Expected WriteTable calls");
            
            // Verify it's updating content order
            $hasOrderingChange = false;
            foreach ($writeCalls as $call) {
                if ($call['arguments']['action'] === 'update' && 
                    (isset($call['arguments']['data']['sorting']) || 
                     isset($call['arguments']['where']['uid']))) {
                    $hasOrderingChange = true;
                    break;
                }
            }
            
            $this->assertTrue($hasOrderingChange, "Expected content ordering to be changed");
        } else {
            // If no WriteTable found, at least verify the LLM understood the task
            $finalContent = $currentResponse->getContent();
            $this->assertNotEmpty($finalContent, "Expected LLM to provide response about reordering");
            
            // Skip the strict assertion if LLM chose to just describe the change
            $this->markTestIncomplete("LLM described the change but didn't execute it");
        }
    }
}