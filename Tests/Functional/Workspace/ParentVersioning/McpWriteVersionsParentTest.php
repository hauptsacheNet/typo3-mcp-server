<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace\ParentVersioning;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The parent versioning has to work for the records an MCP client writes, not just for FormEngine saves:
 * an LLM typically edits a content element of a news record without ever touching the news record itself.
 */
final class McpWriteVersionsParentTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'news',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/parent-versioning-news.csv');
        $backendUser = $this->setUpBackendUser(1);
        // WorkspaceService reads $GLOBALS['LANG'] while listing the available workspaces
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    public function testWritingAContentElementVersionsItsNewsRecord(): void
    {
        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => 200,
            'data' => ['header' => 'Header changed by the MCP client'],
        ]);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $workspaceId = (int)$GLOBALS['BE_USER']->workspace;
        self::assertGreaterThan(0, $workspaceId, 'the write tool has to work in a workspace');

        $contentVersion = BackendUtility::getWorkspaceVersionOfRecord($workspaceId, 'tt_content', 200);
        self::assertIsArray($contentVersion);
        self::assertSame('Header changed by the MCP client', $contentVersion['header']);

        $newsVersion = BackendUtility::getWorkspaceVersionOfRecord($workspaceId, 'tx_news_domain_model_news', 1);
        self::assertIsArray($newsVersion, 'the news record of the changed content element has to be versioned too');
        self::assertSame('News with content elements', $newsVersion['title']);
    }
}
