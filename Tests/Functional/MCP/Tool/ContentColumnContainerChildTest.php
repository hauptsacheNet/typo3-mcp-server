<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use B13\Container\Tca\ContainerConfiguration;
use B13\Container\Tca\Registry;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Children of a b13/container element live in the container's grid columns,
 * which are not part of the page's backend layout. The page layout's column
 * limits must neither refuse them nor report their colPos as missing.
 */
class ContentColumnContainerChildTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/mcp_server',
        'container',
    ];

    private const CONTAINER_CTYPE = 'mcptest_container';
    private const GRID_COLPOS = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');

        GeneralUtility::getContainer()->get(Registry::class)->configureContainer(new ContainerConfiguration(
            self::CONTAINER_CTYPE,
            'MCP Test Container',
            'Container for MCP tests',
            [[['name' => 'Grid', 'colPos' => self::GRID_COLPOS]]]
        ));

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $connection->update('pages', ['TSconfig' => <<<'TSCONFIG'
mod.web_layout.BackendLayouts.restricted.config.backend_layout {
    colCount = 1
    rowCount = 1
    rows.1.columns.1 {
        name = Main
        colPos = 0
        disallowedContentTypes = image
    }
}
TSCONFIG], ['uid' => 1]);
        $connection->update('pages', ['backend_layout' => 'pagets__restricted'], ['uid' => 7]);

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
    }

    public function testChildInAContainerColumnIsNotCheckedAgainstThePageLayout(): void
    {
        $container = $this->write(['pid' => 7, 'CType' => self::CONTAINER_CTYPE, 'header' => 'Container', 'colPos' => 0]);
        $this->assertFalse($container->isError, json_encode($container->jsonSerialize()));
        $containerUid = (int)json_decode($container->content[0]->text, true)['uid'];

        $child = $this->write([
            'pid' => 7,
            'CType' => 'image',
            'header' => 'Image in the container',
            'colPos' => self::GRID_COLPOS,
            'tx_container_parent' => $containerUid,
        ]);

        $this->assertFalse($child->isError, json_encode($child->jsonSerialize()));
    }

    public function testElementOutsideAContainerIsStillChecked(): void
    {
        $result = $this->write(['pid' => 7, 'CType' => 'image', 'header' => 'Image', 'colPos' => 0]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('colPos 0 "Main" does not accept the type "image"', $result->content[0]->text);
    }

    private function write(array $data): CallToolResult
    {
        return GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => $data,
        ]);
    }
}
