<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test CRUD operations for News records with category handling
 */
class NewsCrudTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    protected array $categoryUids = [];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import backend user fixture
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_workspace.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        
        // Set up backend user
        $this->setUpBackendUserWithWorkspace(1);
        
        // Initialize language service
        if (!isset($GLOBALS['LANG'])) {
            $languageServiceFactory = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class);
            $GLOBALS['LANG'] = $languageServiceFactory->create('default');
        }
    }

    /**
     * Test schema shows MM table information for categories
     */
    public function testNewsCategoriesSchemaShowsMmTable(): void
    {
        $tool = new GetTableSchemaTool();
        
        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;
        
        // Check that categories field shows MM table information
        $this->assertMatchesRegularExpression(
            '/categories.*\[MM table: sys_category_record_mm\]/i', 
            $content,
            'Categories field should show MM table information'
        );
        
        // Should also show foreign table
        $this->assertMatchesRegularExpression(
            '/categories.*\[foreign table: sys_category\]/i', 
            $content,
            'Categories field should show foreign table information'
        );
    }

    /**
     * Test creating categories for News
     */
    public function testCreateCategories(): void
    {
        $writeTool = new WriteTableTool();
        
        // Create parent category
        $result = $writeTool->execute([
            'table' => 'sys_category',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News Categories',
                'description' => 'Parent category for news'
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $createdRecord = json_decode($result->content[0]->text);
        $this->categoryUids[] = $createdRecord->uid;
        
        // Create child categories
        $childCategories = [
            'Breaking News' => 'Category for breaking news',
            'Technology' => 'Technology related news',
            'Sports' => 'Sports news'
        ];
        
        foreach ($childCategories as $title => $description) {
            $result = $writeTool->execute([
                'table' => 'sys_category',
                'action' => 'create',
                'pid' => 1,
                'data' => [
                    'title' => $title,
                    'description' => $description,
                    'parent' => $this->categoryUids[0]
                ]
            ]);
            
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $createdRecord = json_decode($result->content[0]->text);
            $this->categoryUids[] = $createdRecord->uid;
        }
        
        // Verify categories were created
        $readTool = new ReadTableTool();
        $result = $readTool->execute([
            'table' => 'sys_category',
            'pid' => 1
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $readResult = json_decode($result->content[0]->text);
        $this->assertCount(4, $readResult->records);
    }

    /**
     * Test creating a News record with multiple categories
     */
    public function testCreateNewsWithCategories(): void
    {
        // First create categories
        $this->testCreateCategories();
        
        $writeTool = new WriteTableTool();
        
        // Create news with multiple categories (using the child category UIDs)
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Test News with Categories',
                'teaser' => 'This is a test news item with multiple categories',
                'bodytext' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
                'datetime' => time(),
                'author' => 'Test Author',
                'categories' => [$this->categoryUids[1], $this->categoryUids[2], $this->categoryUids[3]] // Breaking News, Technology, Sports
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $createdRecord = json_decode($result->content[0]->text);
        $newsUid = $createdRecord->uid;
        
        // Read the news record back
        $readTool = new ReadTableTool();
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $readResult = json_decode($result->content[0]->text);
        $this->assertCount(1, $readResult->records);
        
        $newsRecord = $readResult->records[0];
        
        // Verify categories are returned as an array of IDs
        $this->assertIsArray($newsRecord->categories, 'Categories should be an array');
        $this->assertCount(3, $newsRecord->categories, 'Should have 3 categories');
        
        // Categories should be the IDs we assigned
        $expectedCategories = [$this->categoryUids[1], $this->categoryUids[2], $this->categoryUids[3]];
        sort($expectedCategories);
        sort($newsRecord->categories);
        $this->assertEquals($expectedCategories, $newsRecord->categories, 'Categories should match the assigned IDs');
    }

    /**
     * Test updating News categories
     */
    public function testUpdateNewsCategories(): void
    {
        // First create news with categories
        $this->testCreateNewsWithCategories();
        
        // Get the news record
        $readTool = new ReadTableTool();
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'pid' => 1,
            'limit' => 1
        ]);
        
        $this->assertFalse($result->isError);
        $readResult = json_decode($result->content[0]->text);
        $newsUid = $readResult->records[0]->uid;
        
        // Update with different categories
        $writeTool = new WriteTableTool();
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'categories' => [$this->categoryUids[1], $this->categoryUids[3]] // Only Breaking News and Sports
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Read back and verify
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid
        ]);
        
        $this->assertFalse($result->isError);
        $readResult = json_decode($result->content[0]->text);
        $newsRecord = $readResult->records[0];
        
        // Verify updated categories
        $this->assertIsArray($newsRecord->categories);
        $this->assertCount(2, $newsRecord->categories);
        
        $expectedCategories = [$this->categoryUids[1], $this->categoryUids[3]];
        sort($expectedCategories);
        sort($newsRecord->categories);
        $this->assertEquals($expectedCategories, $newsRecord->categories);
    }

    /**
     * Test reading News without categories
     */
    public function testReadNewsWithoutCategories(): void
    {
        $writeTool = new WriteTableTool();
        
        // Create news without categories
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News without categories',
                'teaser' => 'This news has no categories',
                'bodytext' => 'Content without categories',
                'datetime' => time()
            ]
        ]);
        
        $this->assertFalse($result->isError);
        $createdRecord = json_decode($result->content[0]->text);
        $newsUid = $createdRecord->uid;
        
        // Read it back
        $readTool = new ReadTableTool();
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid
        ]);
        
        $this->assertFalse($result->isError);
        $readResult = json_decode($result->content[0]->text);
        $newsRecord = $readResult->records[0];
        
        // Categories should be empty array or not set
        if (isset($newsRecord->categories)) {
            $this->assertIsArray($newsRecord->categories);
            $this->assertEmpty($newsRecord->categories);
        }
    }

    /**
     * Test deleting News record
     */
    public function testDeleteNews(): void
    {
        // First create a news record
        $writeTool = new WriteTableTool();
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News to be deleted',
                'teaser' => 'This will be deleted',
                'bodytext' => 'Content to delete',
                'datetime' => time()
            ]
        ]);
        
        $this->assertFalse($result->isError);
        $createdRecord = json_decode($result->content[0]->text);
        $newsUid = $createdRecord->uid;
        
        // Delete it
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'delete',
            'uid' => $newsUid
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Try to read it - should not be found
        $readTool = new ReadTableTool();
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid
        ]);
        
        $this->assertFalse($result->isError);
        $readResult = json_decode($result->content[0]->text);
        $this->assertCount(0, $readResult->records, 'Deleted news should not be found');
    }

    /**
     * Test that MM relations work even though sys_category_record_mm is not workspace-capable
     */
    public function testMmRelationsWorkWithoutWorkspaceSupport(): void
    {
        // This test verifies that category assignments work correctly
        // even though sys_category_record_mm doesn't have workspace fields
        
        // Create categories
        $this->testCreateCategories();
        
        // Create news in workspace
        $writeTool = new WriteTableTool();
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Workspace News with MM Relations',
                'teaser' => 'Testing MM relations in workspace',
                'bodytext' => 'This tests that MM relations work correctly',
                'datetime' => time(),
                'categories' => [$this->categoryUids[1], $this->categoryUids[2]]
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $createdRecord = json_decode($result->content[0]->text);
        $newsUid = $createdRecord->uid;
        
        // Read back in workspace
        $readTool = new ReadTableTool();
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid
        ]);
        
        $this->assertFalse($result->isError);
        $readResult = json_decode($result->content[0]->text);
        $newsRecord = $readResult->records[0];
        
        // Verify categories work correctly
        $this->assertIsArray($newsRecord->categories);
        $this->assertCount(2, $newsRecord->categories);
        
        // The MM relations should work even without workspace support
        $expectedCategories = [$this->categoryUids[1], $this->categoryUids[2]];
        sort($expectedCategories);
        sort($newsRecord->categories);
        $this->assertEquals($expectedCategories, $newsRecord->categories,
            'MM relations should work correctly even without workspace support in MM table');
    }

    /**
     * Test other News fields to ensure they're not affected
     */
    public function testOtherNewsFields(): void
    {
        $writeTool = new WriteTableTool();
        
        // Create news with various field types
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with various fields',
                'teaser' => 'Testing different field types',
                'bodytext' => 'Rich text content',
                'datetime' => time(),
                'archive' => time() + 86400, // Tomorrow
                'author' => 'John Doe',
                'author_email' => 'john@example.com',
                'keywords' => 'test, news, keywords',
                'description' => 'Meta description',
                'istopnews' => 1,
                'type' => 0 // Normal news
            ]
        ]);
        
        $this->assertFalse($result->isError);
        $createdRecord = json_decode($result->content[0]->text);
        $newsUid = $createdRecord->uid;
        
        // Read back
        $readTool = new ReadTableTool();
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid
        ]);
        
        $this->assertFalse($result->isError);
        $readResult = json_decode($result->content[0]->text);
        $newsRecord = $readResult->records[0];
        
        // Verify fields
        $this->assertEquals('News with various fields', $newsRecord->title);
        $this->assertEquals('John Doe', $newsRecord->author);
        $this->assertEquals('john@example.com', $newsRecord->author_email);
        $this->assertEquals(1, $newsRecord->istopnews);
        $this->assertEquals(0, $newsRecord->type);
        
        // Single select fields should remain as single values
        $this->assertIsInt($newsRecord->type);
        $this->assertIsNotArray($newsRecord->type);
    }

    protected function setUpBackendUserWithWorkspace(int $uid): void
    {
        $backendUser = $this->setUpBackendUser($uid);
        $backendUser->workspace = 1; // Set to test workspace
        $GLOBALS['BE_USER'] = $backendUser;
    }
}