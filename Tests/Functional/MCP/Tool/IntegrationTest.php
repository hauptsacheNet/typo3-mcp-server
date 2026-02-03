<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Hn\McpServer\Service\WorkspaceContextService;

class IntegrationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        
        // Set up backend user
        $this->setUpBackendUser(1);
        
        // Initialize workspace context once for all tests
        $workspaceContextService = GeneralUtility::makeInstance(WorkspaceContextService::class);
        $workspaceContextService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
    }

    /**
     * Test basic CRUD flow with workspace transparency
     * Scenario: Create content, read it, update it, and search for it - all using the same UID
     */
    public function testBasicCrudFlowWithWorkspaceTransparency(): void
    {
        $writeTool = new WriteTableTool();
        $readTool = new ReadTableTool();
        $searchTool = new SearchTool();
        
        
        // Step 1: Create a content element
        $createResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Workspace Transparency Test',
                'bodytext' => 'This content tests workspace transparency',
            ]
        ]);
        
        $this->assertFalse($createResult->isError);
        $createData = json_decode($createResult->content[0]->text, true);
        $liveUid = $createData['uid'];
        
        
        // The returned UID should be a positive integer
        $this->assertIsInt($liveUid);
        $this->assertGreaterThan(0, $liveUid);
        
        // Step 2: Read the record using the same UID
        $readResult = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $liveUid,
        ]);
        
        $this->assertFalse($readResult->isError);
        $readData = json_decode($readResult->content[0]->text, true);
        
        // Should find exactly one record
        $this->assertCount(1, $readData['records']);
        $record = $readData['records'][0];
        
        // The UID should match what we requested
        $this->assertEquals($liveUid, $record['uid']);
        $this->assertEquals('Workspace Transparency Test', $record['header']);
        
        // Step 3: Update the record using the same UID
        $updateResult = $writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uids' => [$liveUid],
            'data' => [
                'header' => 'Updated Workspace Test',
                'bodytext' => 'Content has been updated transparently',
            ]
        ]);
        
        $this->assertFalse($updateResult->isError);
        $updateData = json_decode($updateResult->content[0]->text, true);

        // The returned UID should still be the same (now in succeeded array for batch operations)
        $this->assertContains($liveUid, $updateData['succeeded']);
        
        // Step 4: Read again to verify the update
        $readAgainResult = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $liveUid,
        ]);
        
        $this->assertFalse($readAgainResult->isError);
        $readAgainData = json_decode($readAgainResult->content[0]->text, true);
        $updatedRecord = $readAgainData['records'][0];
        
        
        // Should see the updated content
        $this->assertEquals('Updated Workspace Test', $updatedRecord['header']);
        $this->assertEquals('Content has been updated transparently', $updatedRecord['bodytext']);
        
        // Step 5: Search for the content
        $searchResult = $searchTool->execute([
            'terms' => ['Updated Workspace Test'],
        ]);
        
        $this->assertFalse($searchResult->isError);
        $searchOutput = $searchResult->content[0]->text;
        
        // Should find the record with the same UID
        $this->assertStringContainsString('[UID: ' . $liveUid . ']', $searchOutput);
        $this->assertStringContainsString('Updated Workspace Test', $searchOutput);
    }

    /**
     * Test reference workflow with workspace transparency
     * Scenario: Create pages and content that reference each other using live UIDs
     */
    public function testReferenceWorkflowWithWorkspaceTransparency(): void
    {
        $writeTool = new WriteTableTool();
        $readTool = new ReadTableTool();
        
        // Step 1: Create a parent page
        $pageResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 1,
            'data' => [
                'title' => 'Reference Test Page',
                'slug' => '/reference-test',
                'doktype' => 1,
            ]
        ]);
        
        $this->assertFalse($pageResult->isError);
        $pageData = json_decode($pageResult->content[0]->text, true);
        $pageUid = $pageData['uid'];
        
        // Step 2: Create content on the new page using its UID
        $contentResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pageUid, // Using the page UID directly
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Content on Reference Page',
                'bodytext' => 'This content is on page ' . $pageUid,
            ]
        ]);
        
        $this->assertFalse($contentResult->isError);
        $contentData = json_decode($contentResult->content[0]->text, true);
        $contentUid = $contentData['uid'];
        
        // Step 3: Read all content on the page
        $pageContentResult = $readTool->execute([
            'table' => 'tt_content',
            'pid' => $pageUid,
        ]);
        
        $this->assertFalse($pageContentResult->isError);
        $pageContentData = json_decode($pageContentResult->content[0]->text, true);
        
        // Should find the content we created
        $this->assertGreaterThan(0, $pageContentData['total']);
        $foundContent = false;
        foreach ($pageContentData['records'] as $record) {
            if ($record['uid'] == $contentUid) {
                $foundContent = true;
                $this->assertEquals('Content on Reference Page', $record['header']);
            }
        }
        $this->assertTrue($foundContent, 'Created content should be found on the page');
        
        // Step 4: Create another content element that references the first
        $refContentResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pageUid,
            'position' => 'after:' . $contentUid, // Position after the first content
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Reference to Content ' . $contentUid,
                'bodytext' => 'This content is positioned after UID ' . $contentUid,
            ]
        ]);
        
        $this->assertFalse($refContentResult->isError);
    }

    /**
     * Test handling of new records without live versions
     * Scenario: Create new content and verify it can be accessed with consistent UIDs
     */
    public function testNewRecordHandling(): void
    {
        $writeTool = new WriteTableTool();
        $readTool = new ReadTableTool();
        $searchTool = new SearchTool();
        
        // Create a new page (which won't have a live version initially)
        $pageResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Brand New Page',
                'slug' => '/brand-new',
                'doktype' => 1,
                'hidden' => 0, // Explicitly set as visible
            ]
        ]);
        
        $this->assertFalse($pageResult->isError);
        $pageData = json_decode($pageResult->content[0]->text, true);
        $pageUid = $pageData['uid'];
        
        // The UID should be stable and usable
        $this->assertIsInt($pageUid);
        $this->assertGreaterThan(0, $pageUid);
        
        // Read the page back
        $readResult = $readTool->execute([
            'table' => 'pages',
            'uid' => $pageUid,
        ]);
        
        $this->assertFalse($readResult->isError);
        $readData = json_decode($readResult->content[0]->text, true);
        
        // Should find the page with the same UID
        $this->assertCount(1, $readData['records']);
        $this->assertEquals($pageUid, $readData['records'][0]['uid']);
        $this->assertEquals('Brand New Page', $readData['records'][0]['title']);
        
        // Search for the new page
        $searchResult = $searchTool->execute([
            'terms' => ['Brand New Page'],
            'table' => 'pages',
        ]);
        
        $this->assertFalse($searchResult->isError);
        $searchOutput = $searchResult->content[0]->text;
        
        // Should find the page with the stable UID
        $this->assertStringContainsString('[UID: ' . $pageUid . ']', $searchOutput);
        $this->assertStringContainsString('Brand New Page', $searchOutput);
    }

    /**
     * Test delete operation with workspace transparency
     */
    public function testDeleteWithWorkspaceTransparency(): void
    {
        $writeTool = new WriteTableTool();
        $readTool = new ReadTableTool();
        $searchTool = new SearchTool();
        
        // Create content to delete
        $createResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Content to Delete',
            ]
        ]);
        
        $this->assertFalse($createResult->isError);
        $createData = json_decode($createResult->content[0]->text, true);
        $uid = $createData['uid'];
        
        // Delete using the same UID
        $deleteResult = $writeTool->execute([
            'action' => 'delete',
            'table' => 'tt_content',
            'uids' => [$uid],
        ]);

        $this->assertFalse($deleteResult->isError);
        $deleteData = json_decode($deleteResult->content[0]->text, true);

        // Should return the same UID (now in succeeded array for batch operations)
        $this->assertContains($uid, $deleteData['succeeded']);
        
        // Try to read the deleted record
        $readResult = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $uid,
        ]);
        
        $this->assertFalse($readResult->isError);
        $readData = json_decode($readResult->content[0]->text, true);
        
        // Should not find the deleted record
        $this->assertCount(0, $readData['records']);
        
        // Verify the deleted record doesn't appear in search results
        $searchResult = $searchTool->execute([
            'terms' => ['Content to Delete'],
        ]);
        
        $this->assertFalse($searchResult->isError);
        $searchOutput = $searchResult->content[0]->text;
        
        // Should not find the deleted record (check for UID in brackets to avoid false positive from search query display)
        $this->assertStringNotContainsString('[UID: ' . $uid . ']', $searchOutput);
        // Total results should be 0
        $this->assertStringContainsString('Total Results: 0', $searchOutput);
    }
    
    /**
     * Test deleting existing live records in workspace (delete placeholder)
     * This tests the scenario where a live record exists and is marked for deletion in a workspace
     */
    public function testDeleteExistingLiveRecordInWorkspace(): void
    {
        $writeTool = new WriteTableTool();
        $readTool = new ReadTableTool();
        $searchTool = new SearchTool();
        
        // Use an existing live record from fixtures (UID 100)
        $liveUid = 100;
        
        // First, verify the record exists and read its content
        $initialReadResult = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $liveUid,
        ]);
        
        $this->assertFalse($initialReadResult->isError);
        $initialData = json_decode($initialReadResult->content[0]->text, true);
        $this->assertCount(1, $initialData['records']);
        $this->assertEquals('Welcome Header', $initialData['records'][0]['header']);
        
        // Delete the live record from within the workspace
        $deleteResult = $writeTool->execute([
            'action' => 'delete',
            'table' => 'tt_content',
            'uids' => [$liveUid],
        ]);

        $this->assertFalse($deleteResult->isError);
        $deleteData = json_decode($deleteResult->content[0]->text, true);

        // Should return the live UID for transparency (now in succeeded array for batch operations)
        $this->assertContains($liveUid, $deleteData['succeeded']);
        
        // Try to read the record again - it should not be found in workspace context
        $readAfterDeleteResult = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $liveUid,
        ]);
        
        $this->assertFalse($readAfterDeleteResult->isError);
        $readAfterDeleteData = json_decode($readAfterDeleteResult->content[0]->text, true);
        
        // In workspace context, the record should appear as deleted (not found)
        $this->assertCount(0, $readAfterDeleteData['records']);
        
        // Search should also not find the deleted record in workspace
        $searchResult = $searchTool->execute([
            'terms' => ['Welcome Header'],
        ]);
        
        $this->assertFalse($searchResult->isError);
        $searchOutput = $searchResult->content[0]->text;
        
        // Should not find the deleted record in search
        $this->assertStringNotContainsString('[UID: ' . $liveUid . ']', $searchOutput);
        
        // Verify a delete placeholder was created in the database
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        
        $queryBuilder->getRestrictions()->removeAll();
        
        // Get the current workspace ID dynamically
        $workspaceContextService = GeneralUtility::makeInstance(WorkspaceContextService::class);
        $currentWorkspaceId = $workspaceContextService->getCurrentWorkspace();
        
        // Look for delete placeholder (t3ver_state = 2)
        $deletePlaceholder = $queryBuilder
            ->select('uid', 't3ver_oid', 't3ver_state', 't3ver_wsid', 'header', 'deleted')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter(2, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($currentWorkspaceId, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        // A delete placeholder should have been created
        $this->assertNotFalse($deletePlaceholder, 'Delete placeholder should exist');
        $this->assertEquals(2, $deletePlaceholder['t3ver_state'], 'Should be a delete placeholder (t3ver_state = 2)');
        $this->assertEquals($liveUid, $deletePlaceholder['t3ver_oid'], 'Delete placeholder should reference the live record');
        // Note: Delete placeholders don't necessarily have deleted=1, the t3ver_state=2 is what marks them as delete placeholders
        
        // The original live record should still exist and be unchanged
        $liveRecord = $queryBuilder
            ->select('uid', 'header', 'deleted', 't3ver_wsid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($liveUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        $this->assertNotFalse($liveRecord, 'Live record should still exist');
        $this->assertEquals(0, $liveRecord['deleted'], 'Live record should not be deleted');
        $this->assertEquals('Welcome Header', $liveRecord['header'], 'Live record should be unchanged');
    }

    /**
     * Test that workspace versions are never exposed
     * Verify that all operations consistently use live UIDs
     */
    public function testWorkspaceUidsNeverExposed(): void
    {
        $writeTool = new WriteTableTool();
        
        // Create multiple records
        $uids = [];
        
        for ($i = 1; $i <= 3; $i++) {
            $result = $writeTool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => 1,
                'data' => [
                    'CType' => 'textmedia',
                    'header' => 'Test Content ' . $i,
                ]
            ]);
            
            $this->assertFalse($result->isError);
            $data = json_decode($result->content[0]->text, true);
            $uids[] = $data['uid'];
        }
        
        // All UIDs should be unique and positive
        $this->assertCount(3, array_unique($uids));
        foreach ($uids as $uid) {
            $this->assertIsInt($uid);
            $this->assertGreaterThan(0, $uid);
        }
        
        // Verify that none of these are workspace UIDs by checking the database
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        
        $queryBuilder->getRestrictions()->removeAll();
        
        // Look for records with these UIDs in live workspace
        $liveRecords = $queryBuilder
            ->select('uid', 't3ver_wsid', 't3ver_state')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in('uid', $uids),
                $queryBuilder->expr()->eq('t3ver_wsid', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();
        
        // These should be placeholders (t3ver_state = 1) or live records
        foreach ($liveRecords as $record) {
            $this->assertTrue(
                $record['t3ver_state'] == 1 || $record['t3ver_wsid'] == 0,
                'Returned UIDs should be live UIDs or placeholders, not workspace UIDs'
            );
        }
    }
}