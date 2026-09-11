<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Regression tests for issue #122.
 *
 * When a backend user has no editable workspace and none can be created,
 * WorkspaceContextService::switchToOptimalWorkspace() falls back to the live
 * workspace (id 0). Previously WriteTableTool ran anyway, silently modifying
 * live data while still reporting the change as a staged draft. Writes must now
 * be refused in that situation unless live writing has been explicitly opted
 * into via extension configuration.
 */
class WriteTableLiveWorkspaceGuardTest extends AbstractFunctionalTest
{
    protected WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();

        // A non-admin user with no workspace membership and no access to the
        // Workspaces module: switchToOptimalWorkspace() cannot find or create a
        // workspace for them and falls back to live (0).
        $this->createUserWithoutWorkspace();

        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
    }

    protected function createUserWithoutWorkspace(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('be_users');
        if ($connection->count('*', 'be_users', ['uid' => 77]) > 0) {
            return;
        }
        $connection->insert('be_users', [
            'uid' => 77,
            'pid' => 0,
            'username' => 'no_workspace_editor',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'disable' => 0,
            'deleted' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'userMods' => '', // intentionally NO web_WorkspacesWorkspaces access
        ]);
    }

    /**
     * An update must be refused instead of silently editing live data.
     */
    public function testUpdateIsRefusedWhenNoWorkspaceAvailable(): void
    {
        $this->setupDefaultBackendUser(77);

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => ['title' => 'Changed directly on live'],
        ]);

        $this->assertTrue($result->isError, 'Write to live must be refused: ' . json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('No editable workspace', $result->content[0]->text);

        // The crucial guarantee: live data was not touched.
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $title = $connection->createQueryBuilder()
            ->select('title')
            ->from('pages')
            ->where('uid = 1')
            ->executeQuery()
            ->fetchOne();
        $this->assertSame('Home', $title, 'Live page title must be unchanged after a refused write');
    }

    /**
     * A create must be refused and must not insert any live record.
     */
    public function testCreateIsRefusedWhenNoWorkspaceAvailable(): void
    {
        $this->setupDefaultBackendUser(77);

        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $countBefore = $connection->count('*', 'tt_content', []);

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => 'text',
                'header' => 'Should never be created on live',
            ],
        ]);

        $this->assertTrue($result->isError, 'Create on live must be refused: ' . json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('No editable workspace', $result->content[0]->text);

        $countAfter = $connection->count('*', 'tt_content', []);
        $this->assertSame($countBefore, $countAfter, 'No record may be created when the write is refused');
    }

    /**
     * The guard must not get in the way of the normal, workspace-backed path.
     * An admin user always gets a workspace created automatically, so their
     * write is staged as usual.
     */
    public function testWriteStillWorksWhenAWorkspaceIsResolved(): void
    {
        $this->setupDefaultBackendUser(1);

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => 'text',
                'header' => 'Staged in workspace',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertGreaterThan(0, $GLOBALS['BE_USER']->workspace, 'Admin should have been switched into a real workspace');
    }

    /**
     * Live writing is off by default and can be opted into via extension config.
     */
    public function testLiveWritingOptInReflectsExtensionConfiguration(): void
    {
        $service = GeneralUtility::makeInstance(WorkspaceContextService::class);

        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['allowLiveWrites']);
        $this->assertFalse($service->isLiveWritingAllowed(), 'Live writing must be disabled by default');

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['allowLiveWrites'] = '1';
        $this->assertTrue($service->isLiveWritingAllowed(), 'Live writing must be enabled when opted in');

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['allowLiveWrites'] = '0';
        $this->assertFalse($service->isLiveWritingAllowed(), 'A disabled checkbox ("0") must not enable live writing');
    }

    /**
     * With the opt-in enabled, the guard no longer refuses a live write.
     */
    public function testGuardAllowsLiveWriteWhenOptedIn(): void
    {
        $this->setupDefaultBackendUser(77);
        // Model the state after initialize(): switchToOptimalWorkspace() could
        // not find or create a workspace and resolved to live (0).
        $GLOBALS['BE_USER']->workspace = 0;

        $guard = new \ReflectionMethod(WriteTableTool::class, 'assertWorkspaceForWriting');
        $guard->setAccessible(true);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['allowLiveWrites'] = '0';
        $this->assertNotNull($guard->invoke($this->writeTool), 'Guard must refuse a live write while opt-in is off');

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['allowLiveWrites'] = '1';
        $this->assertNull($guard->invoke($this->writeTool), 'Guard must permit a live write once opt-in is on');
    }
}
