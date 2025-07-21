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
     * Test that GetTableSchemaTool shows pi_flexform for list type
     */
    public function testListTypeShowsPiFlexForm(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        
        // Get schema for list type
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'list'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Check if pi_flexform appears
        $hasFlexForm = strpos($content, 'pi_flexform') !== false;
        $this->assertTrue($hasFlexForm, 'Schema for list type should include pi_flexform field');
        
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
        
        // Get schema for list type
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'list'
        ]);
        
        $content = $result->content[0]->text;
        
        // Should show available list_type options which include News plugins
        $this->assertStringContainsString('list_type', $content);
        $this->assertStringContainsString('news_pi1', $content);
        
        // Should provide guidance on FlexForm discovery
        $hasFlexFormGuidance = strpos($content, 'FlexForm') !== false || 
                               strpos($content, 'flexform') !== false ||
                               strpos($content, 'GetFlexFormSchema') !== false;
        
        $this->assertTrue($hasFlexFormGuidance, 'Schema should provide guidance about FlexForm fields');
    }

    /**
     * Test that default schema mentions available types
     */
    public function testDefaultSchemaMentionsListType(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        
        // Get default schema without type
        $result = $tool->execute([
            'table' => 'tt_content'
        ]);
        
        $content = $result->content[0]->text;
        
        // Should list available types including 'list'
        $this->assertStringContainsString('list', $content);
        $this->assertStringContainsString('General Plugin', $content);
        
        // Should mention that plugins may have FlexForm configuration
        $hasPluginInfo = strpos($content, 'plugin') !== false || 
                        strpos($content, 'Plugin') !== false;
        
        $this->assertTrue($hasPluginInfo, 'Default schema should mention plugins');
    }
}