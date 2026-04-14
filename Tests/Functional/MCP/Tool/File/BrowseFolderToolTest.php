<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\File\BrowseFolderTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class BrowseFolderToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    private string $storageBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file_storage.csv');

        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;

        $this->createTestFolderStructure();
    }

    /**
     * Create test folders and files in the default storage
     */
    private function createTestFolderStructure(): void
    {
        $this->storageBasePath = $this->instancePath . '/fileadmin';
        @mkdir($this->storageBasePath, 0777, true);

        // Create folder structure
        @mkdir($this->storageBasePath . '/images', 0777, true);
        @mkdir($this->storageBasePath . '/documents', 0777, true);

        // Create test files using GD (1x1px PNG)
        $image = imagecreatetruecolor(1, 1);
        imagepng($image, $this->storageBasePath . '/images/logo.png');
        imagedestroy($image);

        $image = imagecreatetruecolor(1, 1);
        $red = imagecolorallocate($image, 255, 0, 0);
        imagesetpixel($image, 0, 0, $red);
        imagepng($image, $this->storageBasePath . '/images/banner.png');
        imagedestroy($image);

        // Create a simple text file
        file_put_contents($this->storageBasePath . '/documents/readme.txt', 'Test document');
    }

    public function testBrowseRootFolder(): void
    {
        $tool = new BrowseFolderTool();

        $result = $tool->execute(['folder' => '1:/']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        $this->assertStringContainsString('📁 Storage: fileadmin', $content);
        $this->assertStringContainsString('📂 /', $content);
        $this->assertStringContainsString('images', $content);
        $this->assertStringContainsString('documents', $content);
    }

    public function testBrowseSubfolder(): void
    {
        $tool = new BrowseFolderTool();

        $result = $tool->execute(['folder' => '1:/images/']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        $this->assertStringContainsString('📂 /images/', $content);
        $this->assertStringContainsString('logo.png', $content);
        $this->assertStringContainsString('banner.png', $content);
    }

    public function testShowsFileMetadata(): void
    {
        $tool = new BrowseFolderTool();

        $result = $tool->execute(['folder' => '1:/documents/']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        $this->assertStringContainsString('readme.txt', $content);
        // File should show size info
        $this->assertMatchesRegularExpression('/\d+ B/', $content);
    }

    public function testShowsSubfolderFileCount(): void
    {
        $tool = new BrowseFolderTool();

        $result = $tool->execute(['folder' => '1:/']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // images folder has 2 files
        $this->assertStringContainsString('images (2 files)', $content);
        // documents folder has 1 file
        $this->assertStringContainsString('documents (1 files)', $content);
    }

    public function testShowsCombinedIdentifierForSubfolders(): void
    {
        $tool = new BrowseFolderTool();

        $result = $tool->execute(['folder' => '1:/']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        $this->assertStringContainsString('1:/images/', $content);
        $this->assertStringContainsString('1:/documents/', $content);
    }

    public function testEmptyFolder(): void
    {
        @mkdir($this->storageBasePath . '/empty', 0777, true);

        $tool = new BrowseFolderTool();

        $result = $tool->execute(['folder' => '1:/empty/']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        $this->assertStringContainsString('(empty folder)', $content);
    }

    public function testRecursiveListing(): void
    {
        $tool = new BrowseFolderTool();

        $result = $tool->execute([
            'folder' => '1:/',
            'recursive' => true,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // Should show subfolder contents too
        $this->assertStringContainsString('images', $content);
        $this->assertStringContainsString('logo.png', $content);
        $this->assertStringContainsString('banner.png', $content);
        $this->assertStringContainsString('documents', $content);
        $this->assertStringContainsString('readme.txt', $content);
    }
}
