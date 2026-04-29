<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\Service\TableAccessService;
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
     * The plugin container record type. TYPO3 13 uses the dedicated `list`
     * CType plus a `list_type` subtype field; TYPO3 14 uses the plugin's own
     * CType directly (e.g. `news_pi1`).
     */
    private function pluginContainerType(): string
    {
        return TableAccessService::hasPluginSubtypes() ? 'list' : 'news_pi1';
    }

    /**
     * Test that GetTableSchemaTool shows pi_flexform for a plugin record type.
     */
    public function testPluginTypeShowsPiFlexForm(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => $this->pluginContainerType(),
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        $this->assertStringContainsString('pi_flexform', $content,
            'Plugin schema should include pi_flexform field');
        $this->assertStringContainsString('GetFlexFormSchema', $content,
            'Schema should mention GetFlexFormSchema tool for FlexForm fields');
    }

    /**
     * Test that GetTableSchemaTool mentions the news plugin identifier in the
     * plugin schema.
     */
    public function testShowsPluginIdentifiers(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => $this->pluginContainerType(),
        ]);

        $content = $result->content[0]->text;

        // news_pi1 appears as either CType or list_type discriminator.
        $this->assertStringContainsString('news_pi1', $content);
        $this->assertStringContainsString('FlexForm', $content,
            'Schema should provide guidance about FlexForm fields');
    }

    /**
     * Test that the default schema lists the news plugin in some form.
     */
    public function testDefaultSchemaMentionsNewsPlugin(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content'
        ]);

        $content = $result->content[0]->text;

        $this->assertStringContainsString('news_pi1', $content,
            'Default schema should list the news plugin');
    }
}
