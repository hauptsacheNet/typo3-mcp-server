<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Test the LLM's ability to move a content element from one page to another.
 *
 * The expected mechanism is `WriteTable(action=update, pid=<targetPid>)`. The
 * tool accepts the new pid either at the top level or inside `data`; this
 * test treats both as valid as long as the record actually ends up on the
 * target page.
 *
 * @group llm
 */
class MoveContentElementTest extends LlmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/tt_content.csv');
    }

    /**
     * Fixture state:
     *   - tt_content uid=110 "Banner Ad" lives on page 7 ("News").
     *   - The user wants it on page 1 ("Home") instead.
     * The LLM must locate the banner, recognise it's on the wrong page, and
     * issue an update that changes pid to 1.
     */
    #[DataProvider('modelProvider')]
    #[TestDox('[$modelKey] Prompt "Move the Banner Ad to the home page" → explores, then WriteTable(update) with pid=1 to relocate the element')]
    public function testLlmMovesContentElementToAnotherPage(string $modelKey): void
    {
        $this->setModel($modelKey);

        $homePid = 1;
        $newsPid = 7;
        $bannerUid = 110;

        // Sanity check the fixture so a fixture change makes the failure obvious
        // instead of looking like an LLM mistake.
        $banner = BackendUtility::getRecord('tt_content', $bannerUid);
        $this->assertNotNull($banner, 'Fixture content element 110 missing');
        $this->assertSame($newsPid, (int)$banner['pid'], 'Fixture banner is expected to start on the News page');

        $prompt = 'There\'s a "Banner Ad" content element currently on the News page, but it should be on the Home page instead. Please move it.';

        $response = $this->executeUntilToolFound(
            $this->callLlm($prompt),
            'WriteTable',
            8
        );

        $history = $this->getToolCallHistory();
        $hasExploration = in_array('GetPage', $history, true)
            || in_array('GetPageTree', $history, true)
            || in_array('Search', $history, true)
            || in_array('ReadTable', $history, true);
        $this->assertTrue(
            $hasExploration,
            "[$modelKey] Expected exploration before move. Tools used: " . implode(', ', $history)
        );

        $writeCalls = $response->getToolCallsByName('WriteTable');
        $this->assertGreaterThan(
            0,
            count($writeCalls),
            "[$modelKey] Expected WriteTable call to perform the move. "
            . "Tool history: " . implode(' → ', $history)
            . "\nFinal response: " . $response->getContent()
        );

        $writeCall = $writeCalls[0]['arguments'];
        $this->assertSame('update', $writeCall['action'] ?? '',
            "[$modelKey] Expected an update action for the move. Got: " . json_encode($writeCall, JSON_PRETTY_PRINT));
        $this->assertSame('tt_content', $writeCall['table'] ?? '',
            "[$modelKey] Expected tt_content table. Got: " . json_encode($writeCall, JSON_PRETTY_PRINT));
        $this->assertSame($bannerUid, (int)($writeCall['uid'] ?? 0),
            "[$modelKey] Expected uid=$bannerUid (the Banner Ad). Got: " . json_encode($writeCall, JSON_PRETTY_PRINT));

        // Accept pid at the top level OR inside data — both are valid per schema.
        $data = $this->extractWriteData($writeCall);
        $newPid = $writeCall['pid'] ?? $data['pid'] ?? null;
        $this->assertNotNull(
            $newPid,
            "[$modelKey] Expected pid to be set (top level or in data) to move the record. "
            . "Got: " . json_encode($writeCall, JSON_PRETTY_PRINT)
        );
        $this->assertSame(
            $homePid,
            (int)$newPid,
            "[$modelKey] Expected pid=$homePid (Home page). Got: " . json_encode($writeCall, JSON_PRETTY_PRINT)
        );

        $writeResult = $this->executeToolCall($writeCalls[0]);
        $this->assertFalse(
            $writeResult['isError'] ?? false,
            "[$modelKey] WriteTable failed: " . ($writeResult['content'] ?? '')
        );

        // Confirm the record actually moved. ReadTable applies the workspace
        // overlay, which is exactly what we want — the move runs through
        // DataHandler in the workspace.
        $this->assertContentElementIsOnPage($bannerUid, $homePid, $modelKey);
    }

    private function assertContentElementIsOnPage(int $uid, int $expectedPid, string $modelKey): void
    {
        $toolRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\Hn\McpServer\MCP\ToolRegistry::class);
        $readTable = $toolRegistry->getTool('ReadTable');
        $this->assertNotNull($readTable, 'ReadTable tool missing from registry');

        $result = $readTable->execute([
            'table' => 'tt_content',
            'uid' => $uid,
        ]);
        $this->assertFalse($result->isError,
            "[$modelKey] ReadTable failed when verifying move: "
            . ($result->content[0]->text ?? json_encode($result->jsonSerialize())));

        $payload = json_decode($result->content[0]->text, true);
        $record = $payload['records'][0] ?? null;
        $this->assertNotNull($record,
            "[$modelKey] Could not find content element $uid after move. Payload: " . json_encode($payload));
        $this->assertSame(
            $expectedPid,
            (int)($record['pid'] ?? 0),
            "[$modelKey] Expected content element $uid to live on page $expectedPid after the move. "
            . "Actual record: " . json_encode($record)
        );
    }
}
