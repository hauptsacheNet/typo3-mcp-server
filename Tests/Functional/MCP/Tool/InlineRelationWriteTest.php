<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test writing inline relations through different approaches
 */
class InlineRelationWriteTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Test writing inline relations through foreign field (current working method)
     */
    public function testWriteInlineRelationThroughForeignField(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create a page
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pageUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Create a news record
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News with inline content',
                'bodytext' => 'Test news body',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Create content elements with foreign field set
        $contentUids = [];
        for ($i = 1; $i <= 2; $i++) {
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => $pageUid,
                'data' => [
                    'header' => "Content element $i",
                    'bodytext' => "Content for element $i",
                    'CType' => 'text',
                    'tx_news_related_news' => $newsUid,  // Foreign field
                    'sorting' => $i * 256,
                ],
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $contentUids[] = json_decode($result->content[0]->text, true)['uid'];
        }
        
        // Read the news record and verify inline relations
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        
        // Verify content_elements field contains UIDs
        $this->assertArrayHasKey('content_elements', $news);
        $this->assertIsArray($news['content_elements']);
        $this->assertCount(2, $news['content_elements']);
        
        // Verify we get UIDs, not full records
        foreach ($news['content_elements'] as $uid) {
            $this->assertIsInt($uid);
            $this->assertContains($uid, $contentUids);
        }
        
        // Verify all created content elements are included (order doesn't matter)
        sort($contentUids);
        $actualUids = $news['content_elements'];
        sort($actualUids);
        $this->assertEquals($contentUids, $actualUids);
    }

    /**
     * Test writing inline relations for hidden tables (sys_file_reference)
     */
    public function testWriteHiddenTableInlineRelation(): void
    {
        $this->markTestSkipped('sys_file_reference is intentionally restricted due to workspace limitations');
    }

    /**
     * Test updating inline relations through parent record using UID arrays
     */
    public function testWriteInlineRelationThroughParentUsingUids(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create a page first
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pageUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Create a news record
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News to update with inline content',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Create content elements separately
        $contentUids = [];
        for ($i = 1; $i <= 3; $i++) {
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => $pageUid,
                'data' => [
                    'header' => "Content element $i",
                    'CType' => 'text',
                ],
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $contentUids[] = json_decode($result->content[0]->text, true)['uid'];
        }
        
        // Now update the news record with inline content_elements using UIDs
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uids' => [$newsUid],
            'data' => [
                'content_elements' => $contentUids,  // Array of UIDs
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Verify the inline relations were set
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        
        $this->assertArrayHasKey('content_elements', $news);
        $this->assertIsArray($news['content_elements']);
        $this->assertCount(3, $news['content_elements']);
        
        // Verify all UIDs are present
        foreach ($contentUids as $uid) {
            $this->assertContains($uid, $news['content_elements']);
        }
    }

    /**
     * Test updating inline relations
     */
    public function testUpdateInlineRelations(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create initial setup
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $pageUid = json_decode($result->content[0]->text, true)['uid'];
        
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News to update',
            ],
        ]);
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Create initial content element
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'header' => 'Original content',
                'CType' => 'text',
                'tx_news_related_news' => $newsUid,
            ],
        ]);
        $contentUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Update the content element to remove relation
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uids' => [$contentUid],
            'data' => [
                'tx_news_related_news' => 0,  // Remove relation
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Verify relation is removed
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertArrayHasKey('content_elements', $news, 'Should have content_elements field');
        $this->assertEmpty($news['content_elements'], 'content_elements should be empty after removal');
    }

    /**
     * Test writing inline relations with sorting
     */
    public function testInlineRelationSorting(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create page and news
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $pageUid = json_decode($result->content[0]->text, true)['uid'];
        
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News with sorted content',
            ],
        ]);
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Create content elements in reverse order because DataHandler assigns
        // lower sorting values to newer records when using default 'bottom' position
        $contentData = [
            ['header' => 'Third', 'sorting' => 300],
            ['header' => 'Second', 'sorting' => 200],
            ['header' => 'First', 'sorting' => 100],
        ];
        
        $createdUids = [];
        foreach ($contentData as $data) {
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => $pageUid,
                'data' => array_merge($data, [
                    'CType' => 'text',
                    'tx_news_related_news' => $newsUid,
                ]),
            ]);
            $this->assertFalse($result->isError);
            $uid = json_decode($result->content[0]->text, true)['uid'];
            $createdUids[$data['header']] = $uid;
        }
        
        // Read and verify sorting
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertArrayHasKey('content_elements', $news);
        $this->assertCount(3, $news['content_elements']);
        
        // Check actual order
        $actualOrder = [];
        $sortingInfo = [];
        foreach ($news['content_elements'] as $uid) {
            // Get sorting value from database for debugging
            $queryBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
                ->getQueryBuilderForTable('tt_content');
            $queryBuilder->getRestrictions()->removeAll();
            $record = $queryBuilder->select('header', 'sorting')
                ->from('tt_content')
                ->where($queryBuilder->expr()->eq('uid', $uid))
                ->executeQuery()
                ->fetchAssociative();
            
            $sortingInfo[$uid] = $record['sorting'];
            
            foreach ($createdUids as $header => $createdUid) {
                if ($uid == $createdUid) {
                    $actualOrder[] = $header;
                    break;
                }
            }
        }
        
        // Verify that the content elements are returned in ascending sorting order
        // The actual sorting values may differ from what we set due to DataHandler processing
        $this->assertEquals(['First', 'Second', 'Third'], $actualOrder, 
            'Content elements should be returned in sorting order. ' .
            'Actual order: ' . json_encode($actualOrder) . ', ' .
            'UIDs in order: ' . json_encode($news['content_elements']) . ', ' .
            'Sorting values: ' . json_encode($sortingInfo));
    }

    /**
     * Test partial update of inline relations (keeping some, removing others)
     */
    public function testPartialUpdateOfInlineRelations(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create setup
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 1,
            ],
        ]);
        $pageUid = json_decode($result->content[0]->text, true)['uid'];
        
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => $pageUid,
            'data' => [
                'title' => 'News for partial update test',
            ],
        ]);
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Create 4 content elements initially
        $allContentUids = [];
        for ($i = 1; $i <= 4; $i++) {
            $result = $writeTool->execute([
                'table' => 'tt_content',
                'action' => 'create',
                'pid' => $pageUid,
                'data' => [
                    'header' => "Content $i",
                    'CType' => 'text',
                    'tx_news_related_news' => $newsUid,
                ],
            ]);
            $this->assertFalse($result->isError);
            $allContentUids[] = json_decode($result->content[0]->text, true)['uid'];
        }
        
        // Update news to keep only content elements 2 and 4
        $keptUids = [$allContentUids[1], $allContentUids[3]]; // indices 1 and 3
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uids' => [$newsUid],
            'data' => [
                'content_elements' => $keptUids,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Verify only the specified UIDs remain
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        
        // Check content elements that should be kept
        foreach ([1, 3] as $index) {
            $result = $readTool->execute([
                'table' => 'tt_content',
                'uid' => $allContentUids[$index],
            ]);
            $response = json_decode($result->content[0]->text, true);
            if (!isset($response['records'][0])) {
                $this->fail("No record found for content element {$allContentUids[$index]}");
            }
            $content = $response['records'][0];
            // Check if the foreign field is returned
            if (!array_key_exists('tx_news_related_news', $content)) {
                // Field might be filtered out, check directly in database
                $queryBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
                    ->getQueryBuilderForTable('tt_content');
                $queryBuilder->getRestrictions()->removeAll();
                $dbRecord = $queryBuilder->select('tx_news_related_news')
                    ->from('tt_content')
                    ->where($queryBuilder->expr()->eq('uid', $allContentUids[$index]))
                    ->executeQuery()
                    ->fetchAssociative();
                $relatedNews = $dbRecord['tx_news_related_news'] ?? 0;
            } else {
                $relatedNews = $content['tx_news_related_news'];
            }
            $this->assertEquals($newsUid, $relatedNews, 
                "Content element {$allContentUids[$index]} should still be related to news");
        }
        
        // Check content elements that should be removed
        foreach ([0, 2] as $index) {
            $result = $readTool->execute([
                'table' => 'tt_content',
                'uid' => $allContentUids[$index],
            ]);
            $response = json_decode($result->content[0]->text, true);
            if (!isset($response['records'][0])) {
                $this->fail("No record found for content element {$allContentUids[$index]}");
            }
            $content = $response['records'][0];
            // Check if the foreign field is returned
            if (!array_key_exists('tx_news_related_news', $content)) {
                // Field might be filtered out, check directly in database
                $queryBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
                    ->getQueryBuilderForTable('tt_content');
                $queryBuilder->getRestrictions()->removeAll();
                $dbRecord = $queryBuilder->select('tx_news_related_news')
                    ->from('tt_content')
                    ->where($queryBuilder->expr()->eq('uid', $allContentUids[$index]))
                    ->executeQuery()
                    ->fetchAssociative();
                $relatedNews = $dbRecord['tx_news_related_news'] ?? 0;
            } else {
                $relatedNews = $content['tx_news_related_news'];
            }
            $this->assertEquals(0, $relatedNews, 
                "Content element {$allContentUids[$index]} should no longer be related to news");
        }
    }

    /**
     * Test validation errors for inline relations
     */
    public function testInlineRelationValidationErrors(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create a news record first
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News for validation test',
            ],
        ]);
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Test 1: Non-array value - batch operations report validation errors in failed array
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uids' => [$newsUid],
            'data' => [
                'content_elements' => 'not-an-array',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);
        $this->assertNotEmpty($data['failed'], 'Should have failed records');
        $this->assertStringContainsString('must be an array of UIDs', $data['failed'][0]['error']);

        // Test 2: Array with non-numeric values
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uids' => [$newsUid],
            'data' => [
                'content_elements' => [1, 'invalid', 3],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);
        $this->assertNotEmpty($data['failed'], 'Should have failed records');
        $this->assertStringContainsString('must contain only positive integer UIDs', $data['failed'][0]['error']);

        // Test 3: Array with negative values
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uids' => [$newsUid],
            'data' => [
                'content_elements' => [1, -5, 3],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);
        $this->assertNotEmpty($data['failed'], 'Should have failed records');
        $this->assertStringContainsString('must contain only positive integer UIDs', $data['failed'][0]['error']);

        // Test 4: Array with data objects (not supported yet)
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uids' => [$newsUid],
            'data' => [
                'content_elements' => [
                    ['header' => 'New content', 'CType' => 'text'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);
        $this->assertNotEmpty($data['failed'], 'Should have failed records');
        $this->assertStringContainsString('must contain only positive integer UIDs', $data['failed'][0]['error']);
    }
}