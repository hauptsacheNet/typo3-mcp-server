<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ListTablesTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * sys_file_metadata is exposed as a standalone editable table (configured via
 * `additionalStandaloneTables`), instead of being embedded into sys_file.
 * sys_file itself remains read-only — only metadata is editable.
 */
class SysFileMetadataStandaloneTest extends FunctionalTestCase
{
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
        $this->createMultiLanguageSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_filemounts.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_metadata.csv');
        $this->setUpBackendUser(1);
    }

    private function createMultiLanguageSiteConfiguration(): void
    {
        $siteConfiguration = [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'websiteTitle' => 'Test Site',
            'languages' => [
                0 => [
                    'title' => 'English', 'enabled' => true, 'languageId' => 0,
                    'base' => '/', 'locale' => 'en_US.UTF-8', 'iso-639-1' => 'en',
                    'hreflang' => 'en-us', 'direction' => 'ltr', 'flag' => 'us',
                    'navigationTitle' => 'English',
                ],
                1 => [
                    'title' => 'German', 'enabled' => true, 'languageId' => 1,
                    'base' => '/de/', 'locale' => 'de_DE.UTF-8', 'iso-639-1' => 'de',
                    'hreflang' => 'de-de', 'direction' => 'ltr', 'flag' => 'de',
                    'navigationTitle' => 'Deutsch',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ];
        $configPath = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($configPath);
        GeneralUtility::writeFile($configPath . '/config.yaml', Yaml::dump($siteConfiguration, 99, 2), true);
    }

    public function testListTablesShowsSysFileMetadataAsCoreFile(): void
    {
        $tool = GeneralUtility::makeInstance(ListTablesTool::class);
        $result = $tool->execute([]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // sys_file_metadata must appear as core/file, not as "unknown" extension.
        $this->assertStringContainsString('sys_file_metadata', $content);
        $this->assertMatchesRegularExpression(
            '/CORE TABLES:.*?sys_file_metadata.*?\[file\]/s',
            $content,
            'sys_file_metadata should be listed under CORE TABLES with [file] type. Got: ' . $content
        );
        // Specifically: not read-only.
        $this->assertDoesNotMatchRegularExpression(
            '/sys_file_metadata.*?\[READ-ONLY\]/',
            $content,
            'sys_file_metadata should NOT be flagged as read-only'
        );
    }

    public function testSysFileEmbedsMetadataAsUidListNotObjects(): void
    {
        $tool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $tool->execute(['table' => 'sys_file', 'uid' => 1]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $file = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertArrayHasKey('metadata', $file);
        $this->assertSame(
            [1],
            $file['metadata'],
            'metadata should collapse to a list of uids when sys_file_metadata is exposed standalone'
        );
    }

    public function testReadSysFileMetadataDirectly(): void
    {
        $tool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $tool->execute(['table' => 'sys_file_metadata', 'uid' => 1]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $records = json_decode($result->content[0]->text, true)['records'];
        $this->assertCount(1, $records);
        $this->assertSame('Test Image Title', $records[0]['title']);
        $this->assertSame(1, $records[0]['file'], 'foreign key to sys_file is exposed for discovery');
    }

    public function testUpdateSysFileMetadataDirectly(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'sys_file_metadata',
            'action' => 'update',
            'uid' => 1,
            'data' => [
                'title' => 'New Title via MCP',
                'alternative' => 'New Alt via MCP',
                'description' => 'New Description via MCP',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read back through the standard read pipeline (workspace overlay applied).
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute(['table' => 'sys_file_metadata', 'uid' => 1]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $record = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertSame('New Title via MCP', $record['title']);
        $this->assertSame('New Alt via MCP', $record['alternative']);
        $this->assertSame('New Description via MCP', $record['description']);
    }

    public function testSysFileItselfRemainsReadOnly(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'sys_file',
            'action' => 'update',
            'uid' => 1,
            'data' => ['name' => 'hacked.jpg'],
        ]);
        $this->assertTrue($result->isError, 'sys_file must stay read-only — only metadata is editable');
    }

    /**
     * Translation works through the standard create-with-language path because
     * sys_file_metadata is now a normal language-aware table — no special
     * embedded-translation handling needed.
     */
    public function testTranslateSysFileMetadata(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'sys_file_metadata',
            'action' => 'translate',
            'uid' => 1,
            'data' => [
                'sys_language_uid' => 1,
                'title' => 'DE Titel',
                'alternative' => 'DE Alt',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Default-language record is unchanged
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute(['table' => 'sys_file_metadata', 'uid' => 1]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertSame('Test Image Title', $record['title']);
    }

    /**
     * Non-admin users only see sys_file_metadata for files within their mounts.
     */
    public function testNonAdminMountRestrictionAppliesToMetadata(): void
    {
        // Add metadata records for files at uids 3 and 5 too. We can then verify
        // that a /user_upload/team/ mount only exposes metadata for uid=5.
        // We assert against the `file` foreign key rather than `title` because
        // title is `exclude=true` in the TCA, so non-admin users without the
        // matching non_exclude_fields permission don't see it. The mount
        // restriction itself is unrelated to that — it filters on the row,
        // not the column.
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata');
        $conn->insert('sys_file_metadata', [
            'pid' => 0,
            'tstamp' => 1700000000,
            'crdate' => 1700000000,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'l10n_diffsource' => '',
            'title' => 'Person',
            'alternative' => '',
            'description' => 'desc-3',
            'file' => 3,
        ]);
        $conn->insert('sys_file_metadata', [
            'pid' => 0,
            'tstamp' => 1700000000,
            'crdate' => 1700000000,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'l10n_diffsource' => '',
            'title' => 'Team',
            'alternative' => '',
            'description' => 'desc-5',
            'file' => 5,
        ]);

        $userUid = $this->createNonAdminUserWithMounts('2'); // /user_upload/team/
        $this->authenticateAsNonAdmin($userUid, ['sys_file', 'sys_file_metadata']);

        $tool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $tool->execute(['table' => 'sys_file_metadata']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = $result->content[0]->text;
        $records = json_decode($payload, true)['records'] ?? [];
        $fileUids = array_column($records, 'file');
        $this->assertContains(5, $fileUids,
            'Metadata for the file in the mount must be visible. Got: ' . $payload);
        $this->assertNotContains(1, $fileUids,
            'Metadata of files outside the mount must be hidden');
        $this->assertNotContains(3, $fileUids,
            'Metadata of files outside the mount must be hidden');
    }

    public function testNonAdminWithoutMountsSeesNoMetadata(): void
    {
        $userUid = $this->createNonAdminUserWithMounts('');
        $this->authenticateAsNonAdmin($userUid, ['sys_file', 'sys_file_metadata']);

        $tool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $tool->execute(['table' => 'sys_file_metadata']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertSame(0, $data['total']);
    }

    /**
     * Orphaned metadata (file FK points to a non-existent sys_file uid) is
     * filtered out — admin too. The IN-subquery on sys_file gives this for free.
     */
    public function testOrphanMetadataIsFiltered(): void
    {
        $conn = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata');
        $conn->insert('sys_file_metadata', [
            'pid' => 0,
            'tstamp' => 1700000000,
            'crdate' => 1700000000,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'l10n_diffsource' => '',
            'title' => 'Orphan',
            'alternative' => '',
            'description' => '',
            'file' => 999, // non-existent
        ]);

        $tool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $tool->execute(['table' => 'sys_file_metadata']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $titles = array_column(
            json_decode($result->content[0]->text, true)['records'],
            'title'
        );
        $this->assertNotContains('Orphan', $titles);
    }

    private function createNonAdminUserWithMounts(string $fileMountUids): int
    {
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

    private function authenticateAsNonAdmin(int $uid, array $tables): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($uid);
        $user->groupData['tables_select'] = implode(',', $tables);
        $user->groupData['tables_modify'] = implode(',', $tables);
        $user->groupData['filemounts'] = (string)($user->user['file_mountpoints'] ?? '');
        $user->user['admin'] = 0;
        $GLOBALS['BE_USER'] = $user;

        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();

        return $user;
    }
}
