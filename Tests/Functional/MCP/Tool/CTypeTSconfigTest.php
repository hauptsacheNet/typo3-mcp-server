<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\TableAccessService;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests that page-level TSconfig (TCEFORM.tt_content.CType.removeItems and
 * TCEMAIN.table.tt_content.disableCTypes) actually filters CType options for
 * both schema display and write validation.
 *
 * Regression test: previously TSconfig was resolved at pid=0, which has no
 * rootline, so any TSconfig defined on a page record was silently ignored.
 */
class CTypeTSconfigTest extends FunctionalTestCase
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

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');

        // Set page TSconfig on the root page so descendants inherit it.
        // This is the same mechanism real projects use; defaultPageTSconfig
        // (the GLOBALS-based v13 mechanism) was removed in TYPO3 14.
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages')
            ->update('pages', [
                'TSconfig' => "TCEFORM.tt_content.CType.removeItems = text, header\n"
                    . "TCEMAIN.table.tt_content.disableCTypes = bullets",
            ], ['uid' => 1]);

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->schemaTool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
    }

    public function testGetAvailableTypesAppliesRemoveItemsAtPid(): void
    {
        $types = $this->tableAccessService->getAvailableTypes('tt_content', 1);

        $this->assertArrayNotHasKey('text', $types, 'CType "text" should be removed by TSconfig');
        $this->assertArrayNotHasKey('header', $types, 'CType "header" should be removed by TSconfig');
        $this->assertArrayHasKey('textmedia', $types, 'CType "textmedia" should still be available');
    }

    public function testGetAvailableTypesAppliesDisableCTypesAtPid(): void
    {
        $types = $this->tableAccessService->getAvailableTypes('tt_content', 1);

        $this->assertArrayNotHasKey(
            'bullets',
            $types,
            'CType "bullets" should be filtered by TCEMAIN.disableCTypes'
        );
    }

    public function testSchemaToolHidesRemovedCTypesInOptionsWhenPidProvided(): void
    {
        $result = $this->schemaTool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia',
            'pid' => 2,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $content = $result->content[0]->text;

        // Find the line that introduces the CType field — it carries the
        // [Options: ...] block listing allowed values. Removed CTypes must not appear.
        $ctypeLine = null;
        foreach (preg_split('/\r?\n/', $content) as $line) {
            if (preg_match('/(^|\W)CType\b/', $line) && str_contains($line, '[Options:')) {
                $ctypeLine = $line;
                break;
            }
        }
        $this->assertNotNull($ctypeLine, 'Could not locate CType field line in schema output: ' . $content);

        // Each option is rendered as "<value> (<label>)" — match value boundaries
        // to avoid false positives like "textmedia" matching "text".
        $this->assertDoesNotMatchRegularExpression('/\btext\s*\(/', $ctypeLine, 'Removed CType "text" leaked into Options list: ' . $ctypeLine);
        $this->assertDoesNotMatchRegularExpression('/\bheader\s*\(/', $ctypeLine, 'Removed CType "header" leaked into Options list: ' . $ctypeLine);
        $this->assertDoesNotMatchRegularExpression('/\bbullets\s*\(/', $ctypeLine, 'disableCTypes value "bullets" leaked into Options list: ' . $ctypeLine);
        $this->assertStringContainsString('textmedia', $ctypeLine, 'Allowed CType "textmedia" missing from Options: ' . $ctypeLine);
    }

    public function testSchemaToolReturnsErrorWhenRequestingRemovedType(): void
    {
        $result = $this->schemaTool->execute([
            'table' => 'tt_content',
            'type' => 'text',
            'pid' => 2,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // When the requested type is filtered out, the tool emits an ERROR text
        // listing the still-available types.
        $this->assertStringContainsString('ERROR', $content);
        $this->assertStringContainsString("'text'", $content);
    }

    public function testWriteRejectsRemovedCType(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Should be rejected',
                'bodytext' => 'because text is removed by TSconfig',
            ],
        ]);

        $this->assertTrue($result->isError, 'Creating a tt_content with a removed CType must fail');
        $content = $result->content[0]->text;
        $this->assertStringContainsString('CType', $content);
    }

    public function testWriteRejectsDisabledCType(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'bullets',
                'header' => 'Should be rejected',
            ],
        ]);

        $this->assertTrue($result->isError, 'Creating a tt_content with a disabled CType must fail');
        $content = $result->content[0]->text;
        $this->assertStringContainsString('disableCTypes', $content);
    }

    public function testWriteAcceptsAllowedCType(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Allowed',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }
}
