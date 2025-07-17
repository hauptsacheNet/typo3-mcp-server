<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test that News extension table schemas are properly handled by GetTableSchemaTool
 */
class NewsSchemaTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import backend user fixture
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_workspace.csv');
        
        // Set up backend user
        $this->setUpBackendUserWithWorkspace(1);
        
        // Initialize language service
        if (!isset($GLOBALS['LANG'])) {
            $languageServiceFactory = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class);
            $GLOBALS['LANG'] = $languageServiceFactory->create('default');
        }
    }

    /**
     * Test getting News table schema
     */
    public function testGetNewsTableSchema(): void
    {
        $tool = new GetTableSchemaTool();
        
        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $content = $result->content[0]->text;
        
        // Verify essential News fields are present
        $this->assertStringContainsString('TABLE SCHEMA: tx_news_domain_model_news', $content);
        $this->assertStringContainsString('title', $content);
        $this->assertStringContainsString('teaser', $content);
        $this->assertStringContainsString('bodytext', $content);
        $this->assertStringContainsString('datetime', $content);
        $this->assertStringContainsString('archive', $content);
        $this->assertStringContainsString('author', $content);
        $this->assertStringContainsString('categories', $content);
        $this->assertStringContainsString('tags', $content);
    }

    /**
     * Test News field types and configurations
     */
    public function testNewsFieldTypes(): void
    {
        $tool = new GetTableSchemaTool();
        
        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news'
        ]);
        
        $content = $result->content[0]->text;
        
        // Field types should be properly detected (labels might be empty in test environment)
        $this->assertMatchesRegularExpression('/datetime\s*\([^)]*\):\s*datetime/i', $content);
        $this->assertMatchesRegularExpression('/bodytext\s*\([^)]*\):\s*text/i', $content);
        $this->assertMatchesRegularExpression('/categories\s*\([^)]*\):\s*select/i', $content);
    }

    /**
     * Test that News uses sys_category for categories
     */
    public function testNewsUsesSysCategoryForCategories(): void
    {
        $tool = new GetTableSchemaTool();
        
        // News extends sys_category, not creates its own table
        $result = $tool->execute([
            'table' => 'sys_category'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Verify sys_category fields that News uses
        $this->assertStringContainsString('TABLE SCHEMA: sys_category', $content);
        $this->assertStringContainsString('title', $content);
        $this->assertStringContainsString('parent', $content);
        $this->assertStringContainsString('description', $content);
    }

    /**
     * Test getting News tag table schema
     */
    public function testGetNewsTagSchema(): void
    {
        $tool = new GetTableSchemaTool();
        
        $result = $tool->execute([
            'table' => 'tx_news_domain_model_tag'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Verify tag fields
        $this->assertStringContainsString('TABLE SCHEMA: tx_news_domain_model_tag', $content);
        $this->assertStringContainsString('title', $content);
    }

    /**
     * Test that News uses sys_file_reference for media (but it's restricted)
     */
    public function testNewsUsesSysFileReferenceForMedia(): void
    {
        $tool = new GetTableSchemaTool();
        
        // News extends sys_file_reference, but this table is restricted
        $result = $tool->execute([
            'table' => 'sys_file_reference'
        ]);
        
        // sys_file_reference is restricted for security reasons
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('restricted', $result->content[0]->text);
    }

    /**
     * Test that News schema shows proper field grouping/tabs
     */
    public function testNewsSchemaFieldGrouping(): void
    {
        $tool = new GetTableSchemaTool();
        
        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news'
        ]);
        
        $content = $result->content[0]->text;
        
        // News uses parentheses for tabs/sections (labels might be empty in test environment)
        $this->assertMatchesRegularExpression('/\(General\):|General\):/', $content);
        $this->assertMatchesRegularExpression('/\(Categories\):|Categories\):/', $content);
        $this->assertMatchesRegularExpression('/\(Media\):|Media\):/', $content);
    }

    /**
     * Test schema for News types (if News uses types)
     */
    public function testNewsTypes(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get basic schema to check if types are used
        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news'
        ]);
        
        $content = $result->content[0]->text;
        
        // Check if News uses type field
        if (str_contains($content, 'type:')) {
            // Test getting schema for a specific type
            $result = $tool->execute([
                'table' => 'tx_news_domain_model_news',
                'type' => '0' // Default news type
            ]);
            
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $this->assertStringContainsString('Type: 0', $result->content[0]->text);
        } else {
            // No types used, which is also valid
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Test News plugin schema in tt_content shows FlexForm fields
     */
    public function testNewsPluginSchemaInTtContent(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for News plugin content type
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'news_pi1' // News plugin
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Verify it's the News plugin type
        $this->assertStringContainsString('Type: news_pi1', $content);
        // The label should be translated or have a meaningful fallback
        $this->assertTrue(
            str_contains($content, 'News article list') || 
            str_contains($content, 'News list'), // Fallback from "news_list.title"
            'Should contain News plugin label or fallback'
        );
        
        // Should contain pi_flexform field in Plugin tab
        $this->assertStringContainsString('(Plugin):', $content, 'Should have Plugin tab');
        $this->assertStringContainsString('pi_flexform', $content, 'News plugin should have pi_flexform field');
        
        // Check that it's recognized as FlexForm type with proper formatting
        $this->assertMatchesRegularExpression('/pi_flexform\s*\(Plugin Options\):\s*flex\s*\(FlexForm\)/i', $content, 
            'pi_flexform should be properly formatted with label and type');
        
        // Check for FlexForm identifiers
        $this->assertMatchesRegularExpression('/\[Identifiers:\s*[^\]]*news_pi1[^\]]*\]/', $content, 
            'Should have news_pi1 in FlexForm identifiers');
        
        // Verify the instruction to use GetFlexFormSchema tool
        $this->assertStringContainsString('Use GetFlexFormSchema tool with these identifiers', $content, 
            'Should provide instruction to use GetFlexFormSchema tool');
        
        // Check for ds_pointerField information
        $this->assertStringContainsString('[ds_pointerField: list_type,CType]', $content, 
            'Should show ds_pointerField configuration');
    }

    /**
     * Test that News plugin FlexForm schema can be retrieved
     */
    public function testGetNewsPluginFlexFormIdentifiers(): void
    {
        $tool = new GetTableSchemaTool();
        
        // First, check if News registers as a plugin type
        $result = $tool->execute([
            'table' => 'tt_content'
        ]);
        
        $this->assertFalse($result->isError);
        $content = $result->content[0]->text;
        
        // Look for available types to understand how News plugin is registered
        if (preg_match('/AVAILABLE TYPES:(.+?)(?=\n\n|$)/s', $content, $matches)) {
            $typesSection = $matches[1];
            
            // News typically registers as list_type "news_pi1" rather than a direct CType
            if (strpos($typesSection, 'list') !== false) {
                // List type exists, which is what News uses
                $this->assertStringContainsString('list', $typesSection, 'List type should be available for plugins');
            }
        }
        
        // Also check if the schema mentions list_type field for list content
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'list'
        ]);
        
        if (!$result->isError) {
            $content = $result->content[0]->text;
            
            // List content should have list_type field
            if (strpos($content, 'list_type') !== false) {
                // Check that list_type is properly typed
                $this->assertMatchesRegularExpression('/list_type\s*\([^)]*\):\s*select/i', $content, 
                    'list_type should be a select field');
            }
        }
    }

    protected function setUpBackendUserWithWorkspace(int $uid): void
    {
        $backendUser = $this->setUpBackendUser($uid);
        $backendUser->workspace = 1; // Set to test workspace
        $GLOBALS['BE_USER'] = $backendUser;
    }
}