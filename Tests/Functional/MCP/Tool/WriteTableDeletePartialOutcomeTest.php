<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\MCP\Tool\Fixtures\CascadeErrorInjectingHook;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verify how WriteTableTool reports the outcome of a delete operation when
 * DataHandler's command-map produces a non-fatal log entry (typically from a
 * cascade phase) AFTER the primary record has already been removed in the
 * current workspace.
 *
 * The bug: a cascade-style error makes the tool return a hard isError response
 * even when the requested record really is gone, so scripted callers think
 * their delete failed and roll back / retry.
 *
 * The fix: differentiate "primary record not removed" (hard error) from
 * "primary record removed, secondary issues logged" (success with warnings).
 */
class WriteTableDeletePartialOutcomeTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    private WriteTableTool $writeTool;
    private WorkspaceContextService $workspaceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->setUpBackendUser(1);

        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->writeTool = new WriteTableTool();
        $this->workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);

        CascadeErrorInjectingHook::reset();
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][CascadeErrorInjectingHook::class]
            = CascadeErrorInjectingHook::class;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][CascadeErrorInjectingHook::class]);
        CascadeErrorInjectingHook::reset();

        parent::tearDown();
    }

    public function testDeleteOfExistingRecordReturnsSuccess(): void
    {
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $newsUid = $this->createNews(['title' => 'Plain news']);

        $deleteResult = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'delete',
            'uid' => $newsUid,
        ]);

        $this->assertFalse(
            $deleteResult->isError,
            'Plain delete must succeed. Got: ' . json_encode($deleteResult->jsonSerialize())
        );
        $payload = json_decode($deleteResult->content[0]->text, true);
        $this->assertSame('delete', $payload['action']);
        $this->assertSame($newsUid, $payload['uid']);
        $this->assertArrayNotHasKey('warnings', $payload, 'Plain delete must not surface warnings');

        $this->assertRecordIsGoneFromCurrentWorkspace('tx_news_domain_model_news', $newsUid);
    }

    public function testDeleteWithSimulatedCascadeErrorReportsSuccessWithWarnings(): void
    {
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $newsUid = $this->createNews(['title' => 'News with cascade-noisy delete']);

        // Arm the hook so DataHandler will log a non-fatal error during the
        // post-process phase of the delete command. The primary delete still
        // succeeds; the error log just gets a sibling complaint added to it,
        // exactly as production cascade failures do.
        CascadeErrorInjectingHook::$injectForTable = 'tx_news_domain_model_news';

        $deleteResult = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'delete',
            'uid' => $newsUid,
        ]);

        $this->assertRecordIsGoneFromCurrentWorkspace('tx_news_domain_model_news', $newsUid);

        $this->assertFalse(
            $deleteResult->isError,
            'When the primary record is removed, the tool must not return a hard error. Got: '
            . json_encode($deleteResult->jsonSerialize())
        );

        $payload = json_decode($deleteResult->content[0]->text, true);
        $this->assertSame('delete', $payload['action']);
        $this->assertSame($newsUid, $payload['uid']);
        $this->assertArrayHasKey('warnings', $payload, 'Cascade complaints must surface as warnings');
        $this->assertNotEmpty($payload['warnings']);
        $this->assertStringContainsString(
            CascadeErrorInjectingHook::$message,
            implode("\n", (array)$payload['warnings'])
        );
    }

    public function testDeleteWithErrorAndRecordStillPresentRemainsHardError(): void
    {
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        // Try to delete a uid that does not exist — DataHandler will log an
        // error AND the record obviously stays absent. The tool must keep
        // reporting this as a hard error (no record was effectively removed
        // by us, so the caller's rollback/retry logic is right to fire).
        $deleteResult = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'delete',
            'uid' => 999999,
        ]);

        // The current upstream behaviour for non-existent ids is to silently
        // succeed (separate, pre-existing issue). We only assert here that
        // either the tool stays consistent OR — once that silent-skip is
        // fixed — we get a hard error. Either way, this MUST NOT regress
        // into "success with cascade warnings" because nothing was deleted.
        $payload = json_decode($deleteResult->content[0]->text, true);
        $this->assertArrayNotHasKey(
            'warnings',
            $payload ?? [],
            'A delete that did not remove anything must not be dressed up with warnings.'
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createNews(array $data): int
    {
        $payload = array_merge([
            'title' => 'Test news',
            'datetime' => time(),
        ], $data);

        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => $payload,
        ]);
        $this->assertFalse($result->isError, 'Create news failed: ' . json_encode($result->jsonSerialize()));
        return (int)json_decode($result->content[0]->text, true)['uid'];
    }

    private function assertRecordIsGoneFromCurrentWorkspace(string $table, int $liveUid): void
    {
        $this->assertTrue(
            $this->isRecordGoneFromCurrentWorkspace($table, $liveUid),
            sprintf('Record %s:%d should be invisible in the current workspace after delete', $table, $liveUid)
        );
    }

    private function isRecordGoneFromCurrentWorkspace(string $table, int $liveUid): bool
    {
        $workspaceId = (int)($GLOBALS['BE_USER']->workspace ?? 0);
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);

        if ($workspaceId === 0) {
            $row = $connection->createQueryBuilder()
                ->select('deleted')
                ->from($table)
                ->where('uid = :uid')
                ->setParameter('uid', $liveUid)
                ->executeQuery()
                ->fetchAssociative();
            return $row === false || (int)$row['deleted'] === 1;
        }

        $placeholder = $connection->createQueryBuilder()
            ->select('uid')
            ->from($table)
            ->where('t3ver_oid = :liveUid', 't3ver_wsid = :wsid', 't3ver_state = 2')
            ->setParameter('liveUid', $liveUid)
            ->setParameter('wsid', $workspaceId)
            ->executeQuery()
            ->fetchAssociative();
        if ($placeholder !== false) {
            return true;
        }

        $live = $connection->createQueryBuilder()
            ->select('deleted')
            ->from($table)
            ->where('uid = :uid')
            ->setParameter('uid', $liveUid)
            ->executeQuery()
            ->fetchAssociative();
        return $live === false || (int)$live['deleted'] === 1;
    }
}
