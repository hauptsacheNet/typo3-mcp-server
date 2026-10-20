<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace\ParentVersioning;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test tables (see Fixtures/Extensions/test_workspace_relations):
 * tx_testrel_parent -children-> tx_testrel_child -grandchildren-> tx_testrel_grandchild
 * tx_testrel_parent -list_children-> tx_testrel_listchild (uid list in the parent, no foreign_field)
 * tx_testrel_parent -optout_children-> tx_testrel_optout (propagation disabled)
 */
abstract class AbstractParentVersioningTestCase extends FunctionalTestCase
{
    protected const WORKSPACE_ID = 1;

    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        __DIR__ . '/../../Fixtures/Extensions/test_workspace_relations',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/parent-versioning-records.csv');
        $this->get(ReferenceIndex::class)->updateIndex(false);

        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(self::WORKSPACE_ID);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(self::WORKSPACE_ID));
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getWorkspaceVersion(string $table, int $liveUid): ?array
    {
        return BackendUtility::getWorkspaceVersionOfRecord(self::WORKSPACE_ID, $table, $liveUid) ?: null;
    }

    /**
     * @return list<int> uids of all records of $table in the workspace
     */
    protected function getWorkspaceUids(string $table): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return array_map('intval', $queryBuilder
            ->select('uid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(self::WORKSPACE_ID, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getLiveRecord(string $table, int $uid): ?array
    {
        return BackendUtility::getRecord($table, $uid, '*', '', false) ?: null;
    }

    protected function processDataMap(array $dataMap): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);

        return $dataHandler;
    }

    protected function processCommandMap(array $commandMap): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $commandMap);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);

        return $dataHandler;
    }
}
