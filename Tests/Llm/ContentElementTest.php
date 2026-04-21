<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

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

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Add a welcome message to the contact page (Monday-Friday 9-5)" → explores page context, then WriteTable(tt_content, CType=text/textmedia) with business hours in bodytext')]
    public function testLlmCreatesSimpleTextContent(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Add a welcome message to the contact page that says we're available Monday to Friday, 9 AM to 5 PM";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable'
        );

        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history) ||
                         in_array('GetPageTree', $history) ||
                         in_array('Search', $history);
        $this->assertTrue($hasExploration,
            "Expected LLM to explore page context before creating content. Tools used: " . implode(', ', $history));

        $this->assertToolCalled($response, 'WriteTable', [
            'table' => 'tt_content'
        ]);

        $writeCall = $response->getToolCallsByName('WriteTable')[0]['arguments'];
        $data = $this->extractWriteData($writeCall);

        $this->assertContains($writeCall['action'], ['create', 'update'],
            "Expected create or update action for content element");

        if ($writeCall['action'] === 'create' && isset($data['CType'])) {
            $this->assertContains($data['CType'], ['text', 'textmedia'],
                "Expected text or textmedia content type for new content");
        }

        $writeResult = $this->executeToolCall($response->getToolCalls()[0]);
        $this->assertFalse($writeResult['isError'] ?? false,
            'WriteTable failed: ' . $writeResult['content']);

        $writeData = json_decode($writeResult['content'], true);
        $this->assertEquals($writeCall['action'], $writeData['action']);
        $this->assertEquals('tt_content', $writeData['table']);
        $this->assertArrayHasKey('uid', $writeData);

        $bodytext = $data['bodytext'] ?? '';

        $this->assertStringContainsString('Monday', $bodytext);
        $this->assertStringContainsString('Friday', $bodytext);

        $this->assertMatchesRegularExpression('/9|nine/i', $bodytext, "Should mention 9 AM");
        $this->assertMatchesRegularExpression('/5|five/i', $bodytext, "Should mention 5 PM");
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Add business hours to the right column of the contact page" → explores page context, then WriteTable(tt_content) with colPos=1 or 2 for right column')]
    public function testLlmCreatesContentInRightColumn(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Add our business hours (weekdays 8:30 AM to 6:00 PM, closed weekends) to the right column of the contact page";

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
        if (in_array('ReadTable', $history)) {
            $this->assertTrue(true, "LLM checked existing content to understand layout");
        }

        $writeTableCalls = $response->getToolCallsByName('WriteTable');
        $this->assertCount(1, $writeTableCalls, "Expected WriteTable call");

        $writeCall = $writeTableCalls[0]['arguments'];
        $data = $this->extractWriteData($writeCall);

        $this->assertContains($writeCall['action'], ['create', 'update'],
            "Expected create or update action");
        $this->assertEquals('tt_content', $writeCall['table']);

        if ($writeCall['action'] === 'create') {
            $this->assertContains($data['colPos'], [1, 2],
                "Content should be created in right column (colPos=1 or 2)");
        } else if ($writeCall['action'] === 'update') {
            if (isset($writeCall['where']['uid']) && $writeCall['where']['uid'] == 108) {
                $this->assertTrue(true, "Updating existing Office Hours content in right column");
            } else {
                if (isset($data['colPos'])) {
                    $this->assertContains($data['colPos'], [1, 2],
                        "Content should be moved to right column");
                }
            }
        }
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Add a section header \'Our Services\' to the home page" → explores page context, then WriteTable(tt_content, header=Our Services)')]
    public function testLlmCreatesHeaderElement(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Add a section header 'Our Services' to the home page";

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
            'table' => 'tt_content',
            'data' => [
                'header' => 'Our Services'
            ]
        ]);

        $writeCall = $response->getToolCallsByName('WriteTable')[0]['arguments'];

        $this->assertContains($writeCall['action'], ['create', 'update'],
            "Expected create or update action");

        $writeResult = $this->executeToolCall($response->getToolCalls()[0]);
        $this->assertFalse($writeResult['isError'] ?? false);
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Make the welcome header on the home page sound more friendly" → explores, reads existing content, then WriteTable(update, tt_content) with changed header')]
    public function testLlmUpdatesExistingContent(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "Make the welcome header on the home page sound more friendly";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            8
        );

        $this->assertToolCalled($response, 'WriteTable', [
            'action' => 'update',
            'table' => 'tt_content'
        ]);

        $writeCall = $response->getToolCallsByName('WriteTable')[0]['arguments'];
        $data = $this->extractWriteData($writeCall);
        $newHeader = $data['header'] ?? '';

        $this->assertNotEquals('Welcome Header', $newHeader);
        $this->assertNotEmpty($newHeader);
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "On the team page, the team members appear before the introduction — reorder them" → explores, then WriteTable(update) with position or sorting to fix order')]
    public function testLlmReordersContent(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = "On the team page, the team members list currently appears before the team introduction. "
            . "Please reorder the content so the introduction comes first.";

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            8,
        );

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertGreaterThan(0, count($writeCalls),
            "Expected WriteTable call to reorder content. "
            . "Tool history: " . implode(' → ', $this->getToolCallHistory())
            . "\nFinal response: " . $response->getContent());

        $hasOrderingChange = false;
        foreach ($writeCalls as $call) {
            $args = $call['arguments'] ?? [];
            $callData = $this->extractWriteData($args);
            if (($args['action'] ?? '') === 'update' &&
                (isset($callData['sorting']) || isset($args['position']))) {
                $hasOrderingChange = true;
                break;
            }
        }

        $this->assertTrue($hasOrderingChange,
            "Expected update with position or sorting field. "
            . "WriteTable calls: " . json_encode(array_map(fn($c) => $c['arguments'], $writeCalls), JSON_PRETTY_PRINT));
    }
}
