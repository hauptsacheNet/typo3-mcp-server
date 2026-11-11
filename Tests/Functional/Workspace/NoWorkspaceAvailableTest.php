<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Backend\Module\ModuleRegistry;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A non-admin editor without a writable workspace must never write to live.
 */
class NoWorkspaceAvailableTest extends AbstractFunctionalTest
{
    private const EDITOR_UID = 99;
    private const EDITOR_PAGE_UID = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::EDITOR_UID,
            'pid' => 0,
            'username' => 'editor_without_workspace',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
        ]);
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::EDITOR_PAGE_UID,
            'pid' => 1,
            'title' => 'Editor Page',
            'slug' => '/editor-page',
            'doktype' => 1,
            'perms_userid' => self::EDITOR_UID,
            'perms_user' => 31,
        ]);
    }

    public function testWriteIsRejectedInsteadOfGoingLive(): void
    {
        $editor = $this->setUpEditor(liveEditAllowed: true);
        $this->assertSame(0, $editor->workspace, 'Precondition: editor starts in live');

        $result = $this->createContent('Must not go live');

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('No workspace available', $result->content[0]->text);
        $this->assertSame(0, $this->countContentWithHeader('Must not go live'));
    }

    public function testWriteIsRejectedWithClearErrorWithoutLiveAccess(): void
    {
        $this->setUpEditor(liveEditAllowed: false);

        $result = $this->createContent('No access at all');

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('No workspace available', $result->content[0]->text);
        $this->assertSame(0, $this->countContentWithHeader('No access at all'));
    }

    public function testWorkspaceIsCreatedForEditorWithWorkspacesModule(): void
    {
        $editor = $this->setUpEditor(liveEditAllowed: true);
        // Groups saved in the backend store the current module identifier, not the legacy alias
        $editor->groupData['modules'] = GeneralUtility::makeInstance(ModuleRegistry::class)
            ->getModule('web_WorkspacesWorkspaces')
            ->getIdentifier();

        $workspaceId = GeneralUtility::makeInstance(WorkspaceContextService::class)->switchToOptimalWorkspace($editor);

        $this->assertGreaterThan(0, $workspaceId);
    }

    private function setUpEditor(bool $liveEditAllowed): BackendUserAuthentication
    {
        $editor = $this->setupDefaultBackendUser(self::EDITOR_UID);
        $editor->groupData['workspace_perms'] = $liveEditAllowed ? 1 : 0;
        $editor->groupData['modules'] = '';
        $editor->groupData['tables_select'] = 'pages,tt_content';
        $editor->groupData['tables_modify'] = 'pages,tt_content';
        $editor->groupData['non_exclude_fields'] = 'tt_content:CType,tt_content:colPos,tt_content:header,tt_content:sys_language_uid';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text';
        $editor->groupData['webmounts'] = '1,' . self::EDITOR_PAGE_UID;
        $editor->setTemporaryWorkspace(0);

        return $editor;
    }

    private function createContent(string $header): \Mcp\Types\CallToolResult
    {
        return GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => self::EDITOR_PAGE_UID,
            'data' => ['CType' => 'text', 'header' => $header],
        ]);
    }

    private function countContentWithHeader(string $header): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder->count('uid')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('header', $queryBuilder->createNamedParameter($header)))
            ->executeQuery()
            ->fetchOne();
    }
}
