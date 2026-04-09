<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Test LLM's ability to discover files and create file references on content elements.
 *
 * This tests the real-world workflow: an LLM needs to find an existing file in sys_file,
 * then create a content element with a file reference pointing to that file.
 *
 * @group llm
 */
class FileReferenceTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Import test data
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/sys_file_reference.csv');
    }

    /**
     * Verify our own infrastructure: sys_file is readable without pid filter.
     */
    public function testSysFileIsReadableWithoutPid(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'sys_file',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertGreaterThan(0, $data['total'], 'sys_file should return files when queried without pid');
        $this->assertStringContainsString('test.jpg', $result->content[0]->text);
    }

    /**
     * Test that an LLM can discover available files by reading sys_file.
     */
    public function testLlmDiscoversAvailableFiles(): void
    {
        $prompt = 'What image files are available in the system? List them.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'ReadTable',
            5
        );

        $history = $this->getToolCallHistory();

        // Check if it queried sys_file
        $readCalls = $response->getToolCallsByName('ReadTable');
        $queriedSysFile = false;
        foreach ($readCalls as $call) {
            if (($call['arguments']['table'] ?? '') === 'sys_file') {
                $queriedSysFile = true;

                // Log what the LLM passed for debugging
                $callArgs = json_encode($call['arguments']);

                $readResult = $this->executeToolCall($call);
                $this->assertFalse($readResult['isError'] ?? false,
                    'Reading sys_file failed: ' . $readResult['content']);

                $this->assertStringContainsString('test.jpg', $readResult['content'],
                    "sys_file query should return test.jpg.\nLLM args: {$callArgs}\nResult: " . $readResult['content']);
                break;
            }
        }

        // If not yet, continue the conversation
        if (!$queriedSysFile && $response->hasToolCalls()) {
            $nextResponse = $this->executeAndContinue($response);

            foreach ($nextResponse->getToolCallsByName('ReadTable') as $call) {
                if (($call['arguments']['table'] ?? '') === 'sys_file') {
                    $queriedSysFile = true;
                    $readResult = $this->executeToolCall($call);
                    $this->assertFalse($readResult['isError'] ?? false,
                        'Reading sys_file failed: ' . $readResult['content']);
                    break;
                }
            }
        }

        $this->assertTrue($queriedSysFile,
            'Expected LLM to query sys_file table. Tools used: ' . implode(' -> ', $history));
    }

    /**
     * Test that an LLM can add an image to a content element.
     *
     * No hints about UIDs or field names - the LLM must discover everything
     * from the schema and available data. It should:
     * 1. Explore the page tree to find the home page
     * 2. Check GetTableSchema for textmedia to learn about the assets field
     * 3. Query sys_file to find test.jpg
     * 4. Create the content element with embedded file reference
     */
    public function testLlmAddsImageToContentElement(): void
    {
        $prompt = 'Add the image test.jpg to a new textmedia content element on the home page. ' .
            'Set the header to "Welcome Image" and the alt text to "Welcome to our site".';

        // Let the LLM explore and find the write action
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            10
        );

        // Verify it called WriteTable
        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertNotEmpty($writeCalls,
            'Expected WriteTable call. History: ' . implode(' -> ', $this->getToolCallHistory()));

        $writeCall = $writeCalls[0]['arguments'];
        $writeJson = json_encode($writeCall, JSON_PRETTY_PRINT);

        // Check: did it use the correct approach (tt_content with embedded assets)?
        if ($writeCall['table'] === 'tt_content' && isset($writeCall['data']['assets'])) {
            // Correct approach: embedded file reference in assets field
            $this->assertEquals('create', $writeCall['action']);
            $assets = $writeCall['data']['assets'];
            $this->assertIsArray($assets);
            $this->assertNotEmpty($assets, 'Expected at least one file reference in assets');

            $firstRef = $assets[0];
            $this->assertArrayHasKey('uid_local', $firstRef,
                'File reference should include uid_local. WriteTable call: ' . $writeJson);

            // Execute the write and verify it succeeds
            $writeResult = $this->executeToolCall($writeCalls[0]);
            $this->assertFalse($writeResult['isError'] ?? false,
                'WriteTable failed: ' . $writeResult['content']);

        } elseif ($writeCall['table'] === 'sys_file_reference') {
            // Alternative approach: LLM tried to create sys_file_reference directly
            $this->markTestIncomplete(
                'LLM created sys_file_reference directly instead of embedding via assets field. ' .
                'This suggests the schema guidance is not sufficient. WriteTable call: ' . $writeJson
            );
        } else {
            $this->fail(
                'Expected WriteTable for tt_content with assets or sys_file_reference. ' .
                'Got: ' . $writeJson
            );
        }
    }

    /**
     * Test that an LLM can read existing file references on a content element.
     */
    public function testLlmReadsExistingFileReferences(): void
    {
        $prompt = 'Read content element uid=100 from tt_content and describe its attached files.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'ReadTable',
            3
        );

        $readCalls = $response->getToolCallsByName('ReadTable');
        $this->assertNotEmpty($readCalls, 'Expected ReadTable call');

        $readTtContent = false;
        foreach ($readCalls as $call) {
            if (($call['arguments']['table'] ?? '') === 'tt_content') {
                $readTtContent = true;

                $readResult = $this->executeToolCall($call);
                $this->assertFalse($readResult['isError'] ?? false,
                    'Reading tt_content failed: ' . $readResult['content']);

                // Verify the response includes embedded file references
                $this->assertStringContainsString('Hero Image', $readResult['content'],
                    'Should see embedded file reference title');
                $this->assertStringContainsString('test.jpg', $readResult['content'],
                    'Should see enriched file name from sys_file');
                break;
            }
        }

        $this->assertTrue($readTtContent,
            'Expected LLM to read tt_content. History: ' . implode(' -> ', $this->getToolCallHistory()));
    }
}
