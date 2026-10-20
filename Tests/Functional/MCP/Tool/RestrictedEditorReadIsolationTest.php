<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\MCP\Tool\ToolInterface;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Tests\Functional\Traits\RestrictedEditorTrait;
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

        $this->assertStringNotContainsString(
            'HTML block elsewhere',
            json_encode($result->jsonSerialize()),
            'Search must not reveal content from pages outside the DB mount'
        );
    }

    private function getTool(string $name): ToolInterface
    {
        $tool = $this->get(ToolRegistry::class)->getTool($name);
        $this->assertNotNull($tool, 'Tool ' . $name . ' must be registered');
        return $tool;
    }
}
