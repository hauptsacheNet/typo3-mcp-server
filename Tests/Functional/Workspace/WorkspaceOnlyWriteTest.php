<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\Traits\RestrictedEditorTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Live data must never be edited through the MCP tools: every write lands in
 * a workspace, whatever the user's permissions and whatever global state the
 * caller has (or has not) set up.
 */
class WorkspaceOnlyWriteTest extends FunctionalTestCase
{
    use RestrictedEditorTrait;

    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importRestrictedEditorFixture();
    }

    public function testRestrictedEditorWritesNeverReachLive(): void
    {
        $this->actAsBackendUser(self::RESTRICTED_EDITOR_UID);
        $liveBefore = $this->fetchLiveRecords('tt_content');
        $tool = new WriteTableTool();

        $create = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => self::MOUNTED_PAGE_UID,
                'CType' => 'html',
                'header' => 'Workspace only',
                'bodytext' => '<p>new</p>',
            ],
        ]);
        $this->assertFalse($create->isError, json_encode($create->jsonSerialize()));
        $newUid = (int)$this->decodeToolResult($create)['uid'];

        $update = $tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
            'data' => ['bodytext' => '<p>updated</p>'],
        ]);
        $this->assertFalse($update->isError, json_encode($update->jsonSerialize()));

        // The uid handed out for the new record is usable for further writes
        $updateNew = $tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $newUid,
            'data' => ['bodytext' => '<p>new, updated</p>'],
        ]);
        $this->assertFalse($updateNew->isError, json_encode($updateNew->jsonSerialize()));

        $delete = $tool->execute([
            'action' => 'delete',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
        ]);
        $this->assertFalse($delete->isError, json_encode($delete->jsonSerialize()));

        $this->assertEquals($liveBefore, $this->fetchLiveRecords('tt_content'), 'Live tt_content rows must be unchanged');
        $workspaceRows = $this->fetchRawRecords('tt_content', ['t3ver_wsid' => self::EDITORIAL_WORKSPACE_UID]);
        $this->assertNotEmpty($workspaceRows);
        $this->assertContains('<p>new, updated</p>', array_column($workspaceRows, 'bodytext'));
    }

    /**
     * An editor who is neither member of a workspace nor allowed to create one
     * (no workspace module), but keeps the default "live editing" permission.
     * The MCP must not fall back to writing live for this user.
     */
    public function testEditorWithoutWorkspaceDoesNotWriteLive(): void
    {
        $this->actAsBackendUser(self::EDITOR_WITHOUT_WORKSPACE_UID);
        $this->assertTrue((bool)($GLOBALS['BE_USER']->groupData['workspace_perms'] & 1), 'Precondition: live editing permitted by core');
        $liveBefore = $this->fetchLiveRecords('tt_content');

        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
            'data' => ['bodytext' => '<p>would be live</p>'],
        ]);

        $this->assertEquals($liveBefore, $this->fetchLiveRecords('tt_content'), 'The live record must not be modified');
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * Regression: on TYPO3 12, WorkspaceService and DataHandler read
     * $GLOBALS['LANG'] while listing and creating workspaces. Without it the
     * automatic workspace creation failed and the write went live. Callers of
     * the tools (CLI, scheduler, custom integrations) may not set LANG.
     */
    public function testWriteWithoutGlobalLanguageServiceCreatesWorkspace(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_workspace')
            ->truncate('sys_workspace');
        $this->actAsBackendUser(1);
        unset($GLOBALS['LANG']);

        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
            'data' => ['bodytext' => '<p>admin change</p>'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame('<p>Original markup</p>', $this->fetchRawRecord('tt_content', self::HTML_ELEMENT_UID)['bodytext']);
        $workspaces = $this->fetchRawRecords('sys_workspace', []);
        $this->assertCount(1, $workspaces, 'The MCP workspace must have been created');
        $version = $this->fetchWorkspaceVersion('tt_content', self::HTML_ELEMENT_UID, (int)$workspaces[0]['uid']);
        $this->assertNotNull($version);
        $this->assertSame('<p>admin change</p>', $version['bodytext']);
    }

    /**
     * Same regression with an existing workspace the user administers: listing
     * the available workspaces must work without $GLOBALS['LANG'] as well.
     */
    public function testWriteWithoutGlobalLanguageServiceUsesExistingWorkspace(): void
    {
        $this->actAsBackendUser(1);
        unset($GLOBALS['LANG']);

        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
            'data' => ['bodytext' => '<p>admin change</p>'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame('<p>Original markup</p>', $this->fetchRawRecord('tt_content', self::HTML_ELEMENT_UID)['bodytext']);
        $this->assertCount(1, $this->fetchRawRecords('sys_workspace', []), 'No additional workspace expected');
        $this->assertNotNull($this->fetchWorkspaceVersion('tt_content', self::HTML_ELEMENT_UID, self::EDITORIAL_WORKSPACE_UID));
    }
}
