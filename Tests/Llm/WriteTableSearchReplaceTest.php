<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Test how different LLMs use the WriteTable data parameter to correct spelling errors.
 *
 * The data parameter accepts either full string values (complete replacement) or arrays
 * of {search, replace} operations for targeted text modifications. Both approaches are valid.
 *
 * @group llm
 */
class WriteTableSearchReplaceTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Import standard pages and the content with spelling errors
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/search_replace_content.csv');
    }

    /**
     * Data provider that returns the models to test.
     * When only ANTHROPIC_API_KEY is set, only Haiku (direct) is tested.
     */
    public static function modelProvider(): array
    {
        return [
            'Haiku' => ['haiku'],
            'GPT-5.2' => ['gpt-5.2'],
            'GPT-OSS' => ['gpt-oss'],
            'Kimi K2' => ['kimi-k2'],
            'Mistral Medium' => ['mistral-medium'],
        ];
    }

    /**
     * Test that LLM fixes spelling errors in a content element header.
     *
     * Content element 200 has header "Welcom to Our Compnay" (two typos).
     * The LLM should read the content, identify the errors, and use WriteTable
     * with either search-and-replace arrays or full string values to fix them.
     */
    #[DataProvider('modelProvider')]
    public function testLlmFixesHeaderSpellingErrors(string $modelKey): void
    {
        $this->setModel($modelKey);

        if ($this->llmProvider !== 'openrouter' && $modelKey !== 'haiku') {
            $this->markTestSkipped("Model '$modelKey' requires OpenRouter. Set OPENROUTER_API_KEY.");
        }

        $prompt = 'There are spelling errors in the header of a content element on the home page that says something about "welcome" and "company". Please find and fix the spelling mistakes.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
        );

        // Verify exploration happened
        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history)
            || in_array('GetPageTree', $history)
            || in_array('ReadTable', $history)
            || in_array('Search', $history);
        $this->assertTrue(
            $hasExploration,
            "[$modelKey] Expected LLM to explore content before fixing. Tools used: " . implode(', ', $history)
        );

        // Verify WriteTable was called
        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertGreaterThan(
            0,
            count($writeCalls),
            "[$modelKey] Expected WriteTable call. History: " . implode(' -> ', $this->getToolCallHistory())
                . "\nFinal response: " . $response->getContent()
        );

        $writeCall = $writeCalls[0]['arguments'];

        $this->assertEquals(
            'update',
            $writeCall['action'],
            "[$modelKey] Expected update action"
        );

        $this->assertArrayHasKey('data', $writeCall,
            "[$modelKey] Expected data parameter in WriteTable call");

        // Execute the tool call and verify success
        $writeResult = $this->executeToolCall($writeCalls[0]);
        $this->assertFalse(
            $writeResult['isError'] ?? false,
            "[$modelKey] WriteTable failed: " . $writeResult['content']
        );

        // Check that the header field was addressed
        $data = $writeCall['data'];
        $this->assertArrayHasKey('header', $data,
            "[$modelKey] Expected header field in data");

        $headerValue = $data['header'];

        if (is_string($headerValue)) {
            // Full replacement — verify it contains the corrected words
            $this->assertStringContainsString('Welcome', $headerValue,
                "[$modelKey] Updated header should contain 'Welcome'");
            $this->assertStringContainsString('Company', $headerValue,
                "[$modelKey] Updated header should contain 'Company'");
        } elseif (is_array($headerValue)) {
            // Search-and-replace operations
            $fixedWelcome = false;
            $fixedCompany = false;
            foreach ($headerValue as $op) {
                if (stripos($op['search'] ?? '', 'Welcom') !== false && stripos($op['replace'] ?? '', 'Welcome') !== false) {
                    $fixedWelcome = true;
                }
                if (stripos($op['search'] ?? '', 'Compnay') !== false && stripos($op['replace'] ?? '', 'Company') !== false) {
                    $fixedCompany = true;
                }
            }

            $this->assertTrue(
                $fixedWelcome,
                "[$modelKey] Expected 'Welcom' -> 'Welcome' correction. Got: "
                    . json_encode($headerValue)
            );
            $this->assertTrue(
                $fixedCompany,
                "[$modelKey] Expected 'Compnay' -> 'Company' correction. Got: "
                    . json_encode($headerValue)
            );
        } else {
            $this->fail("[$modelKey] header field value is neither string nor array: " . gettype($headerValue));
        }
    }

    /**
     * Test that LLM fixes spelling errors in bodytext using search-and-replace or full values.
     *
     * Content element 201 has bodytext with many typos.
     */
    #[DataProvider('modelProvider')]
    public function testLlmFixesBodytextSpellingErrors(string $modelKey): void
    {
        $this->setModel($modelKey);

        if ($this->llmProvider !== 'openrouter' && $modelKey !== 'haiku') {
            $this->markTestSkipped("Model '$modelKey' requires OpenRouter. Set OPENROUTER_API_KEY.");
        }

        $prompt = 'The "Our Servces" content element on the home page has many spelling errors in both the header and body text. Please fix all the spelling mistakes.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
        );

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertGreaterThan(
            0,
            count($writeCalls),
            "[$modelKey] Expected WriteTable call. History: " . implode(' -> ', $this->getToolCallHistory())
                . "\nFinal response: " . $response->getContent()
        );

        $writeCall = $writeCalls[0]['arguments'];
        $this->assertEquals(
            'update',
            $writeCall['action'],
            "[$modelKey] Expected update action"
        );

        // Execute and verify success
        $writeResult = $this->executeToolCall($writeCalls[0]);
        $this->assertFalse(
            $writeResult['isError'] ?? false,
            "[$modelKey] WriteTable failed: " . $writeResult['content']
        );

        $data = $writeCall['data'] ?? [];

        // Verify header was addressed (either as string or search/replace)
        if (isset($data['header'])) {
            if (is_string($data['header'])) {
                $this->assertStringContainsString('Services', $data['header'],
                    "[$modelKey] Updated header should contain 'Services'");
            } elseif (is_array($data['header'])) {
                $headerFixed = false;
                foreach ($data['header'] as $op) {
                    if (stripos($op['replace'] ?? '', 'Services') !== false) {
                        $headerFixed = true;
                    }
                }
                $this->assertTrue($headerFixed,
                    "[$modelKey] Expected header to be corrected to 'Services'");
            }
        }

        // Verify bodytext was addressed
        if (isset($data['bodytext'])) {
            if (is_string($data['bodytext'])) {
                $this->assertStringNotContainsString('devlopment', $data['bodytext'],
                    "[$modelKey] Should fix 'devlopment' typo");
                $this->assertStringNotContainsString('dixital', $data['bodytext'],
                    "[$modelKey] Should fix 'dixital' typo");
            } elseif (is_array($data['bodytext'])) {
                $this->assertGreaterThan(
                    0,
                    count($data['bodytext']),
                    "[$modelKey] Expected at least one bodytext replacement"
                );
            }
        }
    }

    /**
     * Test a natural-language prompt for fixing typos without specifying UIDs.
     * The LLM should discover the content, identify errors, and fix them.
     */
    #[DataProvider('modelProvider')]
    public function testLlmFindsAndFixesTyposNaturally(string $modelKey): void
    {
        $this->setModel($modelKey);

        if ($this->llmProvider !== 'openrouter' && $modelKey !== 'haiku') {
            $this->markTestSkipped("Model '$modelKey' requires OpenRouter. Set OPENROUTER_API_KEY.");
        }

        $prompt = 'I noticed there are some spelling mistakes on the home page. Can you find and fix them?';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            8, // Allow more iterations for exploration
        );

        // The LLM should have explored and found content with typos
        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history)
            || in_array('GetPageTree', $history)
            || in_array('ReadTable', $history);
        $this->assertTrue(
            $hasExploration,
            "[$modelKey] Expected LLM to explore page content. Tools used: " . implode(', ', $history)
        );

        // Should have called WriteTable to fix something
        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertGreaterThan(
            0,
            count($writeCalls),
            "[$modelKey] Expected at least one WriteTable call to fix spelling errors. "
                . "History: " . implode(' -> ', $this->getToolCallHistory())
                . "\nFinal response: " . $response->getContent()
        );

        // Execute the first write call and verify success
        $writeResult = $this->executeToolCall($writeCalls[0]);
        $this->assertFalse(
            $writeResult['isError'] ?? false,
            "[$modelKey] WriteTable failed: " . $writeResult['content']
        );
    }
}
