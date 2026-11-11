<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Fields with displayCond HIDE_FOR_NON_ADMINS (pages.TSconfig, tsconfig_includes,
 * editlock) are not exclude fields, so no backend group can grant them, and
 * DataHandler drops them for every non-admin. The MCP tools must not offer them
 * to a non-admin: WriteTable would otherwise report a change that is never stored.
 */
class AdminOnlyFieldsTest extends AbstractFunctionalTest
{
    private const GROUP_UID = 10;
    private const EDITOR_UID = 99;
    private const WORKSPACE_UID = 50;
    private const PAGE_UID = 100;
    private const ADMIN_ONLY_FIELDS = ['TSconfig', 'tsconfig_includes', 'editlock'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => self::GROUP_UID,
            'pid' => 0,
            'title' => 'Page editors',
            'tables_select' => 'pages',
            'tables_modify' => 'pages',
            // Listing the admin-only fields here has no effect in the backend, and
            // must have none in MCP either.
            'non_exclude_fields' => 'pages:title,pages:TSconfig,pages:tsconfig_includes,pages:editlock',
            'pagetypes_select' => '1',
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

        $this->connectionPool->getConnectionForTable('pages')->update('pages', [
            'perms_everybody' => 1,
        ], ['uid' => 1]);

        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE_UID,
            'pid' => 1,
            'title' => 'Editable Page',
            'slug' => '/editable-page',
            'doktype' => 1,
            'sorting' => 1024,
            'tstamp' => time(),
            'crdate' => time(),
            'TSconfig' => 'TCEFORM.pages.subtitle.disabled = 1',
            'perms_userid' => 1,
            'perms_user' => 31,
            'perms_groupid' => self::GROUP_UID,
            'perms_group' => 3,
            'perms_everybody' => 0,
        ]);
    }

    private function actAsEditor(): void
    {
        $this->setupDefaultBackendUser(self::EDITOR_UID);
        $this->switchToWorkspace(self::WORKSPACE_UID);
        $this->assertFalse($GLOBALS['BE_USER']->isAdmin());
    }

    public function testSchemaDoesNotOfferAdminOnlyFieldsToEditor(): void
    {
        $this->actAsEditor();

        $result = GeneralUtility::makeInstance(GetTableSchemaTool::class)->execute([
            'table' => 'pages',
            'pid' => self::PAGE_UID,
            'type' => '1',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $schema = $result->content[0]->text;
        $this->assertMatchesRegularExpression('/\btitle \(/', $schema, 'Granted fields must stay listed');
        foreach (self::ADMIN_ONLY_FIELDS as $field) {
            $this->assertDoesNotMatchRegularExpression('/\b' . $field . ' \(/', $schema, $field . ' must not be listed');
        }
    }

    public function testReadTableDoesNotReturnAdminOnlyFieldsToEditor(): void
    {
        $this->actAsEditor();

        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'pages',
            'uid' => self::PAGE_UID,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertSame('Editable Page', $record['title']);
        foreach (self::ADMIN_ONLY_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $record);
        }
    }

    public static function adminOnlyFieldValues(): array
    {
        return [
            'TSconfig' => ['TSconfig', 'TCEFORM.pages.title.disabled = 1'],
            'editlock' => ['editlock', 1],
        ];
    }

    #[DataProvider('adminOnlyFieldValues')]
    public function testEditorWriteToAdminOnlyFieldIsRejected(string $field, mixed $value): void
    {
        $this->actAsEditor();

        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => self::PAGE_UID,
            'data' => [$field => $value],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("Field '{$field}' is not accessible", $result->content[0]->text);

        $page = BackendUtility::getRecord('pages', self::PAGE_UID);
        BackendUtility::workspaceOL('pages', $page);
        $this->assertSame('TCEFORM.pages.subtitle.disabled = 1', $page['TSconfig']);
        $this->assertSame(0, (int)$page['editlock']);
    }

    public function testAdminCanStillWriteTSconfig(): void
    {
        $this->assertTrue($GLOBALS['BE_USER']->isAdmin());

        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => self::PAGE_UID,
            'data' => ['TSconfig' => 'TCEFORM.pages.nav_title.disabled = 1'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $page = BackendUtility::getRecord('pages', self::PAGE_UID);
        BackendUtility::workspaceOL('pages', $page);
        $this->assertSame('TCEFORM.pages.nav_title.disabled = 1', $page['TSconfig']);
    }
}
