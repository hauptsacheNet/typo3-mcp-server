<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
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
     * Test that GetTableSchemaTool shows pi_flexform for a plugin CType
     */
    public function testPluginCTypeShowsPiFlexForm(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'news_pi1',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        $this->assertStringContainsString('pi_flexform', $content,
            'Schema for news_pi1 plugin should include pi_flexform field');
        $this->assertStringContainsString('GetFlexFormSchema', $content,
            'Schema should mention GetFlexFormSchema tool for FlexForm fields');
    }

    /**
     * Test that GetTableSchemaTool mentions plugin identifiers on the
     * pi_flexform field for plugin CTypes.
     */
    public function testShowsPluginIdentifiers(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'news_pi1',
        ]);

        $content = $result->content[0]->text;

        $this->assertStringContainsString('news_pi1', $content);
        $this->assertStringContainsString('FlexForm', $content,
            'Schema should provide guidance about FlexForm fields');
    }

    /**
     * Test that the default schema lists plugin CTypes.
     */
    public function testDefaultSchemaMentionsPluginCType(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);

        $result = $tool->execute([
            'table' => 'tt_content'
        ]);

        $content = $result->content[0]->text;

        $this->assertStringContainsString('news_pi1', $content,
            'Default schema should list the news_pi1 plugin CType');
    }
}