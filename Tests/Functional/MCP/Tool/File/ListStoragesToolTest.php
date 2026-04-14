<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\File\ListStoragesTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ListStoragesToolTest extends FunctionalTestCase
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

        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file_storage.csv');

        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;

        // Create storage base paths so TYPO3 considers them online
        @mkdir($this->instancePath . '/fileadmin', 0777, true);
        @mkdir($this->instancePath . '/fileadmin2', 0777, true);
    }

    public function testListsDefaultStorage(): void
    {
        $tool = new ListStoragesTool();

        $result = $tool->execute([]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        $this->assertStringContainsString('FILE STORAGES', $content);
        $this->assertStringContainsString('fileadmin', $content);
        $this->assertStringContainsString('Storage 1:', $content);
        $this->assertStringContainsString('default', $content);
    }

    public function testListsMultipleStorages(): void
    {
        $tool = new ListStoragesTool();

        $result = $tool->execute([]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        $this->assertStringContainsString('Storage 1:', $content);
        $this->assertStringContainsString('Storage 2:', $content);
        $this->assertStringContainsString('Second Storage', $content);
    }

    public function testShowsStorageFlags(): void
    {
        $tool = new ListStoragesTool();

        $result = $tool->execute([]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // Storage 1 is public, writable, default
        $this->assertStringContainsString('public', $content);
        $this->assertStringContainsString('writable', $content);
        $this->assertStringContainsString('default', $content);
    }

    public function testShowsRootFolderPath(): void
    {
        $tool = new ListStoragesTool();

        $result = $tool->execute([]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        $this->assertStringContainsString('Root: 1:/', $content);
    }

    public function testShowsTotalCount(): void
    {
        $tool = new ListStoragesTool();

        $result = $tool->execute([]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        $this->assertMatchesRegularExpression('/Total: \d+ storage\(s\)/', $content);
    }
}
