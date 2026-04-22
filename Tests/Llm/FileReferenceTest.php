<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

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
    #[TestDox('[$modelKey] Prompt "What images are in the fileadmin?" → ReadTable(sys_file) bridging the fileadmin concept to the sys_file table')]
    public function testLlmUnderstandsFileadminReference(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = 'What images are in the fileadmin?';

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
                break;
            }
        }

        $this->assertTrue($queriedSysFile,
            'Expected LLM to map "fileadmin" to sys_file table. ' .
            'History: ' . implode(' → ', $this->getToolCallHistory()) . "\n" .
            $this->getFailureContext($response));
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Add a textmedia with person.jpg to home" → discovers file by name, creates tt_content with embedded file reference to sys_file uid=3')]
    public function testLlmCreatesTextmediaWithNamedFile(string $modelKey): void
    {
        $this->setModel($modelKey);
        $prompt = 'Create a new textmedia content element on the home page that shows the person.jpg image. ' .
            'Set the header to "Our Team Lead".';

        // Some models spend many turns exploring before writing — allow generous budget.
        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            18
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
        // instead of embedded records — we execute tools and let it correct itself.
        $createdUid = null;
        $currentResponse = $response;
        for ($attempt = 0; $attempt < 8 && $createdUid === null; $attempt++) {
            if (!$currentResponse->hasToolCalls()) {
                break;
            }
            $currentResponse = $this->executeAndContinue($currentResponse);
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
    #[TestDox('[$modelKey] Prompt "Copy the Welcome Header element to the About page" → reads source with file refs, creates new tt_content on About with the same file refs')]
    public function testLlmCopiesContentElementWithFileReferences(string $modelKey): void
    {
        $this->setModel($modelKey);
        // tt_content uid=100 is on home page (pid=1) and has 2 assets + 1 media file reference per fixtures.
        $prompt = 'There is a content element called "Welcome Header" on the home page. ' .
            'Copy it to the About page, keeping the same file references (images/media) attached.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            12
        );

        $history = $this->getToolCallHistory();

        // The LLM must read the source first to know about the file references.
        $this->assertContains('ReadTable', $history,
            'Expected LLM to read source element before copying. History: ' . implode(' → ', $history));

        // Execute all pending tool calls and let the LLM complete the task.
        // Models may create the element first and add file refs in a separate step,
        // or include everything in one call, or need retries for validation errors.
        $copiedUid = null;
        $currentResponse = $response;
        for ($attempt = 0; $attempt < 8 && $copiedUid === null; $attempt++) {
            if (!$currentResponse->hasToolCalls()) {
                break;
            }
            $currentResponse = $this->executeAndContinue($currentResponse);
            $copiedUid = $this->findCopiedContentElement();
        }

        $this->assertNotNull($copiedUid,
            'Expected a new tt_content record on the About page. ' .
            $this->getFailureContext($currentResponse));

        // Check if file references were created. If not, give the LLM a nudge —
        // some models create the element first then need prompting to add files.
        $newRefs = $this->getAllFileReferencesForContent($copiedUid);
        if (empty($newRefs) && $currentResponse->hasToolCalls()) {
            for ($attempt = 0; $attempt < 4 && empty($newRefs); $attempt++) {
                $currentResponse = $this->executeAndContinue($currentResponse);
                $newRefs = $this->getAllFileReferencesForContent($copiedUid);
            }
        }

        $this->assertNotEmpty($newRefs,
            'Copy has no file references. Original element has 3 (2 assets + 1 media), ' .
            'all pointing to sys_file uid=1. New element uid=' . $copiedUid . ' has none. ' .
            $this->getFailureContext($currentResponse));

        $newAssetFileUids = array_map(fn($r) => (int)$r['uid_local'], $newRefs);
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
     * Get all file references for a content element across all field types.
     */
    protected function getAllFileReferencesForContent(int $parentUid): array
    {
        return array_merge(
            $this->queryFileReferencesForContent($parentUid, 'assets'),
            $this->queryFileReferencesForContent($parentUid, 'image'),
            $this->queryFileReferencesForContent($parentUid, 'media')
        );
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
