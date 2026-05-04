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
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/sys_file_metadata.csv');

        // The shared fixture only seeds metadata for sys_file uid=1 (test.jpg).
        // Add a row for person.jpg (sys_file uid=3) so the LLM has a clearer
        // target — both the prompt and the assertion can name a single file
        // unambiguously, and we can verify the *other* metadata row stays
        // untouched.
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata');
        $conn->insert('sys_file_metadata', [
            'pid' => 0,
            'tstamp' => 1700000000,
            'crdate' => 1700000000,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'l10n_diffsource' => '',
            'title' => 'Person Photo',
            'alternative' => 'Original alt for person',
            'description' => '',
            'file' => 3,
        ]);
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
