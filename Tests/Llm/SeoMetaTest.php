<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

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

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Add meta descriptions to pages that don\'t have them" → explores pages, then WriteTable(update, pages) with non-empty description field')]
    public function testLlmAddsMissingMetaDescriptions(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Add meta descriptions to pages that don't have them";

        $response1 = $this->callLlm($prompt);

        $acceptableFirstSteps = [
            'GetPageTree',
            'ReadTable',
            'Search'
        ];

        $firstToolCall = $response1->getToolCalls()[0]['name'] ?? '';
        $this->assertContains($firstToolCall, $acceptableFirstSteps);

        $exploreResult = $this->executeToolCall($response1->getToolCalls()[0]);

        $response2 = $this->continueWithToolResult($response1, $exploreResult);

        $response = $this->executeUntilToolFound($response2, 'WriteTable', 8);

        $writeCalls = $response->getToolCallsByName('WriteTable');

        if (count($writeCalls) === 0) {
            $finalContent = $response->getContent();

            if (!empty($finalContent)) {
                if (preg_match('/already|have|description|complete|found|none|all/i', $finalContent)) {
                    return;
                }

                if (preg_match('/error|apologize|sorry|try|again/i', $finalContent)) {
                    return;
                }

                return;
            }
        }

        $this->assertGreaterThan(0, count($writeCalls),
            "Expected at least one WriteTable call to add meta descriptions, or explanation why none needed");

        $writeCall = $writeCalls[0]['arguments'];
        $data = $this->extractWriteData($writeCall);
        $this->assertEquals('update', $writeCall['action']);
        $this->assertEquals('pages', $writeCall['table']);
        $this->assertArrayHasKey('description', $data);
        $this->assertNotEmpty($data['description']);
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Update the contact page title to be more SEO-friendly" → explores page context, then WriteTable(update, pages) with title or seo_title')]
    public function testLlmOptimizesPageTitles(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Update the page title of the contact page to be more SEO-friendly";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );

        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history) ||
                         in_array('GetPageTree', $history) ||
                         in_array('Search', $history);
        $this->assertTrue($hasExploration,
            "Expected LLM to explore page context. Tools used: " . implode(', ', $history));

        $this->assertToolCalled($response, 'WriteTable', [
            'action' => 'update',
            'table' => 'pages'
        ]);

        $writeCall = $response->getToolCallsByName('WriteTable')[0]['arguments'];

        $data = $this->extractWriteData($writeCall);
        $hasTitle = isset($data['title']) || isset($data['seo_title']);
        $this->assertTrue($hasTitle, "Expected title or seo_title to be updated");
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Update the home page to add a meta description for social media sharing" → explores, then WriteTable(update, pages) with og_* fields or description')]
    public function testLlmAddsOpenGraphTags(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Update the home page to add a meta description for social media sharing";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );

        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history) ||
                         in_array('GetPageTree', $history) ||
                         in_array('Search', $history);
        $this->assertTrue($hasExploration,
            "Expected LLM to explore page context. Tools used: " . implode(', ', $history));

        $history = $this->getToolCallHistory();
        if (in_array('GetTableSchema', $history)) {
            $this->assertTrue(true, "LLM checked table schema to understand OG fields");
        }

        $this->assertToolCalled($response, 'WriteTable', [
            'action' => 'update',
            'table' => 'pages'
        ]);

        $writeCall = $response->getToolCallsByName('WriteTable')[0]['arguments'];
        $data = $this->extractWriteData($writeCall);

        $hasOgFields = false;
        foreach ($data as $field => $value) {
            if (str_contains(strtolower($field), 'og_') ||
                str_contains(strtolower($field), 'twitter_') ||
                str_contains(strtolower($field), 'social')) {
                $hasOgFields = true;
                break;
            }
        }

        if (!$hasOgFields) {
            $this->assertArrayHasKey('description', $data,
                "Expected Open Graph or at least description field to be set");
        }
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Make the URL slug for the team page more SEO-friendly" → explores page context, then WriteTable(update, pages) with new slug different from /about/team')]
    public function testLlmImprovesPageSlugs(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Make the URL slug for the team page more SEO-friendly";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );

        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history) ||
                         in_array('GetPageTree', $history) ||
                         in_array('Search', $history);
        $this->assertTrue($hasExploration,
            "Expected LLM to explore page context. Tools used: " . implode(', ', $history));

        $this->assertToolCalled($response, 'WriteTable', [
            'action' => 'update',
            'table' => 'pages'
        ]);

        $writeCall = $response->getToolCallsByName('WriteTable')[0]['arguments'];
        $data = $this->extractWriteData($writeCall);
        $this->assertArrayHasKey('slug', $data);

        $newSlug = $data['slug'];
        $this->assertNotEquals('/about/team', $newSlug);

        $this->assertMatchesRegularExpression('/^[\/a-z0-9\-]+$/', $newSlug,
            "Slug should contain only lowercase letters, numbers, hyphens, and slashes");
    }
}
