<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Test the LLM's ability to manipulate existing embedded inline relations on a content
 * element: patching a single reference's metadata in place and reordering references
 * by passing them in the desired order. Both rely on the WriteTable patch-by-uid path
 * and on array-position driving sorting_foreign (which is hidden from the schema).
 *
 * @group llm
 */
class EmbeddedRelationTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/sys_file_reference.csv');
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Patch single image alternative on tt_content uid=100 → reuses existing sys_file_reference uid, no duplicate row')]
    public function testLlmPatchesExistingFileReferenceMetadata(string $modelKey): void
    {
        $this->setModel($modelKey);

        // tt_content uid=100 has two assets: ref uid=1 (Hero Image) and ref uid=2 (Second Image),
        // both pointing to sys_file uid=1 (test.jpg).
        $prompt = 'Content element uid=100 in tt_content has an image titled "Hero Image". ' .
            'Update the alternative text of that image to "Updated alt text" without touching the other image.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            10
        );

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertNotEmpty($writeCalls,
            'Expected WriteTable call. ' . $this->getFailureContext($response));

        $currentResponse = $response;
        for ($attempt = 0; $attempt < 6 && $currentResponse->hasToolCalls(); $attempt++) {
            $currentResponse = $this->executeAndContinue($currentResponse);
        }

        $refs = $this->queryAssetReferences(100);
        $this->assertCount(2, $refs,
            'Patching one image must not create a duplicate row or drop the other reference. ' .
            'Got ' . count($refs) . ' references. ' . $this->getFailureContext($currentResponse));

        $refByUid = [];
        foreach ($refs as $row) {
            $refByUid[(int)$row['uid']] = $row;
        }
        $this->assertArrayHasKey(1, $refByUid,
            'Original sys_file_reference uid=1 must be reused, not replaced. ' .
            'Existing uids: ' . implode(',', array_keys($refByUid)) . '. ' .
            $this->getFailureContext($currentResponse));
        $this->assertArrayHasKey(2, $refByUid,
            'Untouched sys_file_reference uid=2 must remain. ' .
            $this->getFailureContext($currentResponse));

        $hero = $refByUid[1];
        $this->assertSame(1, (int)$hero['uid_local'],
            'uid_local must be preserved (no broken reference with uid_local=0). ' .
            $this->getFailureContext($currentResponse));
        $this->assertSame('Updated alt text', (string)$hero['alternative'],
            'alternative on the Hero Image reference should be updated. ' .
            $this->getFailureContext($currentResponse));

        $other = $refByUid[2];
        $this->assertSame('Second photo', (string)$other['alternative'],
            'The other reference must keep its original alternative text.');
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Reorder images on tt_content uid=100 so "Second Image" comes first → array order is reflected in sorting_foreign')]
    public function testLlmReordersFileReferencesByArrayOrder(string $modelKey): void
    {
        $this->setModel($modelKey);

        $prompt = 'Content element uid=100 in tt_content has two images attached: "Hero Image" first, ' .
            'then "Second Image". Swap their order so that "Second Image" appears first and "Hero Image" second. ' .
            'Keep both images attached and do not change any other fields.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            10
        );

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertNotEmpty($writeCalls,
            'Expected WriteTable call. ' . $this->getFailureContext($response));

        $currentResponse = $response;
        for ($attempt = 0; $attempt < 6 && $currentResponse->hasToolCalls(); $attempt++) {
            $currentResponse = $this->executeAndContinue($currentResponse);
        }

        $refs = $this->queryAssetReferences(100);
        $this->assertCount(2, $refs,
            'Both references must remain after reordering. Got ' . count($refs) . '. ' .
            $this->getFailureContext($currentResponse));

        usort($refs, fn($a, $b) => (int)$a['sorting_foreign'] <=> (int)$b['sorting_foreign']);
        $titles = array_column($refs, 'title');

        $this->assertSame(['Second Image', 'Hero Image'], $titles,
            'Reorder must change the sort order of existing references. ' .
            'Got titles in order: ' . implode(' → ', $titles) . '. ' .
            $this->getFailureContext($currentResponse));

        $uidLocals = array_unique(array_map(fn($r) => (int)$r['uid_local'], $refs));
        $this->assertSame([1], array_values($uidLocals),
            'No reference should have been recreated with uid_local=0. ' .
            $this->getFailureContext($currentResponse));
    }

    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Add a third image to tt_content uid=100 without dropping the existing two')]
    public function testLlmAddsImageWhilePreservingExisting(string $modelKey): void
    {
        $this->setModel($modelKey);

        // person.jpg = sys_file uid=3
        $prompt = 'Content element uid=100 in tt_content already has two images. ' .
            'Add person.jpg as a third image to the existing assets, keeping the existing two images intact.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            12
        );

        $this->assertNotEmpty($response->getToolCallsByName('WriteTable'),
            'Expected WriteTable call. ' . $this->getFailureContext($response));

        $currentResponse = $response;
        for ($attempt = 0; $attempt < 6 && $currentResponse->hasToolCalls(); $attempt++) {
            $currentResponse = $this->executeAndContinue($currentResponse);
        }

        $refs = $this->queryAssetReferences(100);
        $this->assertCount(3, $refs,
            'Expected three asset references after adding a third image. Got ' . count($refs) . '. ' .
            $this->getFailureContext($currentResponse));

        $fileUids = array_map(fn($r) => (int)$r['uid_local'], $refs);
        sort($fileUids);
        $this->assertContains(3, $fileUids,
            'New reference must point to person.jpg (sys_file uid=3). ' .
            'Got uid_local values: ' . implode(',', $fileUids) . '. ' .
            $this->getFailureContext($currentResponse));

        // Both original references (uid=1, uid=2 — both pointing at sys_file uid=1) must still be there.
        $existingRefUids = array_map(fn($r) => (int)$r['uid'], $refs);
        $this->assertContains(1, $existingRefUids,
            'Original Hero Image reference (uid=1) was dropped. ' .
            $this->getFailureContext($currentResponse));
        $this->assertContains(2, $existingRefUids,
            'Original Second Image reference (uid=2) was dropped. ' .
            $this->getFailureContext($currentResponse));
    }

    /**
     * Read sys_file_reference rows for tt_content.assets, including workspace versions.
     *
     * Returns the workspace overlay if present, otherwise the live row, so that we
     * always observe the LLM's most recent change in the workspace it wrote into.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function queryAssetReferences(int $parentUid): array
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();

        $rows = $qb->select(
            'uid',
            'uid_local',
            'uid_foreign',
            'sorting_foreign',
            'title',
            'alternative',
            'tablenames',
            'fieldname',
            't3ver_oid',
            't3ver_wsid',
            't3ver_state',
            'deleted',
            'hidden'
        )
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter('assets')),
                $qb->expr()->or(
                    $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($parentUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                    $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter($parentUid, \Doctrine\DBAL\ParameterType::INTEGER))
                ),
                $qb->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Fold workspace overlays onto their live uid: if a row has t3ver_oid > 0, it
        // represents an edit of that live record — prefer the overlay's data but key by
        // the live uid the LLM operates on. Drop a live row whose overlay is also present.
        $byLiveUid = [];
        foreach ($rows as $row) {
            $liveUid = (int)($row['t3ver_oid'] ?: $row['uid']);
            if ((int)$row['t3ver_oid'] > 0) {
                $row['uid'] = $liveUid;
                $byLiveUid[$liveUid] = $row;
            } elseif (!isset($byLiveUid[$liveUid])) {
                $byLiveUid[$liveUid] = $row;
            }
        }

        // Drop placeholder rows (DELETE_PLACEHOLDER state = 2)
        return array_values(array_filter($byLiveUid, fn($r) => (int)$r['t3ver_state'] !== 2));
    }
}
