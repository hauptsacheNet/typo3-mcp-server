<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Test how different LLMs use the WriteTable search_replace parameter to correct spelling errors.
 *
 * The search_replace parameter is part of the "update" action and allows LLMs to make
 * targeted text corrections without rewriting entire field values.
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
     * Test that LLM uses search_replace to fix spelling errors in a content element header.
     *
     * Content element 200 has header "Welcom to Our Compnay" (two typos).
     * The LLM should read the content, identify the errors, and use WriteTable
     * with either search_replace or data to fix them.
     */
    #[DataProvider('modelProvider')]
    public function testLlmFixesHeaderSpellingErrors(string $modelKey): void
    {
        $this->setModel($modelKey);

        if ($this->llmProvider !== 'openrouter' && $modelKey !== 'haiku') {
            $this->markTestSkipped("Model '$modelKey' requires OpenRouter. Set OPENROUTER_API_KEY.");
        }

        $prompt = 'Fix the spelling errors in the header of content element 200 on the home page.';

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

        // search_replace is a parameter on the update action, not a separate action
        $this->assertEquals(
            'update',
            $writeCall['action'],
            "[$modelKey] Expected update action (search_replace is a parameter, not a separate action)"
        );

        // Execute the tool call and verify success
        $writeResult = $this->executeToolCall($writeCalls[0]);
        $this->assertFalse(
            $writeResult['isError'] ?? false,
            "[$modelKey] WriteTable failed: " . $writeResult['content']
        );

        // Check whether the model used search_replace or data
        $usedSearchReplace = !empty($writeCall['search_replace']);
        $usedData = !empty($writeCall['data']);

        if ($usedSearchReplace) {
            $searchReplace = $writeCall['search_replace'];
            $this->assertArrayHasKey('header', $searchReplace,
                "[$modelKey] Expected header field in search_replace");

            $headerOps = $searchReplace['header'];

            // Should fix "Welcom" -> "Welcome" and "Compnay" -> "Company"
            $fixedWelcome = false;
            $fixedCompany = false;
            foreach ($headerOps as $op) {
                if (stripos($op['search'], 'Welcom') !== false && stripos($op['replace'], 'Welcome') !== false) {
                    $fixedWelcome = true;
                }
                if (stripos($op['search'], 'Compnay') !== false && stripos($op['replace'], 'Company') !== false) {
                    $fixedCompany = true;
                }
            }

            $this->assertTrue(
                $fixedWelcome,
                "[$modelKey] Expected 'Welcom' -> 'Welcome' correction. Got: "
                    . json_encode($headerOps)
            );
            $this->assertTrue(
                $fixedCompany,
                "[$modelKey] Expected 'Compnay' -> 'Company' correction. Got: "
                    . json_encode($headerOps)
            );
        } elseif ($usedData) {
            // Model used data to replace the entire header - also valid
            $data = $writeCall['data'];
            $this->assertArrayHasKey('header', $data,
                "[$modelKey] Expected header in update data");
            $this->assertStringContainsString('Welcome', $data['header'],
                "[$modelKey] Updated header should contain 'Welcome'");
            $this->assertStringContainsString('Company', $data['header'],
                "[$modelKey] Updated header should contain 'Company'");
        } else {
            $this->fail("[$modelKey] WriteTable update called without data or search_replace");
        }
    }

    /**
     * Test that LLM fixes spelling errors in bodytext using search_replace.
     *
     * Content element 201 has bodytext with many typos.
     * The LLM should use search_replace (preferred) or data to correct them.
     */
    #[DataProvider('modelProvider')]
    public function testLlmFixesBodytextSpellingErrors(string $modelKey): void
    {
        $this->setModel($modelKey);

        if ($this->llmProvider !== 'openrouter' && $modelKey !== 'haiku') {
            $this->markTestSkipped("Model '$modelKey' requires OpenRouter. Set OPENROUTER_API_KEY.");
        }

        $prompt = 'The "Our Servces" content element on the home page (uid 201) has many spelling errors in both the header and body text. Please fix all the spelling mistakes.';

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

        $usedSearchReplace = !empty($writeCall['search_replace']);
        $usedData = !empty($writeCall['data']);

        if ($usedSearchReplace) {
            $searchReplace = $writeCall['search_replace'];

            // Should fix errors in at least header or bodytext
            $this->assertTrue(
                isset($searchReplace['header']) || isset($searchReplace['bodytext']),
                "[$modelKey] Expected search_replace for header or bodytext"
            );

            // Verify header fix: "Servces" -> "Services"
            if (isset($searchReplace['header'])) {
                $headerFixed = false;
                foreach ($searchReplace['header'] as $op) {
                    if (stripos($op['replace'], 'Services') !== false) {
                        $headerFixed = true;
                    }
                }
                $this->assertTrue($headerFixed,
                    "[$modelKey] Expected header to be corrected to 'Services'");
            }

            // Verify bodytext fixes contain at least some corrections
            if (isset($searchReplace['bodytext'])) {
                $this->assertGreaterThan(
                    0,
                    count($searchReplace['bodytext']),
                    "[$modelKey] Expected at least one bodytext replacement"
                );
            }
        } elseif ($usedData) {
            $data = $writeCall['data'];

            if (isset($data['header'])) {
                $this->assertStringContainsString('Services', $data['header'],
                    "[$modelKey] Updated header should contain 'Services'");
            }

            if (isset($data['bodytext'])) {
                $this->assertStringNotContainsString('devlopment', $data['bodytext'],
                    "[$modelKey] Should fix 'devlopment' typo");
                $this->assertStringNotContainsString('dixital', $data['bodytext'],
                    "[$modelKey] Should fix 'dixital' typo");
            }
        } else {
            $this->fail("[$modelKey] WriteTable update called without data or search_replace");
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
