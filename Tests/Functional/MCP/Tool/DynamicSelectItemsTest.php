<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\SelectItemResolver;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests dynamic select field item resolution via FormDataCompiler.
 *
 * Validates that select fields with items added/removed via TSconfig
 * are properly resolved for both validation and schema display.
 */
class DynamicSelectItemsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/mcp_server',
    ];

    protected array $configurationToUseInTestInstance = [
        'BE' => [
            'defaultPageTSconfig' => '
                TCEFORM.tt_content.colPos.addItems.20 = Custom Column
                TCEFORM.tt_content.colPos.addItems.30 = Another Column
            ',
        ],
    ];

    protected WriteTableTool $writeTool;
    protected TableAccessService $tableAccessService;
    protected SelectItemResolver $selectItemResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
        $this->selectItemResolver = GeneralUtility::makeInstance(SelectItemResolver::class);
    }

    public function testSelectItemResolverReturnsStaticItems(): void
    {
        $resolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'CType');
        $this->assertNotNull($resolved);
        $this->assertContains('text', $resolved['values']);
        $this->assertContains('textmedia', $resolved['values']);
    }

    public function testSelectItemResolverReturnsTsconfigAddedItems(): void
    {
        $resolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'colPos');
        $this->assertNotNull($resolved, 'colPos should resolve. Values: ' . json_encode($resolved));

        // Standard colPos value 0 should always be present
        $this->assertContains('0', $resolved['values'], 'colPos 0 should be present. Resolved: ' . json_encode($resolved['values']));

        // TSconfig-added colPos values
        $this->assertContains('20', $resolved['values'], 'Custom colPos 20 from TSconfig should be resolved. Resolved: ' . json_encode($resolved['values']));
        $this->assertContains('30', $resolved['values'], 'Custom colPos 30 from TSconfig should be resolved. Resolved: ' . json_encode($resolved['values']));
    }

    public function testValidationAcceptsDynamicColPosValues(): void
    {
        $record = ['pid' => 1, 'CType' => 'text', 'colPos' => 20];
        $error = $this->tableAccessService->validateFieldValue('tt_content', 'colPos', 20, $record);
        $this->assertNull($error, 'Dynamic colPos 20 from TSconfig should pass validation');
    }

    public function testValidationRejectsInvalidColPosValues(): void
    {
        $record = ['pid' => 1, 'CType' => 'text', 'colPos' => 999];
        $resolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'colPos', $record);
        $this->assertNotNull($resolved);
        $this->assertNotContains('999', $resolved['values'], 'colPos 999 should NOT be in resolved items. Got: ' . json_encode($resolved['values']));

        $error = $this->tableAccessService->validateFieldValue('tt_content', 'colPos', 999, $record);
        $this->assertNotNull($error, 'Invalid colPos 999 should fail validation');
        $this->assertStringContainsString('must be one of', $error);
    }

    public function testWriteTableAcceptsDynamicColPos(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Test Content',
                'colPos' => 20,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testWriteTableRejectsInvalidColPos(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Test Content',
                'colPos' => 999,
            ],
        ]);
        $this->assertTrue($result->isError);
        $errorMessage = $result->jsonSerialize()['content'][0]->text ?? '';
        $this->assertStringContainsString('colPos', $errorMessage);
    }

    public function testStaticCTypeValidationStillWorks(): void
    {
        // Valid CType should pass
        $error = $this->tableAccessService->validateFieldValue('tt_content', 'CType', 'text');
        $this->assertNull($error);

        // Invalid CType should fail
        $error = $this->tableAccessService->validateFieldValue('tt_content', 'CType', 'invalid_ctype_xyz');
        $this->assertNotNull($error);
        $this->assertStringContainsString('must be one of', $error);
    }

    public function testSelectItemResolverReturnsNullForNonSelectField(): void
    {
        $resolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'header');
        $this->assertNull($resolved);
    }

    public function testSelectItemResolverReturnsNullForInvalidTable(): void
    {
        $resolved = $this->selectItemResolver->resolveSelectItems('nonexistent_table', 'field');
        $this->assertNull($resolved);
    }
}
