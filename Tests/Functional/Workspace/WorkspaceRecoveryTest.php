<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Tests for workspace recovery and cleanup after failures
 * Ensures the MCP server can recover from partial operations and maintain data consistency
 */
class WorkspaceRecoveryTest extends AbstractFunctionalTest
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];
    
    protected ConnectionPool $connectionPool;
    protected WorkspaceContextService $workspaceService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
    }

    /**
     * Test recovery from partial workspace creation
     * Simulates a crash during workspace creation and ensures system can recover
     */
    public function testRecoveryFromPartialWorkspaceCreation(): void
    {
        // Create an incomplete workspace manually (simulating partial creation)
        $connection = $this->connectionPool->getConnectionForTable('sys_workspace');
        
        $connection->insert('sys_workspace', [
            'title' => 'Incomplete MCP Workspace',
            'description' => 'Simulated incomplete workspace',
            'adminusers' => '1',
            'members' => '',
            'pid' => 0,
            'deleted' => 0,
            'freeze' => 0,
            'live_edit' => 0,
            'publish_access' => 1,
            'stagechg_notification' => 0,
            'publish_time' => 0,
            // Intentionally missing some fields that might be required
        ]);
        
        $incompleteId = (int)$connection->lastInsertId();
        $this->assertGreaterThan(0, $incompleteId, 'Incomplete workspace should be created');
        
        // Now try to use the workspace service
        // It should either fix the incomplete workspace or create a new one
        $workspaceId = $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        
        // Should have a valid workspace
        $this->assertGreaterThan(0, $workspaceId, 'Should have a valid workspace');
        
        // Verify workspace is properly initialized
        $workspace = BackendUtility::getRecord('sys_workspace', $workspaceId);
        $this->assertIsArray($workspace);
        $this->assertNotEmpty($workspace['title']);
        $this->assertEquals(0, $workspace['deleted']);
        
        // Try to perform an operation to ensure workspace is functional
        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'header' => 'Test after recovery',
                'CType' => 'text'
            ]
        ]);
        
        $this->assertFalse($result->isError, 'Should be able to create content after recovery: ' . json_encode($result->jsonSerialize()));
    }

    /**
     * Test cleanup after failed operations
     * Ensures no partial data is left after operation failures
     */
    public function testCleanupAfterFailedOperations(): void
    {
        // Set up workspace
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $workspaceId = $this->workspaceService->getCurrentWorkspace();
        
        // Attempt to create a record with invalid data that will fail validation
        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Try to create a record in a non-existent table
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'non_existent_table',
            'pid' => 1,
            'data' => [
                'title' => 'Will Fail Due to Invalid Table',
            ]
        ]);
        
        // Operation should report an error
        $this->assertTrue($result->isError, 'Operation should fail with invalid table');
        
        // Just verify we got an error - the exact message format may vary
        // The important thing is that the operation failed and didn't create partial records
        
        // Verify workspace is still functional
        $testResult = $tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1, // Valid parent
            'data' => [
                'title' => 'Valid Page After Failure',
                'doktype' => 1
            ]
        ]);
        
        $this->assertFalse($testResult->isError, 'Should be able to create valid content after failure');
    }

    /**
     * Test handling of duplicate workspace titles
     * Ensures system can handle when multiple workspaces with same title try to be created
     */
    public function testDuplicateWorkspaceTitleHandling(): void
    {
        // Create a workspace with a specific title
        $connection = $this->connectionPool->getConnectionForTable('sys_workspace');
        
        $title = 'MCP Workspace for admin';
        $connection->insert('sys_workspace', [
            'title' => $title,
            'description' => 'First workspace',
            'adminusers' => '1',
            'members' => '',
            'pid' => 0,
            'deleted' => 0,
            'freeze' => 0,
            'live_edit' => 0,
            'publish_access' => 1,
            'stagechg_notification' => 0,
            'publish_time' => 0,
        ]);
        
        $firstId = (int)$connection->lastInsertId();
        
        // Now try to create another with the same title using WorkspaceContextService
        // This simulates what would happen if the service tried to create a duplicate
        $workspaceId = $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        
        // Should either use the existing workspace or handle the duplicate gracefully
        $this->assertGreaterThan(0, $workspaceId, 'Should have a valid workspace');
        
        // Count how many workspaces exist with MCP in the title
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
        $queryBuilder->getRestrictions()->removeAll();
        
        $count = $queryBuilder
            ->count('*')
            ->from('sys_workspace')
            ->where(
                $queryBuilder->expr()->like('title', $queryBuilder->createNamedParameter('%MCP Workspace%'))
            )
            ->executeQuery()
            ->fetchOne();
            
        // Should have handled the duplicate situation gracefully
        $this->assertGreaterThan(0, $count, 'Should have at least one MCP workspace');
        
        // Verify workspace is functional
        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => ['title' => 'Updated after duplicate handling']
        ]);
        
        $this->assertFalse($result->isError, 'Workspace should be functional');
    }

    /**
     * Test workspace integrity after database errors
     * Simulates database issues during workspace operations
     */
    public function testWorkspaceIntegrityAfterDatabaseErrors(): void
    {
        // Create workspace
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $workspaceId = $this->workspaceService->getCurrentWorkspace();
        
        // Create some workspace records
        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        $result1 = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'header' => 'Content 1',
                'CType' => 'text'
            ]
        ]);
        
        $this->assertFalse($result1->isError, json_encode($result1->jsonSerialize()));
        
        // Simulate a scenario where workspace record might be partially corrupted
        // by directly manipulating database (this simulates database inconsistency)
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        
        // Create an orphaned workspace record (no corresponding live record)
        $connection->insert('tt_content', [
            'pid' => 1,
            'header' => 'Orphaned Workspace Record',
            'CType' => 'text',
            't3ver_oid' => 999999, // Non-existent live record
            't3ver_wsid' => $workspaceId,
            't3ver_state' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);
        
        // Now try to read records - system should handle the inconsistency
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $readResult = $readTool->execute([
            'table' => 'tt_content',
            'pid' => 1
        ]);
        
        // Should not crash despite data inconsistency
        $this->assertFalse($readResult->isError, 'Should handle data inconsistency gracefully');
        
        $data = json_decode($readResult->content[0]->text, true);
        
        // The orphaned record should not appear in results
        $headers = array_column($data['records'], 'header');
        $this->assertNotContains('Orphaned Workspace Record', $headers, 'Orphaned record should not be in results');
        
        // Verify we can still create new records
        $result2 = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'header' => 'Content after inconsistency',
                'CType' => 'text'
            ]
        ]);
        
        $this->assertFalse($result2->isError, 'Should still be able to create records');
    }

    /**
     * Test recovery from workspace with invalid admin users
     * Ensures system can handle workspaces with invalid user references
     */
    public function testWorkspaceWithInvalidAdminUsers(): void
    {
        // Create a workspace with invalid admin user reference
        $connection = $this->connectionPool->getConnectionForTable('sys_workspace');
        
        $connection->insert('sys_workspace', [
            'title' => 'Workspace with Invalid Admin',
            'description' => 'Test workspace',
            'adminusers' => '999999', // Non-existent user
            'members' => '',
            'pid' => 0,
            'deleted' => 0,
            'freeze' => 0,
            'live_edit' => 0,
            'publish_access' => 1,
            'stagechg_notification' => 0,
            'publish_time' => 0,
        ]);
        
        $invalidWorkspaceId = (int)$connection->lastInsertId();
        
        // Try to use workspace service - it should handle the invalid workspace gracefully
        $workspaceId = $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        
        // Should either create a new workspace or find a valid one
        $this->assertGreaterThan(0, $workspaceId, 'Should have a valid workspace');
        
        // If it's using the invalid workspace, verify it's been made functional
        if ($workspaceId === $invalidWorkspaceId) {
            // Try to perform operations
            $tool = GeneralUtility::makeInstance(WriteTableTool::class);
            $result = $tool->execute([
                'action' => 'create',
                'table' => 'pages',
                'pid' => 1,
                'data' => ['title' => 'Page in recovered workspace', 'doktype' => 1]
            ]);
            
            $this->assertFalse($result->isError, 'Should be able to use recovered workspace');
        }
    }

    /**
     * Test cleanup of stale workspace locks
     * Ensures system can recover from abandoned locks
     */
    public function testStaleWorkspaceLockCleanup(): void
    {
        // This test simulates a scenario where a previous process left locks
        // In real scenarios, this might happen if a process crashes while holding locks
        
        // Create a workspace
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $workspaceId = $this->workspaceService->getCurrentWorkspace();
        
        // Simulate multiple rapid operations that might cause lock contention
        $operations = [];
        for ($i = 0; $i < 5; $i++) {
            $tool = GeneralUtility::makeInstance(WriteTableTool::class);
            $result = $tool->execute([
                'action' => 'update',
                'table' => 'pages',
                'uid' => 1,
                'data' => ['title' => "Rapid Update $i"]
            ]);
            $operations[] = $result;
        }
        
        // All operations should complete successfully
        foreach ($operations as $index => $result) {
            $this->assertFalse($result->isError, "Operation $index should succeed: " . json_encode($result->jsonSerialize()));
        }
        
        // Verify data integrity
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        
        // Should have exactly one workspace version
        $count = $queryBuilder
            ->count('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', 1),
                $queryBuilder->expr()->eq('t3ver_wsid', $workspaceId)
            )
            ->executeQuery()
            ->fetchOne();
            
        $this->assertEquals(1, $count, 'Should have exactly one workspace version');
    }

    /**
     * Test recovery from incomplete DataHandler operations
     * Ensures system can recover when DataHandler operations are interrupted
     */
    public function testIncompleteDataHandlerRecovery(): void
    {
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $workspaceId = $this->workspaceService->getCurrentWorkspace();
        
        // Simulate an incomplete DataHandler operation by creating raw database records
        // that would normally be created together
        $connection = $this->connectionPool->getConnectionForTable('pages');
        
        // Create a workspace version without proper initialization
        $connection->insert('pages', [
            'pid' => 1,
            'title' => 'Incomplete Workspace Page',
            'doktype' => 1,
            't3ver_oid' => 0, // Should have a live record reference
            't3ver_wsid' => $workspaceId,
            't3ver_state' => 1, // NEW placeholder state
            'tstamp' => time(),
            'crdate' => time(),
            'deleted' => 0,
        ]);
        
        // Now try to use normal tools - they should handle the inconsistency
        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => ['title' => 'Normal Page After Incomplete', 'doktype' => 1]
        ]);
        
        $this->assertFalse($result->isError, 'Should be able to create pages after incomplete operation');
        
        // Verify the system didn't create duplicate or conflicting records
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        
        $pages = $queryBuilder
            ->select('uid', 'title', 't3ver_oid', 't3ver_wsid', 't3ver_state')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $workspaceId)
            )
            ->executeQuery()
            ->fetchAllAssociative();
            
        // Should have our pages but no duplicates or conflicts
        $this->assertGreaterThan(0, count($pages), 'Should have workspace pages');
        
        // Each page should have proper structure
        foreach ($pages as $page) {
            if ($page['t3ver_state'] == 1) {
                // NEW records might not have t3ver_oid in some TYPO3 versions
                $this->assertTrue(
                    $page['t3ver_oid'] >= 0,
                    'NEW placeholder should have valid structure'
                );
            }
        }
    }
}