<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetSystemStatusTool;
use Hn\McpServer\MCP\ToolRegistry;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests GetSystemStatusTool with EXT:reports loaded.
 */
class GetSystemStatusToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
        'reports',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        // Several core Reports status providers read from $GLOBALS['LANG'].
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    public function testToolIsRegisteredWhenReportsExtensionIsLoaded(): void
    {
        $registry = GeneralUtility::makeInstance(ToolRegistry::class);
        $tool = $registry->getTool('GetSystemStatus');

        self::assertNotNull($tool, 'GetSystemStatus must be registered when EXT:reports is loaded');
        self::assertInstanceOf(GetSystemStatusTool::class, $tool);
    }

    public function testExecuteReturnsSystemStatusSummary(): void
    {
        $registry = GeneralUtility::makeInstance(ToolRegistry::class);
        $tool = $registry->getTool('GetSystemStatus');
        self::assertNotNull($tool);

        $result = $tool->execute([]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        self::assertCount(1, $result->content);
        self::assertInstanceOf(TextContent::class, $result->content[0]);

        $text = $result->content[0]->text;
        self::assertStringContainsString('TYPO3 SYSTEM STATUS', $text);
        self::assertStringContainsString('Summary:', $text);
        // EXT:reports ships a "Configuration" status provider that is always present.
        self::assertMatchesRegularExpression('/\[(OK|INFO|NOTICE|WARNING|ERROR)\]/', $text);
    }

    public function testMinSeverityFiltersOutLowerSeverities(): void
    {
        $registry = GeneralUtility::makeInstance(ToolRegistry::class);
        $tool = $registry->getTool('GetSystemStatus');
        self::assertNotNull($tool);

        $result = $tool->execute(['minSeverity' => 'ERROR']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $text = $result->content[0]->text;
        self::assertStringContainsString('minSeverity=ERROR', $text);
        // Only ERROR rows may appear; OK/INFO/NOTICE/WARNING should not leak through.
        self::assertStringNotContainsString('[OK]', $text);
        self::assertStringNotContainsString('[INFO]', $text);
        self::assertStringNotContainsString('[NOTICE]', $text);
        self::assertStringNotContainsString('[WARNING]', $text);
    }

    public function testInvalidSeverityReturnsErrorResult(): void
    {
        $registry = GeneralUtility::makeInstance(ToolRegistry::class);
        $tool = $registry->getTool('GetSystemStatus');
        self::assertNotNull($tool);

        $result = $tool->execute(['minSeverity' => 'CRITICAL']);

        self::assertTrue($result->isError, 'Unknown severity must produce an error result');
    }

    public function testCategoryFilterLimitsOutput(): void
    {
        $registry = GeneralUtility::makeInstance(ToolRegistry::class);
        $tool = $registry->getTool('GetSystemStatus');
        self::assertNotNull($tool);

        $result = $tool->execute(['category' => 'does-not-exist-xyz']);
        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $text = $result->content[0]->text;
        self::assertStringContainsString('category=does-not-exist-xyz', $text);
        self::assertStringContainsString('No status entries match', $text);
    }

    public function testNonAdminBackendUserIsRejected(): void
    {
        $registry = GeneralUtility::makeInstance(ToolRegistry::class);
        $tool = $registry->getTool('GetSystemStatus');
        self::assertNotNull($tool);

        $GLOBALS['BE_USER']->user['admin'] = 0;

        $result = $tool->execute([]);

        self::assertTrue($result->isError);
        self::assertStringContainsString('admin', $result->content[0]->text);
    }
}
