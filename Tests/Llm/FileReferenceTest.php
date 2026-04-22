<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use TYPO3\CMS\Core\Database\ConnectionPool;
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
     * Not an LLM test - just sanity check for the fixtures and read path.
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
        $this->assertStringContainsString('person.jpg', $result->content[0]->text);
        $this->assertStringContainsString('test.jpg', $result->content[0]->text);
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "What image files are available?" → ReadTable(sys_file) returning the discovered files')]
    public function testLlmDiscoversAvailableFiles(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = 'What image files are available in the system? List them.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'ReadTable',
            5
        );

        $readCalls = $response->getToolCallsByName('ReadTable');
        $queriedSysFile = false;
        foreach ($readCalls as $call) {
            if (($call['arguments']['table'] ?? '') === 'sys_file') {
                $queriedSysFile = true;
                $readResult = $this->executeToolCall($call);
                $this->assertFalse($readResult['isError'] ?? false,
                    'Reading sys_file failed: ' . $readResult['content']);

                $this->assertStringContainsString('person.jpg', $readResult['content'],
                    'sys_file query should return the seeded image files');
                break;
            }
        }

        $this->assertTrue($queriedSysFile,
            'Expected LLM to query sys_file. History: ' . implode(' → ', $this->getToolCallHistory()));
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Add a textmedia with person.jpg to home" → discovers file by name, creates tt_content with embedded file reference to sys_file uid=3')]
    public function testLlmCreatesTextmediaWithNamedFile(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = 'Create a new textmedia content element on the home page that shows the person.jpg image. ' .
            'Set the header to "Our Team Lead".';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            12
        );

        $history = $this->getToolCallHistory();
        $this->assertTrue(
            in_array('ReadTable', $history) || in_array('Search', $history),
            'Expected LLM to use ReadTable (sys_file) or Search to discover person.jpg. ' .
            'History: ' . implode(' → ', $history)
        );

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertNotEmpty($writeCalls,
            'Expected WriteTable call. History: ' . implode(' → ', $history) .
            "\n" . $this->getFailureContext($response));

        $writeCall = $writeCalls[0]['arguments'];
        $this->assertEquals('tt_content', $writeCall['table'],
            'Expected write to tt_content (embedded file reference), not sys_file_reference directly. ' .
            $this->getFailureContext($response));

        // Execute the write. The LLM may need to retry if it initially passes plain UIDs
        // instead of embedded records - we execute tools and let it correct itself.
        $createdUid = null;
        $currentResponse = $response;
        for ($attempt = 0; $attempt < 5 && $createdUid === null; $attempt++) {
            $writeCalls = $currentResponse->getToolCallsByName('WriteTable');
            foreach ($writeCalls as $call) {
                if (($call['arguments']['table'] ?? '') !== 'tt_content') {
                    continue;
                }
                if (($call['arguments']['action'] ?? '') !== 'create') {
                    continue;
                }
            }

            if ($currentResponse->hasToolCalls()) {
                $currentResponse = $this->executeAndContinue($currentResponse);

                // Check results for a successful create of tt_content
                foreach ($currentResponse->getToolCalls() as $tc) {
                    // No direct access to previous results here; we'll query the DB instead.
                }
            } else {
                break;
            }

            $createdUid = $this->findCreatedContentElement();
        }

        $this->assertNotNull($createdUid,
            'Expected a new tt_content record to be created. ' .
            $this->getFailureContext($currentResponse));

        // Verify a sys_file_reference was actually created in the workspace.
        // Accept either field (assets for textmedia, image for legacy textpic/image CTypes).
        // The critical thing: a reference to person.jpg (sys_file uid=3) must exist.
        $allRefs = array_merge(
            $this->queryFileReferencesForContent($createdUid, 'assets'),
            $this->queryFileReferencesForContent($createdUid, 'image')
        );

        $this->assertNotEmpty($allRefs,
            'LLM did not create a sys_file_reference (content element uid=' . $createdUid . '). ' .
            'Even after retries, the LLM could not produce embedded file reference records. ' .
            $this->getFailureContext($currentResponse));

        $fileUids = array_map(fn($r) => (int)$r['uid_local'], $allRefs);
        $this->assertContains(3, $fileUids,
            'File reference must point to person.jpg (sys_file uid=3). ' .
            'Got references to uids: ' . implode(', ', $fileUids) . '. ' .
            $this->getFailureContext($currentResponse));
    }

    /**
     * Find the most recently created tt_content record with header "Our Team Lead".
     * Used to locate the created element when the LLM may have retried.
     */
    protected function findCreatedContentElement(): ?int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();

        $row = $qb->select('uid')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('header', $qb->createNamedParameter('Our Team Lead')),
                $qb->expr()->eq('deleted', 0)
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $row ? (int)$row : null;
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Copy the Welcome Header element (uid 100) to the About page" → reads source with file refs, creates new tt_content on About with the same file refs')]
    public function testLlmCopiesContentElementWithFileReferences(string $modelKey): void
    {
        $this->setModel($modelKey);
        // tt_content uid=100 is on home page (pid=1) and has 2 assets + 1 media file reference per fixtures.
        $prompt = 'There is a content element called "Welcome Header" on the home page. ' .
            'Copy it to the About page, keeping the same images and media attached.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            12
        );

        $history = $this->getToolCallHistory();

        // The LLM must read the source first to know about the file references.
        $this->assertContains('ReadTable', $history,
            'Expected LLM to read source element before copying. History: ' . implode(' → ', $history));

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertNotEmpty($writeCalls,
            'Expected WriteTable call to create the copy. ' . $this->getFailureContext($response));

        // Find the create-tt_content call on the About page (pid=2).
        $copyCall = null;
        foreach ($writeCalls as $call) {
            $args = $call['arguments'] ?? [];
            if (($args['table'] ?? '') === 'tt_content' && ($args['action'] ?? '') === 'create') {
                $copyCall = $args;
                break;
            }
        }
        $this->assertNotNull($copyCall,
            'Expected a create tt_content call. Got: ' .
            json_encode(array_map(fn($c) => $c['arguments'], $writeCalls), JSON_PRETTY_PRINT));

        $data = $this->extractWriteData($copyCall);

        // Verify placement on the About page (uid 2 in fixtures). pid can be at top level or
        // inside data, and position may reference an existing element on that page.
        $pidTop = $copyCall['pid'] ?? null;
        $pidInData = $data['pid'] ?? null;
        $position = $copyCall['position'] ?? null;

        $placedOnAbout = ($pidTop === 2 || $pidInData === 2);
        if (!$placedOnAbout && is_string($position)) {
            // Accept references to existing About page elements (102, 103) or direct "2".
            $placedOnAbout = preg_match('/\b(102|103|2)\b/', $position) === 1;
        }
        $this->assertTrue($placedOnAbout,
            'Copy should be placed on About page (uid=2). Got pid=' . json_encode($pidTop) .
            ', data.pid=' . json_encode($pidInData) .
            ', position=' . json_encode($position) . '. ' . $this->getFailureContext($response));

        // Verify file references are attempted (either assets or media). The LLM may have used
        // plain UIDs (which our validator rejects) or embedded records. Either way, the intent
        // to copy the files must be present.
        $this->assertTrue(
            !empty($data['assets']) || !empty($data['media']) || !empty($data['image']),
            'Expected copy to preserve file references (assets/media/image). ' .
            'Got data keys: ' . implode(', ', array_keys($data)) . "\n" . $this->getFailureContext($response));

        // Allow the LLM to retry if the initial write fails validation.
        $copiedUid = null;
        $currentResponse = $response;
        for ($attempt = 0; $attempt < 5 && $copiedUid === null; $attempt++) {
            if (!$currentResponse->hasToolCalls()) {
                break;
            }
            $currentResponse = $this->executeAndContinue($currentResponse);
            $copiedUid = $this->findCopiedContentElement();
        }

        $this->assertNotNull($copiedUid,
            'Expected a new tt_content record on the About page. Even after retries, the copy did not persist. ' .
            $this->getFailureContext($currentResponse));

        // Verify persisted file references point to the original files (sys_file uid=1).
        $newAssets = array_merge(
            $this->queryFileReferencesForContent($copiedUid, 'assets'),
            $this->queryFileReferencesForContent($copiedUid, 'image'),
            $this->queryFileReferencesForContent($copiedUid, 'media')
        );

        $this->assertNotEmpty($newAssets,
            'Copy has no file references. Original element has 3 (2 assets + 1 media), ' .
            'all pointing to sys_file uid=1. New element uid=' . $copiedUid . ' has none. ' .
            $this->getFailureContext($currentResponse));

        $newAssetFileUids = array_map(fn($r) => (int)$r['uid_local'], $newAssets);
        $this->assertContains(1, $newAssetFileUids,
            'Copied file references should point to original sys_file (uid=1). ' .
            'Got uid_local values: ' . implode(', ', $newAssetFileUids));
    }

    /**
     * Find the copy of "Welcome Header" on the About page (pid=2).
     */
    protected function findCopiedContentElement(): ?int
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();

        $row = $qb->select('uid')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('header', $qb->createNamedParameter('Welcome Header')),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->neq('uid', $qb->createNamedParameter(100, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $row ? (int)$row : null;
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Read content uid=100" → ReadTable returns enriched file references (file_name, identifier)')]
    public function testLlmReadsExistingFileReferences(string $modelKey): void
    {
        $this->setModel($modelKey);
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

                $this->assertStringContainsString('Hero Image', $readResult['content'],
                    'Should see embedded file reference title');
                $this->assertStringContainsString('test.jpg', $readResult['content'],
                    'Should see enriched file name from sys_file');
                break;
            }
        }

        $this->assertTrue($readTtContent,
            'Expected LLM to read tt_content. History: ' . implode(' → ', $this->getToolCallHistory()));
    }

    /**
     * Query file references created for a given parent content element (including workspace versions).
     */
    protected function queryFileReferencesForContent(int $parentUid, string $fieldName): array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();

        return $qb->select('uid', 'uid_local', 'uid_foreign', 'tablenames', 'fieldname', 't3ver_wsid', 't3ver_oid')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter($fieldName)),
                $qb->expr()->or(
                    $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($parentUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                    $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter($parentUid, \Doctrine\DBAL\ParameterType::INTEGER))
                ),
                $qb->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
