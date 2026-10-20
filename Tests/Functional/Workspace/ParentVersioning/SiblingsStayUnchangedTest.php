<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace\ParentVersioning;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceRepository;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository;
use TYPO3\CMS\Workspaces\Service\GridDataService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Versioning a news record versions its content elements along with it. The ones nobody touched have to stay
 * "unchanged" in the Workspaces module, otherwise every edit of a single content element looks like an edit of
 * all of them.
 *
 * GridDataService::versionIsModified() asks getRowDetails() for the differences, which is what this checks.
 */
final class SiblingsStayUnchangedTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'frontend'];

    protected array $testExtensionsToLoad = ['news', 'mcp_server'];

    private const NEWS_UID = 100;
    private const EDITED_ELEMENT = 300;
    private const UNTOUCHED_ELEMENT = 301;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connectionPool->getConnectionForTable('tx_news_domain_model_news')->insert('tx_news_domain_model_news', [
            'uid' => self::NEWS_UID,
            'pid' => 1,
            'title' => 'Live news',
            'content_elements' => 2,
            'tstamp' => 1734875000,
            'crdate' => 1734875000,
        ]);
        $contentConnection = $connectionPool->getConnectionForTable('tt_content');
        foreach ([
            [self::EDITED_ELEMENT, 'First element', 256],
            [self::UNTOUCHED_ELEMENT, 'Second element', 512],
        ] as [$uid, $header, $sorting]) {
            $contentConnection->insert('tt_content', [
                'uid' => $uid,
                'pid' => 1,
                'header' => $header,
                'bodytext' => 'Body of ' . $header,
                'CType' => 'textmedia',
                'colPos' => 0,
                'sorting' => $sorting,
                'tx_news_related_news' => self::NEWS_UID,
                'tstamp' => 1734875000,
                'crdate' => 1734875000,
            ]);
        }
    }

    public function testEditingOneContentElementLeavesItsSiblingUnchanged(): void
    {
        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => self::EDITED_ELEMENT,
            'data' => ['header' => 'First element, edited'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // the sibling is versioned along with the news record, but nothing of it changed
        self::assertIsArray($this->getWorkspaceVersion('tt_content', self::UNTOUCHED_ELEMENT));
        self::assertSame([], $this->getModuleDifferences('tt_content', self::UNTOUCHED_ELEMENT));

        // the edited one is still listed as changed, with its header
        self::assertSame(['header'], array_column($this->getModuleDifferences('tt_content', self::EDITED_ELEMENT), 'field'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getWorkspaceVersion(string $table, int $liveUid): ?array
    {
        $version = BackendUtility::getWorkspaceVersionOfRecord((int)$GLOBALS['BE_USER']->workspace, $table, $liveUid);

        return is_array($version) ? $version : null;
    }

    /**
     * The differences the Workspaces module bases "modified" / "unchanged" on.
     *
     * @return list<array{field: string, label: string, content: string}>
     */
    private function getModuleDifferences(string $table, int $liveUid): array
    {
        $version = $this->getWorkspaceVersion($table, $liveUid);
        self::assertIsArray($version, $table . ':' . $liveUid . ' has no workspace version');

        $parameters = new \stdClass();
        $parameters->stage = (int)$version['t3ver_stage'];
        $parameters->t3ver_oid = $liveUid;
        $parameters->table = $table;
        $parameters->uid = (int)$version['uid'];

        $backendUser = $GLOBALS['BE_USER'];
        $workspaceRecord = $this->get(WorkspaceRepository::class)->findByUid($backendUser->workspace);
        $stages = $this->get(WorkspaceStageRepository::class)->findAllStagesByWorkspace($backendUser, $workspaceRecord);

        return $this->get(GridDataService::class)->getRowDetails($stages, $parameters)['data'][0]['diff'] ?? [];
    }
}
