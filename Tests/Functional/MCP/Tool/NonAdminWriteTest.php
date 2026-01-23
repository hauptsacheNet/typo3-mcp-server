<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Test non-admin user write operations.
 *
 * This test focuses on the "positive path" - verifying that a properly
 * configured non-admin user CAN write content. If this test fails,
 * it reveals the root cause of the permission issue.
 */
class NonAdminWriteTest extends AbstractFunctionalTest
{
    protected WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data for non-admin user scenario
        $this->createNonAdminUser();
        $this->createWorkspaceWithNonAdminMember();
        $this->createPageWithNonAdminPermissions();

        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
    }

    /**
     * Create a non-admin backend user (UID 99)
     */
    protected function createNonAdminUser(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('be_users');

        // Check if user already exists
        $exists = $connection->count('*', 'be_users', ['uid' => 99]);
        if ($exists > 0) {
            return;
        }

        $connection->insert('be_users', [
            'uid' => 99,
            'pid' => 0,
            'username' => 'test_editor',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'disable' => 0,
            'deleted' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'userMods' => 'web_WorkspacesWorkspaces', // Allow workspace access
        ]);
    }

    /**
     * Create a workspace where non-admin user (99) is a member
     */
    protected function createWorkspaceWithNonAdminMember(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_workspace');

        // Check if workspace already exists
        $exists = $connection->count('*', 'sys_workspace', ['uid' => 50]);
        if ($exists > 0) {
            return;
        }

        // Use minimal fields that exist in all TYPO3 versions
        $connection->insert('sys_workspace', [
            'uid' => 50,
            'pid' => 0,
            'title' => 'Editor Workspace',
            'adminusers' => 'be_users_1', // Admin user is workspace admin
            'members' => 'be_users_99', // Non-admin user is member
            'deleted' => 0,
        ]);
    }

    /**
     * Create a page where non-admin user (99) has full permissions
     */
    protected function createPageWithNonAdminPermissions(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('pages');

        // Check if page already exists
        $exists = $connection->count('*', 'pages', ['uid' => 100]);
        if ($exists > 0) {
            return;
        }

        $connection->insert('pages', [
            'uid' => 100,
            'pid' => 1, // Child of root page
            'title' => 'Editor Test Page',
            'slug' => '/editor-test',
            'doktype' => 1,
            'hidden' => 0,
            'deleted' => 0,
            'sorting' => 1024,
            'tstamp' => time(),
            'crdate' => time(),
            // Page permissions for non-admin user
            'perms_userid' => 99, // User 99 owns this page
            'perms_user' => 31,   // All permissions: 1+2+4+8+16 (show+edit+delete+new+editcontent)
            'perms_groupid' => 0,
            'perms_group' => 0,
            'perms_everybody' => 0,
        ]);
    }

    /**
     * Get all non_exclude_fields for tt_content that a non-admin editor needs
     */
    protected function getAllTtContentExcludeFields(): string
    {
        // These are the most common exclude fields in tt_content
        $fields = [
            'tt_content:CType',
            'tt_content:colPos',
            'tt_content:header',
            'tt_content:header_layout',
            'tt_content:header_position',
            'tt_content:header_link',
            'tt_content:subheader',
            'tt_content:bodytext',
            'tt_content:image',
            'tt_content:imagewidth',
            'tt_content:imageheight',
            'tt_content:imageorient',
            'tt_content:imagecols',
            'tt_content:imageborder',
            'tt_content:media',
            'tt_content:layout',
            'tt_content:frame_class',
            'tt_content:space_before_class',
            'tt_content:space_after_class',
            'tt_content:sectionIndex',
            'tt_content:linkToTop',
            'tt_content:sys_language_uid',
            'tt_content:hidden',
            'tt_content:starttime',
            'tt_content:endtime',
            'tt_content:fe_group',
            'tt_content:editlock',
            'tt_content:categories',
            'tt_content:rowDescription',
        ];

        return implode(',', $fields);
    }

    /**
     * Test that a properly configured non-admin user can write content.
     *
     * This is the "positive path" test - it should SUCCEED if all permissions
     * are correctly configured. If it fails, the error message will reveal
     * the actual root cause.
     */
    public function testNonAdminCanWriteContentWithFullPermissions(): void
    {
        // 1. Set up BE_USER as user 99 (non-admin)
        $user = $this->setupDefaultBackendUser(99);

        // 2. Configure user permissions
        $user->groupData['tables_select'] = 'pages,tt_content';
        $user->groupData['tables_modify'] = 'pages,tt_content';
        $user->groupData['non_exclude_fields'] = $this->getAllTtContentExcludeFields();

        // Ensure user has workspace module access
        $user->groupData['modules'] = 'web_WorkspacesWorkspaces';

        // DB Mount points - non-admin users need this to access page tree
        $user->groupData['webmounts'] = '1,100';
        // Also set on user object directly
        $user->user['db_mountpoints'] = '1';

        // Explicit allow for CType values (TYPO3's authMode system)
        // Format is table:field:value (NOT table:field:value:ALLOW)
        $user->groupData['explicit_allowdeny'] = 'tt_content:CType:text,tt_content:CType:header,tt_content:CType:textmedia';

        // 3. Switch to workspace 50 (where user 99 is a member)
        $this->switchToWorkspace(50);

        // Verify workspace is set
        $this->assertEquals(50, $GLOBALS['BE_USER']->workspace, 'Workspace should be 50');
        $this->assertFalse($GLOBALS['BE_USER']->isAdmin(), 'User should NOT be admin');

        // DEBUG: Verify page 100 exists and has correct permissions
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $queryBuilder = $connection->createQueryBuilder();
        $page = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', 100))
            ->executeQuery()
            ->fetchAssociative();

        $this->assertNotFalse($page, 'Page 100 should exist');
        $this->assertEquals(99, $page['perms_userid'], 'Page owner should be user 99');
        $this->assertEquals(31, $page['perms_user'], 'Page should have full user permissions');
        $this->assertEquals(1, $page['doktype'], 'Page should be standard doktype');

        // 4. Execute write operation on page 100 (where user 99 has permissions)
        // Note: sys_language_uid should be auto-added by WriteTableTool now
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 100,
            'data' => [
                'CType' => 'text',
                'header' => 'Non-Admin Test Content',
                'bodytext' => 'This content was created by a non-admin user.',
            ]
        ]);

        // 5. This SHOULD succeed - if it fails, the error reveals the root cause
        $this->assertFalse(
            $result->isError,
            'Non-admin write FAILED with full permissions configured. ' .
            'Page data: ' . json_encode($page) . '. Error: ' .
            json_encode($result->jsonSerialize(), JSON_PRETTY_PRINT)
        );

        // 6. Verify the content was created
        $responseData = json_decode($result->content[0]->text, true);
        $this->assertIsArray($responseData, 'Response should be valid JSON');
        $this->assertArrayHasKey('uid', $responseData, 'Response should contain UID');
        $this->assertGreaterThan(0, $responseData['uid'], 'UID should be positive');
    }

    /**
     * Test non-admin can write to page 1 (root page from fixtures)
     * This helps isolate whether the issue is with page 100 specifically
     */
    public function testNonAdminCanWriteToRootPage(): void
    {
        // 1. Set up BE_USER as user 99 (non-admin)
        $user = $this->setupDefaultBackendUser(99);

        // 2. Configure user permissions
        $user->groupData['tables_select'] = 'pages,tt_content';
        $user->groupData['tables_modify'] = 'pages,tt_content';
        $user->groupData['non_exclude_fields'] = $this->getAllTtContentExcludeFields();
        $user->groupData['modules'] = 'web_WorkspacesWorkspaces';

        // DB Mount points - non-admin users need this to access page tree
        $user->groupData['webmounts'] = '1';
        $user->user['db_mountpoints'] = '1';

        // Explicit allow for CType values
        // Format is table:field:value (NOT table:field:value:ALLOW)
        $user->groupData['explicit_allowdeny'] = 'tt_content:CType:text,tt_content:CType:header';

        // Give user 99 permissions on page 1 (update existing page)
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $connection->update('pages', [
            'perms_userid' => 99,
            'perms_user' => 31,
        ], ['uid' => 1]);

        // 3. Switch to workspace 50
        $this->switchToWorkspace(50);

        // 4. Try to create content on page 1
        // Note: sys_language_uid should be auto-added by WriteTableTool now
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Non-Admin on Root Page',
            ]
        ]);

        // 5. Check result
        $this->assertFalse(
            $result->isError,
            'Non-admin write to page 1 FAILED. Error: ' .
            json_encode($result->jsonSerialize(), JSON_PRETTY_PRINT)
        );
    }

    /**
     * Test that admin user can still write (baseline test)
     */
    public function testAdminCanWriteContent(): void
    {
        // Use admin user (UID 1)
        $this->setupDefaultBackendUser(1);

        // Create workspace for admin using simplified insert
        $connection = $this->connectionPool->getConnectionForTable('sys_workspace');
        $connection->insert('sys_workspace', [
            'title' => 'Admin Test Workspace',
            'adminusers' => 'be_users_1',
            'members' => '',
            'pid' => 0,
            'deleted' => 0,
        ]);
        $workspaceId = (int)$connection->lastInsertId();
        $this->switchToWorkspace($workspaceId);

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Admin Test Content'
            ]
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * Test read operation for non-admin (should work with basic permissions)
     */
    public function testNonAdminCanRead(): void
    {
        // Set up non-admin user
        $user = $this->setupDefaultBackendUser(99);
        $user->groupData['tables_select'] = 'pages,tt_content';

        // Switch to workspace
        $this->switchToWorkspace(50);

        // Create a ReadTableTool and try to read
        $readTool = GeneralUtility::makeInstance(\Hn\McpServer\MCP\Tool\Record\ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'pages',
            'uid' => 100
        ]);

        $this->assertFalse($result->isError, 'Non-admin should be able to read pages: ' . json_encode($result->jsonSerialize()));
    }
}
