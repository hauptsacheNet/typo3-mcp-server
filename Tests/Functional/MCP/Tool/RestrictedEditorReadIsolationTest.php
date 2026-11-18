<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\MCP\Tool\ToolInterface;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Tests\Functional\Traits\RestrictedEditorTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Read access of a non-admin editor follows the same page rules as the TYPO3
 * backend: records, pages and search results from outside the editor's DB
 * mounts are not visible, while everything inside the mount stays readable.
 */
class RestrictedEditorReadIsolationTest extends FunctionalTestCase
{
    use RestrictedEditorTrait;

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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importRestrictedEditorFixture();
        $this->actAsBackendUser(self::RESTRICTED_EDITOR_UID);
    }

    public function testReadTableWithoutFilterOnlyReturnsMountedRecords(): void
    {
        $result = (new ReadTableTool())->execute(['table' => 'tt_content', 'limit' => 100]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->decodeToolResult($result);
        $uids = array_column($data['records'], 'uid');
        sort($uids);
        $this->assertSame([self::HTML_ELEMENT_UID, self::TEXT_ELEMENT_UID], $uids);
        $this->assertSame(2, $data['total'], 'The total must not count records outside the mount');
    }

    public function testReadPagesOnlyReturnsMountedPages(): void
    {
        $result = (new ReadTableTool())->execute(['table' => 'pages', 'limit' => 100]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([self::MOUNTED_PAGE_UID], array_column($this->decodeToolResult($result)['records'], 'uid'));
    }

    public function testReadTableByPidOutsideMountReturnsNothing(): void
    {
        $result = (new ReadTableTool())->execute([
            'table' => 'tt_content',
            'pid' => self::UNMOUNTED_PAGE_UID,
        ]);

        if (!$result->isError) {
            $this->assertSame([], array_column($this->decodeToolResult($result)['records'] ?? [], 'uid'));
        }
    }

    public function testReadTableByUidOutsideMountReturnsNothing(): void
    {
        $result = (new ReadTableTool())->execute([
            'table' => 'tt_content',
            'uid' => self::UNMOUNTED_HTML_ELEMENT_UID,
        ]);

        if (!$result->isError) {
            $this->assertSame([], $this->decodeToolResult($result)['records'] ?? []);
        }
    }

    public function testGetPageInsideMountWorks(): void
    {
        $result = $this->getTool('GetPage')->execute(['uid' => self::MOUNTED_PAGE_UID]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('HTML block', $result->content[0]->text);
    }

    public function testGetPageOutsideMountIsRejected(): void
    {
        $result = $this->getTool('GetPage')->execute(['uid' => self::UNMOUNTED_PAGE_UID]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testPageTreeOnlyShowsMountedPages(): void
    {
        $result = $this->getTool('GetPageTree')->execute(['startPage' => 0, 'depth' => 3]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $tree = $result->content[0]->text;
        $this->assertStringContainsString('Restricted Page', $tree);
        $this->assertStringNotContainsString('Other Page', $tree);
        $this->assertStringNotContainsString('Hidden Page', $tree);
    }

    /**
     * A mount root that was moved in the workspace is represented by its move
     * pointer, which has its own uid; the tree must still start at it.
     */
    public function testPageTreeShowsAMountRootMovedInTheWorkspace(): void
    {
        $this->actAsBackendUser(1);
        $move = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => self::MOUNTED_PAGE_UID,
            'data' => ['pid' => 2],
        ]);
        $this->assertFalse($move->isError, json_encode($move->jsonSerialize()));
        $this->assertNotNull(
            $this->fetchWorkspaceVersion('pages', self::MOUNTED_PAGE_UID, self::EDITORIAL_WORKSPACE_UID),
            'Precondition: the move is staged in the editorial workspace'
        );

        $this->actAsBackendUser(self::RESTRICTED_EDITOR_UID);
        $result = $this->getTool('GetPageTree')->execute(['startPage' => 0, 'depth' => 3]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $tree = $result->content[0]->text;
        $this->assertStringContainsString('[' . self::MOUNTED_PAGE_UID . '] Restricted Page', $tree);
        $this->assertStringNotContainsString('Other Page', $tree);
    }

    public function testPageTreeStartingOutsideMountIsRejected(): void
    {
        $result = $this->getTool('GetPageTree')->execute(['startPage' => 1, 'depth' => 2]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testSearchFindsContentInsideMount(): void
    {
        $result = (new SearchTool())->execute(['terms' => ['Original markup']]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('HTML block', $result->content[0]->text);
    }

    public function testSearchDoesNotFindContentOutsideMount(): void
    {
        $result = (new SearchTool())->execute(['terms' => ['Not mounted']]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringNotContainsString(
            'HTML block elsewhere',
            json_encode($result->jsonSerialize()),
            'Search must not reveal content from pages outside the DB mount'
        );
    }

    public function testPageTreeStartingInsideMountWorks(): void
    {
        $result = $this->getTool('GetPageTree')->execute(['startPage' => self::MOUNTED_PAGE_UID, 'depth' => 2]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testSearchDoesNotFindPagesOutsideMount(): void
    {
        $result = (new SearchTool())->execute(['terms' => ['Page'], 'table' => 'pages']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringContainsString('Restricted Page', $text);
        $this->assertStringNotContainsString('Other Page', $text);
        $this->assertStringNotContainsString('Hidden Page', $text);
    }

    public function testTableSchemaForPageInsideMountWorks(): void
    {
        $result = (new GetTableSchemaTool())->execute(['table' => 'tt_content', 'pid' => self::MOUNTED_PAGE_UID]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Type: html', $result->content[0]->text);
    }

    public function testTableSchemaForPageOutsideMountIsRejected(): void
    {
        $result = (new GetTableSchemaTool())->execute(['table' => 'tt_content', 'pid' => self::UNMOUNTED_PAGE_UID]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testTableSchemaWithoutPidIsResolvedForTheMount(): void
    {
        $withoutPid = (new GetTableSchemaTool())->execute(['table' => 'tt_content']);
        $mounted = (new GetTableSchemaTool())->execute(['table' => 'tt_content', 'pid' => self::MOUNTED_PAGE_UID]);

        $this->assertFalse($withoutPid->isError, json_encode($withoutPid->jsonSerialize()));
        $this->assertFalse($mounted->isError, json_encode($mounted->jsonSerialize()));
        $this->assertSame($mounted->content[0]->text, $withoutPid->content[0]->text);
    }

    #[DataProvider('unreadableMountProvider')]
    public function testTableSchemaWithoutReadableMountIsRejected(string $mountPoints): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/restricted_editor_subpages.csv');
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_groups')
            ->update('be_groups', ['db_mountpoints' => $mountPoints], ['uid' => 10]);
        $this->actAsBackendUser(self::RESTRICTED_EDITOR_UID);

        $result = (new GetTableSchemaTool())->execute(['table' => 'tt_content']);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('no access to any page', $result->content[0]->text);
    }

    public static function unreadableMountProvider(): array
    {
        return [
            'no mount at all' => [''],
            'mount without show permission' => ['22'],
        ];
    }

    #[DataProvider('searchStringProvider')]
    public function testSearchReplaceOutsideMountRevealsNothing(string $search): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::UNMOUNTED_HTML_ELEMENT_UID,
            'data' => ['bodytext' => [['search' => $search, 'replace' => 'x']]],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString(
            'Record ' . self::UNMOUNTED_HTML_ELEMENT_UID . ' not found',
            $result->content[0]->text
        );
        $this->assertSame('<p>Not mounted</p>', $this->fetchRawRecord('tt_content', self::UNMOUNTED_HTML_ELEMENT_UID)['bodytext']);
    }

    public static function searchStringProvider(): array
    {
        return [
            'present once' => ['Not mounted'],
            'present twice' => ['o'],
            'absent' => ['absent text'],
        ];
    }

    /**
     * The record lives inside the mount, but on a subpage without show
     * permission. It must be refused before any field is checked, so neither
     * the field checks (which depend on the record's type and page) nor the
     * search string can reveal anything about it.
     */
    #[DataProvider('unreadableRecordFieldProvider')]
    public function testSearchReplaceOnPageWithoutShowPermissionIsRefused(string $field, string $search): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/restricted_editor_subpages.csv');

        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 203,
            'data' => [$field => [['search' => $search, 'replace' => 'x']]],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("Record 203 not found in table 'tt_content'", $result->content[0]->text);
        $record = $this->fetchRawRecord('tt_content', 203);
        $this->assertSame('<p>Secret markup</p>', $record['bodytext']);
        $this->assertSame('default', $record['frame_class']);
        $this->assertNull($this->fetchWorkspaceVersion('tt_content', 203, self::EDITORIAL_WORKSPACE_UID));
    }

    public static function unreadableRecordFieldProvider(): array
    {
        return [
            'accessible text field' => ['bodytext', 'Secret markup'],
            'field excluded for the editor' => ['frame_class', 'default'],
            'non-string field' => ['colPos', '0'],
            'unknown field' => ['no_such_field', 'x'],
        ];
    }

    public function testSearchReplaceInsideMountWorks(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
            'data' => ['bodytext' => [['search' => 'Original', 'replace' => 'Changed']]],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(
            '<p>Changed markup</p>',
            $this->fetchWorkspaceVersion('tt_content', self::HTML_ELEMENT_UID, self::EDITORIAL_WORKSPACE_UID)['bodytext'] ?? null
        );
    }

    public function testGetPageListsOnlySubpagesWithShowPermission(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/restricted_editor_subpages.csv');

        $result = $this->getTool('GetPage')->execute(['uid' => self::MOUNTED_PAGE_UID]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringContainsString('Shared Subpage', $text);
        $this->assertStringNotContainsString('Secret Subpage', $text);
    }

    public function testGetPageOfSubpageWithoutShowPermissionIsRejected(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/restricted_editor_subpages.csv');

        $result = $this->getTool('GetPage')->execute(['uid' => 22]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testPageTreeLeavesOutSubpagesWithoutShowPermission(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/restricted_editor_subpages.csv');

        $result = $this->getTool('GetPageTree')->execute(['startPage' => self::MOUNTED_PAGE_UID, 'depth' => 2]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $tree = $result->content[0]->text;
        $this->assertStringContainsString('Shared Subpage', $tree);
        $this->assertStringNotContainsString('Secret Subpage', $tree);
    }

    public function testReadTableLeavesOutSubpagesWithoutShowPermission(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/restricted_editor_subpages.csv');

        $pages = (new ReadTableTool())->execute(['table' => 'pages', 'pid' => self::MOUNTED_PAGE_UID]);
        $content = (new ReadTableTool())->execute(['table' => 'tt_content', 'pid' => 22]);

        $this->assertFalse($pages->isError, json_encode($pages->jsonSerialize()));
        $this->assertSame([23], array_column($this->decodeToolResult($pages)['records'], 'uid'));
        $this->assertFalse($content->isError, json_encode($content->jsonSerialize()));
        $this->assertSame([], $this->decodeToolResult($content)['records']);
    }

    public function testSearchDoesNotFindContentOnSubpagesWithoutShowPermission(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/restricted_editor_subpages.csv');

        $result = (new SearchTool())->execute(['terms' => ['Secret markup']]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringNotContainsString('Secret block', json_encode($result->jsonSerialize()));
    }

    private function getTool(string $name): ToolInterface
    {
        $tool = $this->get(ToolRegistry::class)->getTool($name);
        $this->assertNotNull($tool, 'Tool ' . $name . ' must be registered');
        return $tool;
    }
}
