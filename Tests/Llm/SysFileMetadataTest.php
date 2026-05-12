<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Test that the LLM understands sys_file metadata is exposed as a standalone
 * editable table — not as an inline child of sys_file. The LLM has to:
 *
 *   1. find the file (sys_file by name, or via Search)
 *   2. find the corresponding metadata row (either via the metadata-uid hint
 *      embedded on sys_file or by reading sys_file_metadata directly)
 *   3. WriteTable the alternative text on that metadata row
 *
 * This is the use case the standalone exposure was designed for, so the
 * smoke test covers the discovery path end-to-end.
 *
 * @group llm
 */
class SysFileMetadataTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/backend_layout.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/sys_file_metadata.csv');

        // The shared fixture only seeds metadata for sys_file uid=1 (test.jpg).
        // We need additional rows so each scenario can target an unambiguous
        // file: person.jpg (uid=3) for the alt-text/translation tests,
        // logo.png (uid=4) and team-photo.jpg (uid=5) without alt text for
        // the bulk-find-untagged test.
        $this->insertMetadata(3, 'Person Photo', 'Original alt for person');
        $this->insertMetadata(4, 'Company Logo', '');
        $this->insertMetadata(5, 'Team Group Photo', '');
    }

    private function insertMetadata(int $fileUid, string $title, string $alternative, string $description = ''): int
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata');
        $conn->insert('sys_file_metadata', [
            'pid' => 0,
            'tstamp' => 1700000000,
            'crdate' => 1700000000,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'l10n_diffsource' => '',
            'title' => $title,
            'alternative' => $alternative,
            'description' => $description,
            'file' => $fileUid,
        ]);
        return (int)$conn->lastInsertId();
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Set the alt text of person.jpg to ..." → discovers the metadata row and WriteTable updates only that row')]
    public function testLlmSetsAltTextViaSysFileMetadata(string $modelKey): void
    {
        $this->setModel($modelKey);

        $newAlt = 'Our team lead photo';
        $prompt = 'Set the alternative text of the file "person.jpg" to "' . $newAlt . '".';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            12
        );

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertNotEmpty($writeCalls,
            'Expected a WriteTable call. ' . $this->getFailureContext($response));

        // The write must target sys_file_metadata. sys_file is read-only, and
        // there is no embedded inline metadata field anymore, so a write on
        // sys_file with a `metadata` payload would be the wrong path.
        $metadataWrite = null;
        foreach ($writeCalls as $call) {
            if (($call['arguments']['table'] ?? '') === 'sys_file_metadata') {
                $metadataWrite = $call['arguments'];
                break;
            }
        }
        $this->assertNotNull($metadataWrite,
            'Expected WriteTable on sys_file_metadata. Tool history: '
            . implode(' → ', $this->getToolCallHistory()) . "\n"
            . $this->getFailureContext($response));

        $this->assertSame('update', $metadataWrite['action'] ?? null,
            'Metadata write must be an update, not a create. '
            . $this->getFailureContext($response));

        // Drive the conversation forward until tool calls dry up so the
        // workspace overlay actually gets written.
        $currentResponse = $response;
        for ($attempt = 0; $attempt < 6 && $currentResponse->hasToolCalls(); $attempt++) {
            $currentResponse = $this->executeAndContinue($currentResponse);
        }

        $writeArgsDump = "\nWriteTable arguments seen: " . json_encode(
            array_map(fn($c) => $c['arguments'] ?? [], $writeCalls),
            JSON_PRETTY_PRINT
        );

        $personMeta = $this->loadMetadataForFile(3);
        $this->assertNotNull($personMeta,
            'Could not find metadata row for person.jpg (sys_file uid=3) after the LLM run. '
            . $writeArgsDump . "\n" . $this->getFailureContext($currentResponse));
        $this->assertSame($newAlt, (string)$personMeta['alternative'],
            'Alt text on person.jpg metadata was not updated. '
            . $writeArgsDump . "\n" . $this->getFailureContext($currentResponse));

        $testJpgMeta = $this->loadMetadataForFile(1);
        $this->assertNotNull($testJpgMeta, 'test.jpg metadata row disappeared.');
        $this->assertSame('Alt text for test', (string)$testJpgMeta['alternative'],
            'Untouched file metadata (test.jpg) was modified — the LLM hit the wrong row. '
            . $writeArgsDump . "\n" . $this->getFailureContext($currentResponse));
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Translate metadata of person.jpg to German" → action=translate creates a sys_language_uid=1 row pointing at the default-language record')]
    public function testLlmTranslatesMetadataToGerman(string $modelKey): void
    {
        $this->setModel($modelKey);

        $prompt = 'Add a German translation for the metadata of the file "person.jpg". '
            . 'The German title should be "Personenfoto" and the alternative text should be "Foto vom Teamleiter".';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            12
        );

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertNotEmpty($writeCalls,
            'Expected a WriteTable call. ' . $this->getFailureContext($response));

        // Drive the conversation forward so the workspace overlay is written.
        $currentResponse = $response;
        for ($attempt = 0; $attempt < 6 && $currentResponse->hasToolCalls(); $attempt++) {
            $currentResponse = $this->executeAndContinue($currentResponse);
        }

        $writeArgsDump = "\nWriteTable arguments seen: " . json_encode(
            array_map(fn($c) => $c['arguments'] ?? [], $writeCalls),
            JSON_PRETTY_PRINT
        );

        // Look for a German translation row pointing at the original
        // metadata record for person.jpg. We accept either action=translate
        // (the canonical path) or a direct create with l10n_parent set —
        // both produce the same end state.
        $deRow = $this->loadGermanTranslationForFile(3);
        $this->assertNotNull($deRow,
            'Expected a German translation row for person.jpg metadata. '
            . $writeArgsDump . "\n" . $this->getFailureContext($currentResponse));
        $this->assertSame('Personenfoto', (string)$deRow['title'],
            'German title not stored on the translation row. '
            . $writeArgsDump . "\n" . $this->getFailureContext($currentResponse));
        $this->assertSame('Foto vom Teamleiter', (string)$deRow['alternative'],
            'German alt text not stored on the translation row. '
            . $writeArgsDump . "\n" . $this->getFailureContext($currentResponse));
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Find images without alt text and fix them" → discovers untagged metadata rows and updates each')]
    public function testLlmFillsMissingAltTextOnUntaggedImages(string $modelKey): void
    {
        $this->setModel($modelKey);

        $prompt = 'Some images on this site have no alternative text yet. '
            . 'Find every image whose alternative text is empty and add a sensible alt text '
            . 'based on the title that is already stored. Keep titles unchanged.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            18
        );

        $this->assertNotEmpty($response->getToolCallsByName('WriteTable'),
            'Expected at least one WriteTable call. ' . $this->getFailureContext($response));

        // The LLM may need several rounds: read to find untagged rows, then
        // update each. Allow generous turn budget.
        $currentResponse = $response;
        for ($attempt = 0; $attempt < 10 && $currentResponse->hasToolCalls(); $attempt++) {
            $currentResponse = $this->executeAndContinue($currentResponse);
        }

        $logoMeta = $this->loadMetadataForFile(4);
        $teamMeta = $this->loadMetadataForFile(5);

        $this->assertNotNull($logoMeta, 'logo.png metadata row should still exist.');
        $this->assertNotNull($teamMeta, 'team-photo.jpg metadata row should still exist.');

        $this->assertNotSame('', (string)$logoMeta['alternative'],
            'logo.png alt text should have been filled. '
            . $this->getFailureContext($currentResponse));
        $this->assertNotSame('', (string)$teamMeta['alternative'],
            'team-photo.jpg alt text should have been filled. '
            . $this->getFailureContext($currentResponse));

        // Pre-populated rows must not be flattened or overwritten.
        $personMeta = $this->loadMetadataForFile(3);
        $this->assertSame('Original alt for person', (string)$personMeta['alternative'],
            'person.jpg already had alt text and must not have been touched. '
            . $this->getFailureContext($currentResponse));

        // Title field on the previously-untagged rows must survive — the
        // prompt only asks for alt text changes.
        $this->assertSame('Company Logo', (string)$logoMeta['title']);
        $this->assertSame('Team Group Photo', (string)$teamMeta['title']);
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "What metadata is stored for person.jpg?" → reads sys_file, follows the metadata uid hint into sys_file_metadata')]
    public function testLlmFollowsMetadataUidHintFromSysFile(string $modelKey): void
    {
        $this->setModel($modelKey);

        $prompt = 'Read the file "person.jpg" from sys_file and tell me what metadata is stored for it.';

        // Collect tool calls across every iteration so we can assert on the
        // full sequence, not just the first or last response.
        $currentResponse = $this->callLlm($prompt);
        $allReadCalls = [];
        for ($iter = 0; $iter < 8 && $currentResponse->hasToolCalls(); $iter++) {
            foreach ($currentResponse->getToolCallsByName('ReadTable') as $call) {
                $allReadCalls[] = $call;
            }
            $currentResponse = $this->executeAndContinue($currentResponse);
        }
        // Catch any ReadTable calls in the final response too.
        foreach ($currentResponse->getToolCallsByName('ReadTable') as $call) {
            $allReadCalls[] = $call;
        }

        $tablesRead = array_values(array_unique(array_filter(array_map(
            fn($call) => $call['arguments']['table'] ?? '',
            $allReadCalls
        ))));

        $this->assertNotEmpty($allReadCalls,
            'Expected at least one ReadTable call. ' . $this->getFailureContext($currentResponse));

        // The whole point of leaving `metadata: [<uid>]` on sys_file is that
        // the LLM follows the hint into sys_file_metadata.
        $this->assertContains('sys_file_metadata', $tablesRead,
            'Expected the LLM to read sys_file_metadata after seeing the metadata uid hint on sys_file. '
            . 'Tables read: ' . implode(', ', $tablesRead) . "\n"
            . $this->getFailureContext($currentResponse));

        // The model's final answer should mention the metadata it discovered.
        $finalText = $currentResponse->getContent();
        $this->assertMatchesRegularExpression(
            '/Person Photo|Original alt for person/i',
            $finalText,
            'LLM should have surfaced the discovered metadata in its answer. '
            . $this->getFailureContext($currentResponse)
        );
    }

    /**
     * Load the German (sys_language_uid=1) metadata row for a sys_file uid,
     * folding in any workspace overlay. Returns null if no translation row
     * exists yet.
     *
     * @return array<string,mixed>|null
     */
    private function loadGermanTranslationForFile(int $fileUid): ?array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_metadata');
        $qb->getRestrictions()->removeAll();

        $rows = $qb->select('uid', 't3ver_oid', 't3ver_state', 'sys_language_uid', 'deleted', 'alternative', 'title', 'l10n_parent', 'file')
            ->from('sys_file_metadata')
            ->where(
                $qb->expr()->eq('file', $qb->createNamedParameter($fileUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(1, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $byLiveUid = [];
        foreach ($rows as $row) {
            if ((int)$row['t3ver_state'] === 2) {
                continue;
            }
            $deletedValue = $row['deleted'] ?? $row['"deleted"'] ?? 0;
            if ((int)$deletedValue === 1) {
                continue;
            }
            $liveUid = (int)($row['t3ver_oid'] ?: $row['uid']);
            if ((int)$row['t3ver_oid'] > 0) {
                $byLiveUid[$liveUid] = $row;
            } elseif (!isset($byLiveUid[$liveUid])) {
                $byLiveUid[$liveUid] = $row;
            }
        }

        return $byLiveUid ? array_values($byLiveUid)[0] : null;
    }

    /**
     * Load the workspace-overlaid metadata row for a given sys_file uid.
     *
     * The LLM writes through DataHandler in the optimal workspace selected by
     * WorkspaceContextService, so the new alt text lives on a t3ver_oid >0 row
     * rather than the live record. We pick the overlay if present, otherwise
     * fall back to live, and ignore delete placeholders.
     *
     * @return array<string,mixed>|null
     */
    private function loadMetadataForFile(int $fileUid): ?array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_metadata');
        $qb->getRestrictions()->removeAll();

        // Don't filter on sys_language_uid/deleted in SQL — workspace overlays
        // sometimes store sys_language_uid=-1 as the "concept" marker, which
        // would skip the overlay row. Filter in PHP instead.
        $rows = $qb->select('uid', 't3ver_oid', 't3ver_state', 'sys_language_uid', 'deleted', 'alternative', 'title', 'description', 'file')
            ->from('sys_file_metadata')
            ->where(
                $qb->expr()->eq('file', $qb->createNamedParameter($fileUid, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $byLiveUid = [];
        foreach ($rows as $row) {
            if ((int)$row['t3ver_state'] === 2) {
                continue; // delete placeholder
            }
            // `deleted` is a Doctrine reserved word and may surface under a
            // quoted key on some DBs; default to 0 (not deleted) when absent.
            $deletedValue = $row['deleted'] ?? $row['"deleted"'] ?? 0;
            if ((int)$deletedValue === 1) {
                continue;
            }
            // Default-language only: live row sys_language_uid=0; workspace
            // overlays inherit that (or carry -1 as concept marker).
            $lang = (int)$row['sys_language_uid'];
            if ($lang !== 0 && $lang !== -1) {
                continue;
            }
            $liveUid = (int)($row['t3ver_oid'] ?: $row['uid']);
            if ((int)$row['t3ver_oid'] > 0) {
                $byLiveUid[$liveUid] = $row;
            } elseif (!isset($byLiveUid[$liveUid])) {
                $byLiveUid[$liveUid] = $row;
            }
        }

        return $byLiveUid ? array_values($byLiveUid)[0] : null;
    }
}
