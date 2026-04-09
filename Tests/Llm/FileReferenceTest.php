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
     * This is a sanity check before running LLM tests.
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
     *
     * When asked about files, the LLM should query sys_file to discover what's available.
     * This is the prerequisite for creating file references.
     */
    public function testLlmDiscoversAvailableFiles(): void
    {
        $prompt = 'What image files are available in the TYPO3 system? List them with their filenames and UIDs. ' .
            'The files are stored in the sys_file table which is a root-level table (query it without a pid filter).';

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

                // Execute and verify the result
                $readResult = $this->executeToolCall($call);
                $this->assertFalse($readResult['isError'] ?? false,
                    'Reading sys_file failed: ' . $readResult['content']);

                // Check if it found files
                $this->assertStringContainsString('test.jpg', $readResult['content'],
                    'sys_file query should return test.jpg. Result: ' . $readResult['content']);
                break;
            }
        }

        // If the LLM didn't query sys_file yet, continue the conversation
        if (!$queriedSysFile) {
            // Execute whatever it did and continue
            $nextResponse = $this->executeAndContinue($response);

            // Check again
            $readCalls = $nextResponse->getToolCallsByName('ReadTable');
            foreach ($readCalls as $call) {
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
     * Test that an LLM can add an image to a content element via the assets field.
     *
     * This is the end-to-end test: explore, discover files, create content with file reference.
     * The LLM should understand that file references are embedded in the parent's file field
     * (e.g., assets for textmedia), not created as separate sys_file_reference records.
     */
    public function testLlmAddsImageToContentElement(): void
    {
        $prompt = 'Create a new textmedia content element on the home page (pid=1) with header "Welcome Image". ' .
            'Add the image file test.jpg (which has sys_file uid=1) to the assets field. ' .
            'Set the alt text to "Welcome to our site". ' .
            'File references are embedded as record data in the assets field: ' .
            'assets: [{"uid_local": <sys_file_uid>, "alternative": "alt text"}]';

        // Let the LLM explore and find the write action
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            8
        );

        // Verify it called WriteTable
        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertNotEmpty($writeCalls, 'Expected WriteTable call. History: ' . implode(' -> ', $this->getToolCallHistory()));

        $writeCall = $writeCalls[0]['arguments'];

        // Log what the LLM tried for debugging
        $writeJson = json_encode($writeCall, JSON_PRETTY_PRINT);

        // Check: did it try the correct approach (tt_content with embedded assets)?
        if ($writeCall['table'] === 'tt_content' && isset($writeCall['data']['assets'])) {
            // Correct approach: embedded file reference in assets field
            $this->assertEquals('create', $writeCall['action']);
            $assets = $writeCall['data']['assets'];
            $this->assertIsArray($assets);
            $this->assertNotEmpty($assets, 'Expected at least one file reference in assets');

            $firstRef = $assets[0];
            $this->assertArrayHasKey('uid_local', $firstRef,
                'File reference should include uid_local. WriteTable call: ' . $writeJson);
            $this->assertEquals(1, $firstRef['uid_local'],
                'Should reference sys_file uid=1 (test.jpg)');

            // Execute the write and verify it succeeds
            $writeResult = $this->executeToolCall($writeCalls[0]);
            $this->assertFalse($writeResult['isError'] ?? false,
                'WriteTable failed: ' . $writeResult['content']);

        } elseif ($writeCall['table'] === 'sys_file_reference') {
            // Alternative approach: LLM tried to create sys_file_reference directly
            // This is not ideal but shows the LLM understands file references exist
            $this->markTestIncomplete(
                'LLM created sys_file_reference directly instead of embedding via assets field. ' .
                'This works but is not the recommended approach. WriteTable call: ' . $writeJson
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
     *
     * Content element uid=100 has file references in the fixtures.
     * The LLM should be able to read them and understand the structure.
     */
    public function testLlmReadsExistingFileReferences(): void
    {
        $prompt = 'Read the content element with uid=100 from tt_content and tell me about its file references (images/assets). ' .
            'What files are attached to it?';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'ReadTable',
            3
        );

        // Should have called ReadTable for tt_content
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
