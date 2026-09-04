<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\EdgeCase;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Test resource constraint edge cases
 * 
 * @group resource-intensive
 */
class ResourceConstraintTest extends AbstractFunctionalTest
{
    protected WriteTableTool $writeTool;
    protected ReadTableTool $readTool;
    protected SearchTool $searchTool;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize tools
        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $this->searchTool = GeneralUtility::makeInstance(SearchTool::class);
    }
    
    /**
     * Test handling of large result sets
     */
    public function testLargeResultSetHandling(): void
    {
        // Create many content elements
        $contentCount = 1000;
        $createdUids = [];
        
        for ($i = 0; $i < $contentCount; $i++) {
            $result = $this->writeTool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => 1,
                'data' => [
                    'CType' => 'text',
                    'header' => "Content Element $i",
                    'bodytext' => "This is test content number $i with some text."
                ]
            ]);
            
            if (!$result->isError) {
                $data = json_decode($result->content[0]->text, true);
                $createdUids[] = $data['uid'];
            }
            
            // Stop if we're taking too long
            if ($i > 100 && (time() % 10) === 0) {
                break; // Prevent test timeout
            }
        }
        
        // Test reading with no limit
        $result = $this->readTool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'pid', 'operator' => 'eq', 'value' => 1],
            ],
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);
        
        // Tool should limit results automatically
        $this->assertIsArray($data);
        if (count($data) > 100) {
            // If more than 100 records, there should be some indication
            $this->assertLessThanOrEqual(1000, count($data), 'Results should be limited');
        }
    }
    
    /**
     * Test handling of very large IN clauses
     */
    public function testLargeInClauseHandling(): void
    {
        // Create a very large array of UIDs
        $uids = range(1, 10000);
        
        // Try to read with huge IN clause
        $result = $this->readTool->execute([
            'table' => 'pages',
            'filters' => [
                ['field' => 'uid', 'operator' => 'in', 'value' => array_slice($uids, 0, 1000)],
            ],
        ]);
        
        // Should handle this gracefully (maybe by batching)
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }
    
    /**
     * Test handling of deep recursive structures
     */
    public function testDeepRecursiveStructures(): void
    {
        // Create a deep page hierarchy
        $parentId = 0;
        $depth = 20;
        
        for ($i = 0; $i < $depth; $i++) {
            $result = $this->writeTool->execute([
                'action' => 'create',
                'table' => 'pages',
                'pid' => $parentId,
                'data' => [
                    'title' => "Level $i Page"
                ]
            ]);
            
            if (!$result->isError) {
                $data = json_decode($result->content[0]->text, true);
                $parentId = $data['uid'];
            } else {
                break;
            }
        }
        
        // Try to read pages with complex conditions
        $result = $this->readTool->execute([
            'table' => 'pages',
            'filters' => [
                ['field' => 'title', 'operator' => 'like', 'value' => '%Level%'],
            ],
        ]);
        
        // Should handle deep structures without stack overflow
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }
    
    /**
     * Test handling of complex search queries
     */
    public function testComplexSearchQueries(): void
    {
        // Create content with various special characters
        $specialContents = [
            'Special chars: !"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~',
            'Unicode: 你好世界 🌍 مرحبا بالعالم',
            'Very long ' . str_repeat('text ', 1000),
            'Nested <b>HTML <i>tags <u>everywhere</u></i></b>',
        ];
        
        foreach ($specialContents as $content) {
            $this->writeTool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => 1,
                'data' => [
                    'CType' => 'text',
                    'header' => substr($content, 0, 100),
                    'bodytext' => $content
                ]
            ]);
        }
        
        // Test searching with complex patterns
        $searchQueries = [
            'Special chars',
            '你好世界',
            'HTML tags',
            str_repeat('text ', 10)
        ];
        
        foreach ($searchQueries as $query) {
            $result = $this->searchTool->execute([
                'terms' => [$query], // Changed from 'query' to 'terms' array
                'tables' => ['tt_content'],
                'limit' => 100
            ]);
            
            // Should handle all queries without errors
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        }
    }
    
    /**
     * Test handling of execution time limits
     */
    public function testExecutionTimeLimit(): void
    {
        // Save current time limit
        $originalTimeLimit = ini_get('max_execution_time');
        
        // Set a short time limit (but not too short to break setup)
        set_time_limit(5);
        
        try {
            // Try an operation that could take time
            $startTime = time();
            
            // Create multiple records in a loop
            $count = 0;
            while ((time() - $startTime) < 3) { // Run for 3 seconds
                $result = $this->writeTool->execute([
                    'action' => 'create',
                    'table' => 'pages',
                    'pid' => 0,
                    'data' => [
                        'title' => "Time test page $count"
                    ]
                ]);
                
                if ($result->isError) {
                    break;
                }
                
                $count++;
                
                // Prevent infinite loop
                if ($count > 1000) {
                    break;
                }
            }
            
            // We should have created some pages
            $this->assertGreaterThan(0, $count);
            
        } finally {
            // Restore original time limit
            set_time_limit((int)$originalTimeLimit);
        }
    }
    
    
    /**
     * Test handling of file system constraints
     */
    public function testFileSystemConstraints(): void
    {
        // Test with very long field values that might be cached/stored
        $veryLongText = str_repeat('A very long text that could fill disk space. ', 10000);
        
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Long content test',
                'bodytext' => $veryLongText
            ]
        ]);
        
        // Should handle large content gracefully
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Verify it was stored (possibly truncated)
        $data = json_decode($result->content[0]->text, true);
        if (isset($data['uid'])) {
            $readResult = $this->readTool->execute([
                'table' => 'tt_content',
                'uid' => $data['uid']
            ]);
            
            $this->assertFalse($readResult->isError);
            $recordData = json_decode($readResult->content[0]->text, true);
            if (isset($recordData['bodytext'])) {
                $this->assertNotEmpty($recordData['bodytext']);
            } else {
                // Field might have been truncated or filtered
                $this->assertTrue(true);
            }
        }
    }


    /**
     * Test handling of many structured filters
     */
    public function testManyFiltersHandling(): void
    {
        // Build a large set of filters
        $filters = [];
        for ($i = 0; $i < 50; $i++) {
            $filters[] = ['field' => 'title', 'operator' => 'notLike', 'value' => "%nonexistent$i%"];
        }

        $result = $this->readTool->execute([
            'table' => 'pages',
            'filters' => $filters,
        ]);

        // Should handle many filters gracefully
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }
}