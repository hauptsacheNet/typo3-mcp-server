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
    // A container whose grid column has the number of a page column (1, "Sidebar").
    private const SIDE_CONTAINER_CTYPE = 'mcptest_side_container';

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
        GeneralUtility::getContainer()->get(Registry::class)->configureContainer(new ContainerConfiguration(
            self::SIDE_CONTAINER_CTYPE,
            'MCP Side Container',
            'Container whose grid column is 1',
            [[['name' => 'Side grid', 'colPos' => 1]]]
        ));

        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => implode("\n", [
                'TCEFORM.tt_content.colPos.disabled = 1',
                'TCEFORM.tt_content.tx_container_parent.disabled = 1',
                'mod.SHARED.colPos_list = 0',
                'mod.web_layout.BackendLayouts.twocol.config.backend_layout {',
                '    colCount = 2',
                '    rowCount = 1',
                '    rows.1.columns.1 {',
                '        name = Main',
                '        colPos = 0',
                '    }',
                '    rows.1.columns.2 {',
                '        name = Sidebar',
                '        colPos = 1',
                '    }',
                '}',
                '',
            ]), 'backend_layout' => 'pagets__twocol'],
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

    /**
     * b13/container offers no colPos item for a column its grid does not have.
     * The select validation then falls back to the static TCA items (0-3), so
     * without a check of its own a child could land in column 0 of a container
     * that only renders column 200.
     */
    public function testChildInAColumnTheContainerDoesNotHaveIsRefused(): void
    {
        $containerUid = $this->createContainer();

        $child = $this->write([
            'pid' => 1,
            'CType' => 'text',
            'header' => 'Child',
            'colPos' => 0,
            'tx_container_parent' => $containerUid,
        ]);

        $this->assertTrue($child->isError, json_encode($child->jsonSerialize()));
        $this->assertStringContainsString(
            "Field 'colPos' value '0' is not a column of the container it is placed in (tx_container_parent " . $containerUid . ')',
            $child->content[0]->text
        );
    }

    /**
     * An element that leaves its container keeps its colPos and becomes a page
     * element in that column, so colPos_list applies even though colPos itself
     * does not change.
     */
    public function testChildLeavingItsContainerIsCheckedAgainstThePageColumns(): void
    {
        $containerUid = $this->createContainer(self::SIDE_CONTAINER_CTYPE);
        $child = $this->write([
            'pid' => 1,
            'CType' => 'text',
            'header' => 'Child',
            'colPos' => 1,
            'tx_container_parent' => $containerUid,
        ]);
        $this->assertFalse($child->isError, json_encode($child->jsonSerialize()));
        $childUid = (int)json_decode($child->content[0]->text, true)['uid'];

        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $childUid,
            'data' => ['tx_container_parent' => 0],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('mod.SHARED.colPos_list', $result->content[0]->text);
    }

    private function createContainer(string $cType = self::CONTAINER_CTYPE): int
    {
        $result = $this->write(['pid' => 1, 'CType' => $cType, 'header' => 'Container', 'colPos' => 0]);
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
