<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaTtContentCtypeItemsRestrictionByBackendLayout;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests that content types restricted to specific backend layout columns
 * (allowedContentTypes / disallowedContentTypes, TYPO3 14 Feature #108623)
 * are still available in the schema.
 *
 * Regression test: the schema resolved CType items for a blank record, which
 * the core restricts to colPos 0 — so a type only allowed in another column
 * (e.g. a hero element in a "Stage" column) was reported as non-existent.
 */
class BackendLayoutContentTypeRestrictionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/mcp_server',
    ];

    protected GetTableSchemaTool $schemaTool;
    protected WriteTableTool $writeTool;
    protected TableAccessService $tableAccessService;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(TcaTtContentCtypeItemsRestrictionByBackendLayout::class)) {
            $this->markTestSkipped('Content type restrictions per backend layout column require TYPO3 14.1+.');
        }

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');

        // "header" may only be placed in the stage column; "bullets" is not
        // allowed in any column of the layout.
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $connection->update('pages', [
            'TSconfig' => <<<'TSCONFIG'
mod.web_layout.BackendLayouts.mcpStage {
  title = Stage layout
  config.backend_layout {
    colCount = 1
    rowCount = 2
    rows {
      1.columns.1 {
        name = Stage
        colPos = 2
        allowedContentTypes = header
      }
      2.columns.1 {
        name = Main
        colPos = 0
        disallowedContentTypes = header, bullets
      }
    }
  }
}
TSCONFIG,
            'backend_layout' => 'pagets__mcpStage',
            'backend_layout_next_level' => 'pagets__mcpStage',
        ], ['uid' => 1]);
        // Page 2 selects a database layout in the fixture — let it inherit instead.
        $connection->update('pages', ['backend_layout' => ''], ['uid' => 2]);

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->schemaTool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
    }

    public function testGetAvailableTypesIncludesTypesOfAllColumns(): void
    {
        foreach ([1, 5] as $pid) {
            $types = $this->tableAccessService->getAvailableTypes('tt_content', $pid);

            $this->assertArrayHasKey('header', $types, "CType only allowed in the stage column must be available on page $pid");
            $this->assertArrayHasKey('textmedia', $types, "CType allowed in the main column must be available on page $pid");
            $this->assertArrayNotHasKey('bullets', $types, "CType not allowed in any column must not be available on page $pid");
        }
    }

    public function testSchemaForTypeOnlyAllowedInOtherColumn(): void
    {
        $result = $this->schemaTool->execute([
            'table' => 'tt_content',
            'type' => 'header',
            'pid' => 1,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        $this->assertStringNotContainsString('ERROR', $content);
        $this->assertStringContainsString('Type: header', $content);

        // colPos options are resolved for the requested type: only the stage column.
        $colPosLine = $this->findFieldLine($content, 'colPos');
        $this->assertStringContainsString('2 (Stage)', $colPosLine);
        $this->assertDoesNotMatchRegularExpression('/\b0 \(/', $colPosLine, 'Main column must not be offered for "header": ' . $colPosLine);

        // The CType options list every type available on the page.
        $ctypeLine = $this->findFieldLine($content, 'CType');
        $this->assertMatchesRegularExpression('/\bheader \(/', $ctypeLine);
        $this->assertMatchesRegularExpression('/\btextmedia \(/', $ctypeLine);
        $this->assertDoesNotMatchRegularExpression('/\bbullets \(/', $ctypeLine);
    }

    public function testSchemaForTypeAllowedInMainColumn(): void
    {
        $result = $this->schemaTool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia',
            'pid' => 1,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $colPosLine = $this->findFieldLine($result->content[0]->text, 'colPos');
        $this->assertStringContainsString('0 (Main)', $colPosLine);
        $this->assertStringNotContainsString('2 (Stage)', $colPosLine);
    }

    public function testSchemaForTypeNotAllowedInAnyColumnReturnsError(): void
    {
        $result = $this->schemaTool->execute([
            'table' => 'tt_content',
            'type' => 'bullets',
            'pid' => 1,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("ERROR: The requested type 'bullets'", $result->content[0]->text);
    }

    public function testSchemaWithoutTypeUsesDefaultType(): void
    {
        $result = $this->schemaTool->execute([
            'table' => 'tt_content',
            'pid' => 1,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertMatchesRegularExpression('/^Type: text \(/m', $result->content[0]->text);
    }

    public function testWriteAcceptsTypeInAllowedColumn(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'header',
                'colPos' => 2,
                'header' => 'Stage header',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testWriteRejectsTypeInDisallowedColumn(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'header',
                'colPos' => 0,
                'header' => 'Should be rejected',
            ],
        ]);

        $this->assertTrue($result->isError, 'Creating a "header" in the main column must fail');
    }

    private function findFieldLine(string $content, string $fieldName): string
    {
        foreach (preg_split('/\r?\n/', $content) as $line) {
            if (preg_match('/[-─]\s' . preg_quote($fieldName, '/') . ' \(/u', $line)) {
                return $line;
            }
        }
        $this->fail("Could not locate $fieldName field line in schema output: " . $content);
    }
}
