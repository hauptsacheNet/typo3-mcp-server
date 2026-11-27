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
 * b13/container places children with colPos and tx_container_parent, and its
 * drag and drop in the page module sets both. Like colPos, tx_container_parent
 * stays writable when TCEFORM hides it in the edit form, and mod.SHARED.colPos_list
 * (a limit on the page's backend layout columns) does not apply to the grid
 * columns of a container.
 */
class ContainerChildColPosTSconfigTest extends FunctionalTestCase
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

        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => implode("\n", [
                'TCEFORM.tt_content.colPos.disabled = 1',
                'TCEFORM.tt_content.tx_container_parent.disabled = 1',
                'mod.SHARED.colPos_list = 0',
                '',
            ])],
            ['uid' => 1]
        );

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
    }

    public function testChildIsPlacedInAContainerWhenTheFormHidesBothFields(): void
    {
        $containerUid = $this->createContainer();

        $child = $this->write([
            'pid' => 1,
            'CType' => 'text',
            'header' => 'Child',
            'colPos' => self::GRID_COLPOS,
            'tx_container_parent' => $containerUid,
        ]);

        $this->assertFalse($child->isError, json_encode($child->jsonSerialize()));
    }

    private function createContainer(): int
    {
        $result = $this->write(['pid' => 1, 'CType' => self::CONTAINER_CTYPE, 'header' => 'Container', 'colPos' => 0]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return (int)json_decode($result->content[0]->text, true)['uid'];
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
