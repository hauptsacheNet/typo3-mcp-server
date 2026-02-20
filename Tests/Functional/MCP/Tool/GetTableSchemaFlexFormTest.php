<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test GetTableSchemaTool FlexForm discoverability
 */
class GetTableSchemaFlexFormTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'news',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Test that GetTableSchemaTool shows pi_flexform for plugin types
     */
    public function testListTypeShowsPiFlexForm(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class);

        if ($typo3Version->getMajorVersion() >= 14) {
            // In TYPO3 14+, plugins have their own CType (e.g., news_pi1)
            $result = $tool->execute([
                'table' => 'tt_content',
                'type' => 'news_pi1'
            ]);
        } else {
            // In TYPO3 13, plugins use CType='list'
            $result = $tool->execute([
                'table' => 'tt_content',
                'type' => 'list'
            ]);
        }

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Check if pi_flexform appears
        $hasFlexForm = strpos($content, 'pi_flexform') !== false;
        $this->assertTrue($hasFlexForm, 'Schema for plugin type should include pi_flexform field');

        // Check if it mentions GetFlexFormSchema tool
        $mentionsFlexFormTool = strpos($content, 'GetFlexFormSchema') !== false;
        $this->assertTrue($mentionsFlexFormTool, 'Schema should mention GetFlexFormSchema tool for FlexForm fields');
    }

    /**
     * Test that GetTableSchemaTool mentions plugin identifiers
     */
    public function testShowsPluginIdentifiers(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class);

        if ($typo3Version->getMajorVersion() >= 14) {
            // In TYPO3 14+, get schema for news_pi1 CType
            $result = $tool->execute([
                'table' => 'tt_content',
                'type' => 'news_pi1'
            ]);

            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $content = $result->content[0]->text;

            // Should show pi_flexform and guidance about FlexForm discovery
            $hasFlexFormGuidance = strpos($content, 'FlexForm') !== false ||
                                   strpos($content, 'flexform') !== false ||
                                   strpos($content, 'GetFlexFormSchema') !== false;

            $this->assertTrue($hasFlexFormGuidance, 'Schema should provide guidance about FlexForm fields');
        } else {
            // In TYPO3 13, get schema for list CType
            $result = $tool->execute([
                'table' => 'tt_content',
                'type' => 'list'
            ]);

            $content = $result->content[0]->text;

            // Should show available list_type options which include News plugins
            $this->assertStringContainsString('list_type', $content);
            $this->assertStringContainsString('news_pi1', $content);

            $hasFlexFormGuidance = strpos($content, 'FlexForm') !== false ||
                                   strpos($content, 'flexform') !== false ||
                                   strpos($content, 'GetFlexFormSchema') !== false;

            $this->assertTrue($hasFlexFormGuidance, 'Schema should provide guidance about FlexForm fields');
        }
    }

    /**
     * Test that default schema mentions available types including plugins
     */
    public function testDefaultSchemaMentionsListType(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class);

        // Get default schema without type
        $result = $tool->execute([
            'table' => 'tt_content'
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        if ($typo3Version->getMajorVersion() >= 14) {
            // In TYPO3 14+, news plugins appear as dedicated CTypes
            $this->assertStringContainsString('news_pi1', $content);
        } else {
            // In TYPO3 13, plugins are listed under 'list' type
            $this->assertStringContainsString('list', $content);
            $this->assertStringContainsString('General Plugin', $content);
        }

        // Should mention that plugins may have FlexForm configuration
        $hasPluginInfo = strpos($content, 'plugin') !== false ||
                        strpos($content, 'Plugin') !== false ||
                        strpos($content, 'news') !== false;

        $this->assertTrue($hasPluginInfo, 'Default schema should mention plugins');
    }
}
