<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test MM relation handling for both standard and opposite relations
 */
class MmRelationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_workspace.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category.csv');
        
        // Set up backend user
        $this->setUpBackendUser(1);
        
        // Initialize language service
        if (!isset($GLOBALS['LANG'])) {
            $languageServiceFactory = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class);
            $GLOBALS['LANG'] = $languageServiceFactory->create('default');
        }
    }

    /**
     * Test opposite MM relation (categories - uses MM_opposite_field)
     */
    public function testOppositeMmRelation(): void
    {
        $writeTool = new WriteTableTool();
        $readTool = new ReadTableTool();
        
        // Create a news record with categories
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Test News with Categories',
                'datetime' => time(),
                'categories' => [1, 2], // Technology and News categories
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $createdNews = json_decode($result->content[0]->text);
        $newsUid = $createdNews->uid;
        
        // Verify MM records were created correctly
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_category_record_mm');
        
        $mmRecords = $queryBuilder
            ->select('*')
            ->from('sys_category_record_mm')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $newsUid),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tx_news_domain_model_news')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('categories'))
            )
            ->orderBy('sorting_foreign')
            ->executeQuery()
            ->fetchAllAssociative();
        
        $this->assertCount(2, $mmRecords);
        $this->assertEquals(1, $mmRecords[0]['uid_local']); // Category 1
        $this->assertEquals(2, $mmRecords[1]['uid_local']); // Category 2
        $this->assertEquals(1, $mmRecords[0]['sorting_foreign']);
        $this->assertEquals(2, $mmRecords[1]['sorting_foreign']);
        
        // Read back with relations
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'includeRelations' => true
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $readResult = json_decode($result->content[0]->text);
        $news = $readResult->records[0];
        
        // Check categories are returned as array of UIDs
        $this->assertIsArray($news->categories);
        $this->assertCount(2, $news->categories);
        $this->assertEquals([1, 2], $news->categories);
    }

    /**
     * Test standard MM relation (tags - no MM_opposite_field)
     */
    public function testStandardMmRelation(): void
    {
        $writeTool = new WriteTableTool();
        $readTool = new ReadTableTool();
        
        // First create some tags
        $tagUids = [];
        foreach (['Breaking', 'Important', 'Tech'] as $tagTitle) {
            $result = $writeTool->execute([
                'table' => 'tx_news_domain_model_tag',
                'action' => 'create',
                'pid' => 1,
                'data' => [
                    'title' => $tagTitle,
                ]
            ]);
            
            $this->assertFalse($result->isError);
            $tag = json_decode($result->content[0]->text);
            $tagUids[] = $tag->uid;
        }
        
        // Create a news record with tags
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Test News with Tags',
                'datetime' => time(),
                'tags' => $tagUids, // All three tags
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $createdNews = json_decode($result->content[0]->text);
        $newsUid = $createdNews->uid;
        
        // Verify MM records were created correctly for standard relation
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_news_domain_model_news_tag_mm');
        
        $mmRecords = $queryBuilder
            ->select('*')
            ->from('tx_news_domain_model_news_tag_mm')
            ->where(
                $queryBuilder->expr()->eq('uid_local', $newsUid) // Note: uid_local for standard relation
            )
            ->orderBy('sorting') // Note: sorting, not sorting_foreign
            ->executeQuery()
            ->fetchAllAssociative();
        
        $this->assertCount(3, $mmRecords);
        $this->assertEquals($tagUids[0], $mmRecords[0]['uid_foreign']);
        $this->assertEquals($tagUids[1], $mmRecords[1]['uid_foreign']);
        $this->assertEquals($tagUids[2], $mmRecords[2]['uid_foreign']);
        $this->assertEquals(1, $mmRecords[0]['sorting']);
        $this->assertEquals(2, $mmRecords[1]['sorting']);
        $this->assertEquals(3, $mmRecords[2]['sorting']);
        
        // Read back with relations
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'includeRelations' => true
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $readResult = json_decode($result->content[0]->text);
        $news = $readResult->records[0];
        
        // Check tags are returned as array of UIDs
        $this->assertIsArray($news->tags);
        $this->assertCount(3, $news->tags);
        $this->assertEquals($tagUids, $news->tags);
    }

    /**
     * Test MM match fields for shared MM tables
     */
    public function testMmMatchFields(): void
    {
        $writeTool = new WriteTableTool();
        $readTool = new ReadTableTool();
        
        // Create a content element with categories
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Test Content with Categories',
                'CType' => 'text',
                'categories' => [1, 3], // Technology and Business categories
            ]
        ]);
        
        $this->assertFalse($result->isError, 'Failed to create content: ' . json_encode($result->jsonSerialize()));
        $createdContent = json_decode($result->content[0]->text);
        $this->assertNotNull($createdContent, 'Failed to decode content response');
        $this->assertObjectHasProperty('uid', $createdContent, 'Content response missing UID');
        $contentUid = $createdContent->uid;
        
        // Create a news record with different categories
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Test News with Different Categories',
                'datetime' => time(),
                'categories' => [2, 4], // News and Sports categories
            ]
        ]);
        
        $this->assertFalse($result->isError);
        $createdNews = json_decode($result->content[0]->text);
        $newsUid = $createdNews->uid;
        
        // Verify that sys_category_record_mm has correct match fields
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_category_record_mm');
        
        // Check content element categories
        $contentMMRecords = $queryBuilder
            ->select('*')
            ->from('sys_category_record_mm')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $contentUid),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('categories'))
            )
            ->executeQuery()
            ->fetchAllAssociative();
        
        $this->assertCount(2, $contentMMRecords);
        $this->assertEquals('tt_content', $contentMMRecords[0]['tablenames']);
        $this->assertEquals('categories', $contentMMRecords[0]['fieldname']);
        
        // Check news categories
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_category_record_mm');
        
        $newsMMRecords = $queryBuilder
            ->select('*')
            ->from('sys_category_record_mm')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $newsUid),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tx_news_domain_model_news')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('categories'))
            )
            ->executeQuery()
            ->fetchAllAssociative();
        
        $this->assertCount(2, $newsMMRecords);
        $this->assertEquals('tx_news_domain_model_news', $newsMMRecords[0]['tablenames']);
        $this->assertEquals('categories', $newsMMRecords[0]['fieldname']);
        
        // Read back both records with relations
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
            'includeRelations' => true
        ]);
        
        $this->assertFalse($result->isError);
        $contentResult = json_decode($result->content[0]->text);
        $content = $contentResult->records[0];
        $this->assertEquals([1, 3], $content->categories);
        
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'includeRelations' => true
        ]);
        
        $this->assertFalse($result->isError);
        $newsResult = json_decode($result->content[0]->text);
        $news = $newsResult->records[0];
        $this->assertEquals([2, 4], $news->categories);
    }
}