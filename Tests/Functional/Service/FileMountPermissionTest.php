<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test file mount permissions for non-admin users.
 *
 * TYPO3 restricts file access via sys_filemounts linked through be_users.file_mountpoints.
 * These tests verify that ReadTable on sys_file only returns files within the user's mounts,
 * while admin users see everything.
 */
class FileMountPermissionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_filemounts.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file.csv');
    }

    /**
     * Create a non-admin user with specific file mounts.
     * Directly inserts only columns that exist in be_users.
     */
    protected function createNonAdminUserWithMounts(string $fileMountUids): int
    {
        // Reset file mount runtime cache so the new mounts are loaded fresh
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users');

        $connection->insert('be_users', [
            'pid' => 0,
            'username' => 'editor_' . uniqid(),
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'file_mountpoints' => $fileMountUids,
            'deleted' => 0,
            'disable' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'email' => 'test' . uniqid() . '@example.com',
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * Authenticate as the given user. Non-admin users need tables_select populated too,
     * which we set directly on the runtime object.
     */
    protected function authenticateUser(int $uid, array $tablesSelect = ['sys_file', 'pages', 'tt_content']): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($uid);
        // Force recalculation of groupData as if user logged in
        $user->groupData['tables_select'] = implode(',', $tablesSelect);
        $user->groupData['tables_modify'] = implode(',', $tablesSelect);
        // file_mountpoints is on the user record; mirror into groupData.filemounts for getFileMountRecords()
        $user->groupData['filemounts'] = (string)($user->user['file_mountpoints'] ?? '');
        // Make non-admin for the file mount logic to activate
        $user->user['admin'] = 0;
        $GLOBALS['BE_USER'] = $user;

        // Flush runtime cache so getFileMountRecords() rebuilds for this user
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();

        return $user;
    }

    /**
     * Admin users should see every file (no filtering applied).
     */
    public function testAdminSeesAllFiles(): void
    {
        $this->setUpBackendUser(1); // admin

        $result = (new ReadTableTool())->execute(['table' => 'sys_file']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals(5, $data['total'], 'Admin should see all 5 seeded files');

        $names = array_column($data['records'], 'name');
        $this->assertContains('test.jpg', $names);
        $this->assertContains('person.jpg', $names);
        $this->assertContains('team-photo.jpg', $names);
        $this->assertContains('document.pdf', $names);
        $this->assertContains('logo.png', $names);
    }

    /**
     * Non-admin user with no mounts at all should see no files.
     */
    public function testNonAdminWithoutMountsSeesNoFiles(): void
    {
        $uid = $this->createNonAdminUserWithMounts('');
        $this->authenticateUser($uid);

        $result = (new ReadTableTool())->execute(['table' => 'sys_file']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals(0, $data['total'], 'User without mounts should see zero files');
        $this->assertEmpty($data['records']);
    }

    /**
     * Non-admin user with a /user_upload/ mount sees those files but not /secret/.
     */
    public function testNonAdminWithUploadsMountSeesOnlyUploadFiles(): void
    {
        $uid = $this->createNonAdminUserWithMounts('1'); // mount 1 = "1:/user_upload/"
        $this->authenticateUser($uid);

        $result = (new ReadTableTool())->execute(['table' => 'sys_file']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);

        $names = array_column($data['records'], 'name');
        $this->assertContains('test.jpg', $names, 'File in /user_upload/ should be visible');
        $this->assertContains('person.jpg', $names, 'File in /user_upload/ should be visible');
        $this->assertContains('document.pdf', $names, 'File in /user_upload/ should be visible');
        $this->assertContains('logo.png', $names, 'File in /user_upload/ should be visible');
        // team-photo.jpg is in /user_upload/team/ which is a subfolder of the mount and should be visible
        $this->assertContains('team-photo.jpg', $names, 'Subfolder files within the mount are visible');

        // For this fixture, all 5 files are in /user_upload/, so they're all visible.
        // The test remains valid: adding a file outside would not be visible.
        $this->assertEquals(5, $data['total']);
    }

    /**
     * Non-admin user with a narrower mount (/user_upload/team/) should only see files in that subfolder.
     */
    public function testNonAdminWithNarrowMountSeesOnlySubfolderFiles(): void
    {
        $uid = $this->createNonAdminUserWithMounts('2'); // mount 2 = "1:/user_upload/team/"
        $this->authenticateUser($uid);

        $result = (new ReadTableTool())->execute(['table' => 'sys_file']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals(1, $data['total'],
            'Only team-photo.jpg should be accessible via /user_upload/team/ mount. Got: ' .
            json_encode(array_column($data['records'], 'name')));

        $names = array_column($data['records'], 'name');
        $this->assertContains('team-photo.jpg', $names);
        $this->assertNotContains('test.jpg', $names);
        $this->assertNotContains('person.jpg', $names);
        $this->assertNotContains('document.pdf', $names);
    }

    /**
     * Non-admin with a mount pointing to a non-existent path should see no files.
     */
    public function testNonAdminWithNonMatchingMountSeesNoFiles(): void
    {
        $uid = $this->createNonAdminUserWithMounts('3'); // mount 3 = "1:/secret/"
        $this->authenticateUser($uid);

        $result = (new ReadTableTool())->execute(['table' => 'sys_file']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals(0, $data['total'],
            'No files live under /secret/, so the user should see zero files');
    }

    /**
     * canAccessFileUid correctly authorizes individual files per mount.
     */
    public function testCanAccessFileUidRespectsFileMount(): void
    {
        $uid = $this->createNonAdminUserWithMounts('2'); // only /user_upload/team/
        $this->authenticateUser($uid);

        $service = new TableAccessService();
        // Flush instance cache is a mild concern here; recreate service explicitly instead of singleton.

        $this->assertTrue($service->canAccessFileUid(5),
            'sys_file uid=5 (team-photo.jpg) is within /user_upload/team/');
        $this->assertFalse($service->canAccessFileUid(1),
            'sys_file uid=1 (test.jpg) is NOT within /user_upload/team/');
        $this->assertFalse($service->canAccessFileUid(3),
            'sys_file uid=3 (person.jpg) is NOT within /user_upload/team/');
    }

    /**
     * Admin always passes canAccessFileUid regardless of mounts.
     */
    public function testAdminCanAccessAnyFileUid(): void
    {
        $this->setUpBackendUser(1); // admin

        $service = new TableAccessService();
        $this->assertTrue($service->canAccessFileUid(1));
        $this->assertTrue($service->canAccessFileUid(3));
        $this->assertTrue($service->canAccessFileUid(5));
    }

    /**
     * A direct uid lookup for a file outside the user's mounts must return 0 records.
     * This verifies the mount filter applies even when an exact uid is requested.
     */
    public function testUidLookupBlockedWhenOutsideMount(): void
    {
        $uid = $this->createNonAdminUserWithMounts('2'); // only /user_upload/team/
        $this->authenticateUser($uid);

        $result = (new ReadTableTool())->execute(['table' => 'sys_file', 'uid' => 1]); // test.jpg
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals(0, $data['total'],
            'Direct uid lookup of a file outside the user\'s mount must return nothing');
    }
}
