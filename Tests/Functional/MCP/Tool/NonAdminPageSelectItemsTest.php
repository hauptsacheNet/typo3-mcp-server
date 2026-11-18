<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Select items of an existing record must be resolved with the rights of the
 * backend edit form, not with the rights needed to create a new record.
 *
 * Scenario: a non-admin editor may edit a page but must not create pages on its
 * parent. pages.backend_layout gets its items from an itemsProcFunc
 * (BackendLayoutView::addBackendLayoutItems). If the items are compiled as a
 * "new" record on the parent page, FormEngine throws AccessDeniedPageNewException,
 * only the static TCA items remain and every backend layout is rejected, although
 * the backend form offers them to the same editor.
 */
class NonAdminPageSelectItemsTest extends AbstractFunctionalTest
{
    private const GROUP_UID = 10;
    private const EDITOR_UID = 99;
    private const WORKSPACE_UID = 50;
    private const PAGE_UID = 100;
    private const CONTENT_UID = 500;

    protected WriteTableTool $writeTool;
    protected TableAccessService $tableAccessService;

    protected function setUp(): void
    {
        parent::setUp();

        // Page rights are only calculated for users with a backend group.
        $this->connectionPool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => self::GROUP_UID,
            'pid' => 0,
            'title' => 'Page editors',
            'tables_select' => 'pages,tt_content',
            'tables_modify' => 'pages,tt_content',
            'non_exclude_fields' => 'pages:title,pages:doktype,pages:backend_layout,pages:backend_layout_next_level,'
                . 'tt_content:CType,tt_content:header',
            'pagetypes_select' => '1',
            'explicit_allowdeny' => 'tt_content:CType:text',
            'db_mountpoints' => '1',
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        $this->connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::EDITOR_UID,
            'pid' => 0,
            'username' => 'page_editor',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'usergroup' => (string)self::GROUP_UID,
            // Take DB and file mounts from the group (the database default differs
            // between TYPO3 versions).
            'options' => 3,
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => self::WORKSPACE_UID,
            'pid' => 0,
            'title' => 'Editor Workspace',
            'adminusers' => 'be_users_1',
            'members' => 'be_users_' . self::EDITOR_UID,
        ]);

        // Parent page 1: the editor may only see it (perms_everybody = show), so it
        // grants no PAGE_NEW. It defines a backend layout through page TSconfig.
        $this->connectionPool->getConnectionForTable('pages')->update('pages', [
            'perms_userid' => 1,
            'perms_user' => 31,
            'perms_groupid' => 0,
            'perms_group' => 0,
            'perms_everybody' => 1,
            'TSconfig' => implode("\n", [
                'mod.web_layout.BackendLayouts.example {',
                '  title = Example',
                '  config.backend_layout {',
                '    colCount = 1',
                '    rowCount = 1',
                '    rows.1.columns.1 {',
                '      name = Main',
                '      colPos = 0',
                '    }',
                '  }',
                '}',
            ]),
        ], ['uid' => 1]);

        // The page itself: the editor may show and edit it and edit its content.
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE_UID,
            'pid' => 1,
            'title' => 'Editable Page',
            'slug' => '/editable-page',
            'doktype' => 1,
            'sorting' => 1024,
            'tstamp' => time(),
            'crdate' => time(),
            'perms_userid' => 1,
            'perms_user' => 31,
            'perms_groupid' => self::GROUP_UID,
            'perms_group' => 19,
            'perms_everybody' => 0,
        ]);

        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::CONTENT_UID,
            'pid' => self::PAGE_UID,
            'CType' => 'text',
            'header' => 'Text element',
            'colPos' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        $this->setupDefaultBackendUser(self::EDITOR_UID);
        $this->switchToWorkspace(self::WORKSPACE_UID);

        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
    }

    public function testEditorHasNoPageNewPermissionOnParent(): void
    {
        $backendUser = $GLOBALS['BE_USER'];
        $this->assertFalse($backendUser->isAdmin());
        $parentPermissions = new Permission($backendUser->calcPerms(BackendUtility::getRecord('pages', 1)));
        $this->assertFalse(
            $parentPermissions->createPagePermissionIsGranted(),
            'Precondition: the editor must not be allowed to create pages on the parent page'
        );
        $pagePermissions = new Permission($backendUser->calcPerms(BackendUtility::getRecord('pages', self::PAGE_UID)));
        $this->assertTrue(
            $pagePermissions->editPagePermissionIsGranted(),
            'Precondition: the editor must be allowed to edit the page'
        );
    }

    public function testAllowedValuesOfExistingPageIncludeItemsProcFuncItems(): void
    {
        $record = BackendUtility::getRecord('pages', self::PAGE_UID);
        $allowedValues = $this->tableAccessService->getSelectFieldAllowedValues('pages', 'backend_layout', $record);

        $this->assertIsArray($allowedValues);
        $this->assertContains('pagets__example', $allowedValues, 'Allowed: ' . json_encode($allowedValues));
    }

    public function testEditorCanSetBackendLayoutWithoutPageNewPermissionOnParent(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => self::PAGE_UID,
            'data' => [
                'backend_layout' => 'pagets__example',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $page = BackendUtility::getRecord('pages', self::PAGE_UID);
        BackendUtility::workspaceOL('pages', $page);
        $this->assertSame('pagets__example', $page['backend_layout']);
    }

    public function testUnknownBackendLayoutIsStillRejected(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => self::PAGE_UID,
            'data' => [
                'backend_layout' => 'pagets__unknown',
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('must be one of', $result->content[0]->text);
        $this->assertStringContainsString('pagets__example', $result->content[0]->text);
    }

    /**
     * Fields FormEngine's edit access check reads (authMode, page types) keep their
     * stored values when the items are compiled. With the submitted value the check
     * would throw, and the static TCA fallback would accept a content type the
     * editor may not use.
     */
    public function testContentTypeOutsideAuthModeIsRejectedOnUpdate(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::CONTENT_UID,
            'data' => [
                'CType' => 'header',
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("Field 'CType' value 'header' must be one of", $result->content[0]->text);
        $this->assertSame('text', BackendUtility::getRecord('tt_content', self::CONTENT_UID)['CType']);
    }

    public function testPageTypeOutsidePagetypesSelectIsRejectedOnUpdate(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => self::PAGE_UID,
            'data' => [
                'doktype' => 254,
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("Field 'doktype' value '254' must be one of", $result->content[0]->text);
    }
}
