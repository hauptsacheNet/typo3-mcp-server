<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\Traits\RestrictedEditorTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A non-admin editor that may only maintain HTML content elements on a single
 * page. Everything the group grants must work through the MCP tools,
 * everything else must be refused - and refused visibly (isError), so the
 * LLM does not report a change that never happened.
 */
class RestrictedEditorPermissionTest extends FunctionalTestCase
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

    public function testFixtureLeavesFrameClassAndLayoutAsExcludeFields(): void
    {
        // Precondition for the frame_class/layout tests below: both columns are
        // exclude fields in core, so withholding them in non_exclude_fields
        // actually restricts the editor.
        $this->assertTrue((bool)($GLOBALS['TCA']['tt_content']['columns']['frame_class']['exclude'] ?? false));
        $this->assertTrue((bool)($GLOBALS['TCA']['tt_content']['columns']['layout']['exclude'] ?? false));
        $this->assertFalse($GLOBALS['BE_USER']->isAdmin());
        $this->assertTrue($GLOBALS['BE_USER']->check('tables_modify', 'tt_content'));
        $this->assertFalse($GLOBALS['BE_USER']->check('tables_modify', 'pages'));
    }

    public function testReadHtmlElementOnMountedPage(): void
    {
        $result = (new ReadTableTool())->execute([
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->decodeToolResult($result);
        $this->assertCount(1, $data['records']);
        $this->assertSame('html', $data['records'][0]['CType']);
        $this->assertSame('<p>Original markup</p>', $data['records'][0]['bodytext']);
    }

    public function testUpdateBodytextOfHtmlElement(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
            'data' => ['bodytext' => '<p>Changed markup</p>'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $version = $this->fetchWorkspaceVersion('tt_content', self::HTML_ELEMENT_UID, self::EDITORIAL_WORKSPACE_UID);
        $this->assertNotNull($version, 'The update must create a version in the editorial workspace');
        $this->assertSame('<p>Changed markup</p>', $version['bodytext']);
        $this->assertSame('<p>Original markup</p>', $this->fetchRawRecord('tt_content', self::HTML_ELEMENT_UID)['bodytext']);
    }

    public function testCreateHtmlElementOnMountedPage(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => self::MOUNTED_PAGE_UID,
                'CType' => 'html',
                'colPos' => 0,
                'header' => 'New HTML block',
                'bodytext' => '<div class="new">New markup</div>',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $created = $this->fetchRawRecords('tt_content', ['header' => 'New HTML block']);
        $this->assertCount(1, $created);
        $this->assertSame(self::EDITORIAL_WORKSPACE_UID, (int)$created[0]['t3ver_wsid']);
        $this->assertSame(self::MOUNTED_PAGE_UID, (int)$created[0]['pid']);
        $this->assertSame('html', $created[0]['CType']);
        $this->assertSame('<div class="new">New markup</div>', $created[0]['bodytext']);
    }

    public function testCreateOtherContentTypeIsRejected(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => self::MOUNTED_PAGE_UID,
                'CType' => 'text',
                'header' => 'Forbidden text element',
                'bodytext' => 'Not allowed',
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([], $this->fetchRawRecords('tt_content', ['header' => 'Forbidden text element']));
    }

    public function testUpdateOfOtherContentTypeIsRejected(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::TEXT_ELEMENT_UID,
            'data' => ['bodytext' => 'Changed text'],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertNull($this->fetchWorkspaceVersion('tt_content', self::TEXT_ELEMENT_UID, self::EDITORIAL_WORKSPACE_UID));
        $this->assertSame('Some text', $this->fetchRawRecord('tt_content', self::TEXT_ELEMENT_UID)['bodytext']);
    }

    public function testChangingHtmlElementToOtherContentTypeIsRejected(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
            'data' => ['CType' => 'text'],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $version = $this->fetchWorkspaceVersion('tt_content', self::HTML_ELEMENT_UID, self::EDITORIAL_WORKSPACE_UID);
        $this->assertTrue($version === null || $version['CType'] === 'html');
    }

    public function testUpdateOnPageOutsideMountIsRejected(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::UNMOUNTED_HTML_ELEMENT_UID,
            'data' => ['bodytext' => '<p>Changed elsewhere</p>'],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertNull($this->fetchWorkspaceVersion('tt_content', self::UNMOUNTED_HTML_ELEMENT_UID, self::EDITORIAL_WORKSPACE_UID));
        $this->assertSame('<p>Not mounted</p>', $this->fetchRawRecord('tt_content', self::UNMOUNTED_HTML_ELEMENT_UID)['bodytext']);
    }

    public function testCreateOnPageOutsideMountIsRejected(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => self::UNMOUNTED_PAGE_UID,
                'CType' => 'html',
                'header' => 'HTML outside mount',
                'bodytext' => '<p>Nope</p>',
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([], $this->fetchRawRecords('tt_content', ['header' => 'HTML outside mount']));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function nonGrantedFieldProvider(): array
    {
        return [
            'frame_class' => ['frame_class', 'ruler-before'],
            'layout' => ['layout', 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonGrantedFieldProvider')]
    public function testUpdatingNonGrantedFieldIsRejected(string $field, mixed $value): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
            'data' => [$field => $value],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $version = $this->fetchWorkspaceVersion('tt_content', self::HTML_ELEMENT_UID, self::EDITORIAL_WORKSPACE_UID);
        $live = $this->fetchRawRecord('tt_content', self::HTML_ELEMENT_UID);
        $this->assertEquals($live[$field], $version[$field] ?? $live[$field], $field . ' must keep its value');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonGrantedFieldProvider')]
    public function testCreatingWithNonGrantedFieldIsRejected(string $field, mixed $value): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => self::MOUNTED_PAGE_UID,
                'CType' => 'html',
                'header' => 'HTML with ' . $field,
                'bodytext' => '<p>x</p>',
                $field => $value,
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        foreach ($this->fetchRawRecords('tt_content', ['header' => 'HTML with ' . $field]) as $row) {
            $this->assertNotEquals($value, $row[$field], $field . ' must not be written');
        }
    }

    public function testNonGrantedFieldsAreHiddenFromRead(): void
    {
        $result = (new ReadTableTool())->execute([
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = $this->decodeToolResult($result)['records'][0];
        $this->assertArrayNotHasKey('frame_class', $record);
        $this->assertArrayNotHasKey('layout', $record);
    }

    public function testWritingPagesIsRejected(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => self::MOUNTED_PAGE_UID,
            'data' => ['title' => 'Renamed page'],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([], $this->fetchRawRecords('pages', ['title' => 'Renamed page']));
    }

    public function testWritingOtherTableIsRejected(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => 'sys_category',
            'data' => [
                'pid' => self::MOUNTED_PAGE_UID,
                'title' => 'Forbidden category',
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([], $this->fetchRawRecords('sys_category', ['title' => 'Forbidden category']));
    }

    public function testReadingOtherTableIsRejected(): void
    {
        $result = (new ReadTableTool())->execute([
            'table' => 'sys_category',
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testDeletingHtmlElementOnlyMarksItInWorkspace(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'delete',
            'table' => 'tt_content',
            'uid' => self::HTML_ELEMENT_UID,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $live = $this->fetchRawRecord('tt_content', self::HTML_ELEMENT_UID);
        $this->assertSame(0, (int)$live['deleted'], 'The live record must stay untouched');
        $this->assertNotNull(
            $this->fetchWorkspaceVersion('tt_content', self::HTML_ELEMENT_UID, self::EDITORIAL_WORKSPACE_UID),
            'Deleting must create a delete placeholder in the workspace'
        );
    }
}
