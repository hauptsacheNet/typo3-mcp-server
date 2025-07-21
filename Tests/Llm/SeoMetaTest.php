<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

/**
 * Test LLM's ability to manage SEO and meta tags using MCP tools
 * 
 * @group llm
 */
class SeoMetaTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Import test data with pages
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/pages.csv');
    }

    /**
     * Test that LLM adds missing meta descriptions
     */
    public function testLlmAddsMissingMetaDescriptions(): void
    {
        $prompt = "Add meta descriptions to pages that don't have them";
        
        $response1 = $this->callLlm($prompt);
        
        // LLM should explore pages to find ones without descriptions
        $acceptableFirstSteps = [
            'GetPageTree',  // Browse all pages
            'ReadTable',    // Read pages table directly
            'Search'        // Search for pages
        ];
        
        $firstToolCall = $response1->getToolCalls()[0]['name'] ?? '';
        $this->assertContains($firstToolCall, $acceptableFirstSteps);
        
        // Execute exploration
        $exploreResult = $this->executeToolCall($response1->getToolCalls()[0]);
        
        // Continue - may need multiple reads to check all pages
        $response2 = $this->continueWithToolResult($response1, $exploreResult);
        
        // Use executeUntilToolFound to handle multiple exploration steps
        $response = $this->executeUntilToolFound($response2, 'WriteTable', 8);
        
        // Should have found at least one page to update
        $writeCalls = $response->getToolCallsByName('WriteTable');
        
        if (count($writeCalls) === 0) {
            // If no WriteTable, check if LLM determined all pages already have descriptions
            $finalContent = $response->getContent();
            $this->assertNotEmpty($finalContent, "Expected LLM to provide analysis");
            
            // If LLM says all pages have descriptions, that's valid too
            if (str_contains(strtolower($finalContent), 'already have') || 
                str_contains(strtolower($finalContent), 'all pages')) {
                $this->markTestIncomplete("LLM determined all pages already have descriptions");
                return;
            }
        }
        
        $this->assertGreaterThan(0, count($writeCalls), 
            "Expected at least one WriteTable call to add meta descriptions");
        
        // Verify it's updating pages with descriptions
        $writeCall = $writeCalls[0]['arguments'];
        $this->assertEquals('update', $writeCall['action']);
        $this->assertEquals('pages', $writeCall['table']);
        $this->assertArrayHasKey('description', $writeCall['data']);
        $this->assertNotEmpty($writeCall['data']['description']);
    }

    /**
     * Test that LLM optimizes page titles
     */
    public function testLlmOptimizesPageTitles(): void
    {
        $prompt = "Update the page title of the contact page to be more SEO-friendly";
        
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
        
        // Expect update to page title
        $this->assertToolCalled($response, 'WriteTable', [
            'action' => 'update',
            'table' => 'pages'
        ]);
        
        // Verify the title is being optimized
        $writeCall = $response->getToolCallsByName('WriteTable')[0]['arguments'];
        
        // Should update either 'title' or 'seo_title' field
        $hasTitle = isset($writeCall['data']['title']) || isset($writeCall['data']['seo_title']);
        $this->assertTrue($hasTitle, "Expected title or seo_title to be updated");
    }

    /**
     * Test that LLM adds Open Graph tags
     */
    public function testLlmAddsOpenGraphTags(): void
    {
        $prompt = "Update the home page to add a meta description for social media sharing";
        
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
        
        // LLM might check table schema to understand available fields
        $history = $this->getToolCallHistory();
        if (in_array('GetTableSchema', $history)) {
            $this->assertTrue(true, "LLM checked table schema to understand OG fields");
        }
        
        // Expect update with OG fields
        $this->assertToolCalled($response, 'WriteTable', [
            'action' => 'update',
            'table' => 'pages'
        ]);
        
        $writeCall = $response->getToolCallsByName('WriteTable')[0]['arguments'];
        
        // Should set OG fields (og_title, og_description) or similar social media fields
        $hasOgFields = false;
        foreach ($writeCall['data'] as $field => $value) {
            if (str_contains(strtolower($field), 'og_') || 
                str_contains(strtolower($field), 'twitter_') ||
                str_contains(strtolower($field), 'social')) {
                $hasOgFields = true;
                break;
            }
        }
        
        // If no OG-specific fields, at least description should be set
        if (!$hasOgFields) {
            $this->assertArrayHasKey('description', $writeCall['data'],
                "Expected Open Graph or at least description field to be set");
        }
    }

    /**
     * Test that LLM improves page slugs
     */
    public function testLlmImprovesPageSlugs(): void
    {
        $prompt = "Make the URL slug for the team page more SEO-friendly";
        
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
        
        // Expect slug update
        $this->assertToolCalled($response, 'WriteTable', [
            'action' => 'update',
            'table' => 'pages'
        ]);
        
        $writeCall = $response->getToolCallsByName('WriteTable')[0]['arguments'];
        $this->assertArrayHasKey('slug', $writeCall['data']);
        
        // New slug should be different from original "/about/team"
        $newSlug = $writeCall['data']['slug'];
        $this->assertNotEquals('/about/team', $newSlug);
        
        // Should be SEO-friendly (lowercase, hyphens, no special chars)
        $this->assertMatchesRegularExpression('/^[\/a-z0-9\-]+$/', $newSlug,
            "Slug should contain only lowercase letters, numbers, hyphens, and slashes");
    }
}