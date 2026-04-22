<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class UploadFileToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private const TINY_PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure target fileadmin path exists (storage basePath points here)
        $basePath = Environment::getPublicPath() . '/typo3temp/var/transient';
        if (!is_dir($basePath)) {
            GeneralUtility::mkdir_deep($basePath);
        }

        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file_storage.csv');
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        $basePath = Environment::getPublicPath() . '/typo3temp/var/transient';
        if (is_dir($basePath . '/user_upload')) {
            $this->rrmdir($basePath . '/user_upload');
        }
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $dir . '/' . $item;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }

    public function testBase64UploadCreatesFileRecord(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'filename' => 'tiny.png',
            'data' => self::TINY_PNG_BASE64,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = json_decode($result->content[0]->text, true);
        $this->assertArrayHasKey('uid', $payload);
        $this->assertGreaterThan(0, $payload['uid']);
        $this->assertSame('tiny.png', $payload['name']);
        $this->assertSame('image/png', $payload['mime_type']);
        $this->assertStringContainsString('/user_upload/mcp/', $payload['identifier']);

        $diskPath = Environment::getPublicPath() . '/typo3temp/var/transient' . $payload['identifier'];
        $this->assertFileExists($diskPath, 'Uploaded file must exist on disk');

        // sys_file row was created
        $this->assertSysFileRowExists($payload['uid'], 'tiny.png');
    }

    public function testDefaultFolderIsAutoCreated(): void
    {
        $folderPath = Environment::getPublicPath() . '/typo3temp/var/transient/user_upload/mcp';
        $this->assertDirectoryDoesNotExist($folderPath);

        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'filename' => 'auto-folder.png',
            'data' => self::TINY_PNG_BASE64,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertDirectoryExists($folderPath);
    }

    public function testExplicitFolderIsAutoCreatedAndUsed(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'filename' => 'nested.png',
            'data' => self::TINY_PNG_BASE64,
            'folder' => '/user_upload/deep/nested/path/',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = json_decode($result->content[0]->text, true);
        $this->assertStringContainsString('/user_upload/deep/nested/path/', $payload['identifier']);

        $diskPath = Environment::getPublicPath() . '/typo3temp/var/transient/user_upload/deep/nested/path/nested.png';
        $this->assertFileExists($diskPath);
    }

    public function testCombinedIdentifierFolderIsAccepted(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'filename' => 'combined.png',
            'data' => self::TINY_PNG_BASE64,
            'folder' => '1:/user_upload/combined/',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testMetadataIsStoredOnUpload(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'filename' => 'with-meta.png',
            'data' => self::TINY_PNG_BASE64,
            'metadata' => [
                'alternative' => 'Alt text from upload',
                'title' => 'Upload title',
                'description' => 'Long caption',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $fileUid = json_decode($result->content[0]->text, true)['uid'];

        $row = $this->fetchMetadataRow($fileUid);
        $this->assertNotNull($row, 'sys_file_metadata row must exist');
        $this->assertSame('Alt text from upload', $row['alternative']);
        $this->assertSame('Upload title', $row['title']);
        $this->assertSame('Long caption', $row['description']);
    }

    public function testMissingDataAndUrlIsRejected(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'filename' => 'nothing.png',
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('data', $result->content[0]->text);
    }

    public function testBothDataAndUrlIsRejected(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'filename' => 'both.png',
            'data' => self::TINY_PNG_BASE64,
            'url' => 'https://example.com/file.png',
        ]);
        $this->assertTrue($result->isError);
    }

    public function testInvalidBase64IsRejected(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'filename' => 'bad.png',
            'data' => '!!not-base64@@',
        ]);
        $this->assertTrue($result->isError);
    }

    public function testNonHttpUrlIsRejected(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'filename' => 'ftp.png',
            'url' => 'ftp://example.com/file.png',
        ]);
        $this->assertTrue($result->isError);
    }

    public function testStorageMismatchInCombinedIdentifierIsRejected(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'filename' => 'wrong-storage.png',
            'data' => self::TINY_PNG_BASE64,
            'folder' => '999:/user_upload/mcp/',
        ]);
        $this->assertTrue($result->isError);
    }

    public function testDataUriPrefixIsStripped(): void
    {
        $tool = GeneralUtility::makeInstance(UploadFileTool::class);
        $result = $tool->execute([
            'filename' => 'datauri.png',
            'data' => 'data:image/png;base64,' . self::TINY_PNG_BASE64,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testEndToEndUploadAndAttachToContent(): void
    {
        // 1. Upload via UploadFileTool
        $uploadTool = GeneralUtility::makeInstance(UploadFileTool::class);
        $uploadResult = $uploadTool->execute([
            'filename' => 'hero.png',
            'data' => self::TINY_PNG_BASE64,
            'metadata' => ['alternative' => 'Hero Alt'],
        ]);
        $this->assertFalse($uploadResult->isError, json_encode($uploadResult->jsonSerialize()));
        $fileUid = json_decode($uploadResult->content[0]->text, true)['uid'];

        // 2. Create tt_content with image reference to the uploaded file
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $createResult = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Hero block with uploaded image',
                'CType' => 'textmedia',
                'image' => [
                    ['uid_local' => $fileUid, 'alternative' => 'Hero reference alt'],
                ],
            ],
        ]);
        $this->assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));
        $contentUid = json_decode($createResult->content[0]->text, true)['uid'];

        // 3. Read content back — image reference must resolve to the uploaded file
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $readResult = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        $this->assertFalse($readResult->isError, json_encode($readResult->jsonSerialize()));
        $record = json_decode($readResult->content[0]->text, true)['records'][0];

        $this->assertArrayHasKey('image', $record);
        $this->assertCount(1, $record['image']);
        $this->assertSame($fileUid, (int) $record['image'][0]['uid_local']);
        $this->assertSame('Hero reference alt', $record['image'][0]['alternative']);
        $this->assertSame('hero.png', $record['image'][0]['file_name']);
    }

    public function testMetadataCanBeUpdatedViaWriteTable(): void
    {
        // Upload a file first
        $uploadTool = GeneralUtility::makeInstance(UploadFileTool::class);
        $uploadResult = $uploadTool->execute([
            'filename' => 'meta-edit.png',
            'data' => self::TINY_PNG_BASE64,
            'metadata' => ['alternative' => 'initial alt'],
        ]);
        $this->assertFalse($uploadResult->isError, json_encode($uploadResult->jsonSerialize()));
        $fileUid = json_decode($uploadResult->content[0]->text, true)['uid'];

        $metaUid = (int) $this->fetchMetadataRow($fileUid)['uid'];

        // Update via WriteTable on sys_file_metadata (proves the unlock)
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'sys_file_metadata',
            'action' => 'update',
            'uid' => $metaUid,
            'data' => ['alternative' => 'updated alt via WriteTable'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $readResult = $readTool->execute([
            'table' => 'sys_file_metadata',
            'uid' => $metaUid,
        ]);
        $this->assertFalse($readResult->isError, json_encode($readResult->jsonSerialize()));
        $record = json_decode($readResult->content[0]->text, true)['records'][0];
        $this->assertSame('updated alt via WriteTable', $record['alternative']);
    }

    private function assertSysFileRowExists(int $uid, string $expectedName): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file');
        $row = $connection->createQueryBuilder()
            ->select('name', 'storage')
            ->from('sys_file')
            ->where('uid = ' . $uid)
            ->executeQuery()
            ->fetchAssociative();
        $this->assertIsArray($row, 'sys_file row must exist');
        $this->assertSame($expectedName, $row['name']);
        $this->assertSame(1, (int) $row['storage']);
    }

    private function fetchMetadataRow(int $fileUid): ?array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata');
        $qb = $connection->createQueryBuilder();
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from('sys_file_metadata')
            ->where($qb->expr()->eq('file', $qb->createNamedParameter($fileUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }
}
