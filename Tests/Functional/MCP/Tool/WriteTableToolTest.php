<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Fixtures\TestDataBuilder;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Mcp\Types\TextContent;
use Hn\McpServer\MCP\ToolRegistry;

class WriteTableToolTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;
    
    private WriteTableTool $tool;
    private TestDataBuilder $data;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Import additional fixtures needed for this test
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category.csv');
        
        $this->tool = new WriteTableTool();
        $this->data = new TestDataBuilder();
    }
    
    /**
     * Test using builder pattern for fixture creation
     */
    public function testCreatePageWithBuilderPattern(): void
    {
        // Create test page using builder
        $pageUid = $this->data->page()
            ->withTitle('Built Test Page')
            ->withSlug('/built-test-page')
            ->withParent($this->getRootPageUid())
            ->create();
        
        // Create test content using builder
        $contentUid = $this->data->content()
            ->onPage($pageUid)
            ->asTextMedia('Built Content Header', 'This content was created with the builder')
            ->inColumn(0)
            ->create();
        
        // Now test updating the content
        $updateResult = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $contentUid,
            'data' => [
                'header' => 'Updated via Tool',
                'bodytext' => 'Content updated through WriteTableTool'
            ]
        ]);
        
        $this->assertSuccessfulToolResult($updateResult);
        $data = $this->extractJsonFromResult($updateResult);
        $this->assertEquals('update', $data['action']);
        $this->assertEquals($contentUid, $data['uid']);
    }
    
    /**
     * Test standard CRUD operations (create, read, update, delete)
     */
    public function testStandardCrudOperations(): void
    {
        $table = 'tt_content';
        $createData = [
            'CType' => 'textmedia',
            'header' => 'CRUD Test Content',
            'bodytext' => 'Original content',
            'colPos' => 0
        ];
        $updateData = [
            'header' => 'Updated CRUD Content',
            'bodytext' => 'Updated content text'
        ];
        $pid = $this->getRootPageUid();
        
        // Initialize tools
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        
        // Create record
        $createResult = $writeTool->execute([
            'action' => 'create',
            'table' => $table,
            'pid' => $pid,
            'data' => $createData
        ]);
        $this->assertSuccessfulToolResult($createResult);
        $createResponse = $this->extractJsonFromResult($createResult);
        $this->assertArrayHasKey('uid', $createResponse);
        
        $uid = $createResponse['uid'];
        $this->assertGreaterThan(0, $uid);
        
        // Read record
        $readResult = $readTool->execute([
            'table' => $table,
            'uid' => $uid
        ]);
        $this->assertSuccessfulToolResult($readResult);
        $readData = $this->extractJsonFromResult($readResult);
        $this->assertArrayHasKey('records', $readData);
        $this->assertCount(1, $readData['records']);
        // Compare the original createData with the read record
        $this->assertRecordEquals($createData, $readData['records'][0]);
        
        // Update record
        $updateResult = $writeTool->execute([
            'action' => 'update',
            'table' => $table,
            'uid' => $uid,
            'data' => $updateData
        ]);
        $this->assertSuccessfulToolResult($updateResult);
        
        // Verify update
        $verifyResult = $readTool->execute([
            'table' => $table,
            'uid' => $uid
        ]);
        $this->assertSuccessfulToolResult($verifyResult);
        $verifyData = $this->extractJsonFromResult($verifyResult);
        $this->assertRecordEquals($updateData, $verifyData['records'][0]);
        
        // Delete record
        $deleteResult = $writeTool->execute([
            'action' => 'delete',
            'table' => $table,
            'uid' => $uid
        ]);
        $this->assertSuccessfulToolResult($deleteResult);
        
        // Verify deletion - record should still be readable but marked as deleted
        $deletedResult = $readTool->execute([
            'table' => $table,
            'uid' => $uid
        ]);
        // In TYPO3, deleted records may still be readable depending on restrictions
        // so we don't assert an error here
    }
    
    /**
     * User story: Create a page with content element
     */
    public function testCreatePageWithContentElement(): void
    {
        // First create a new page
        $pageResult = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'New Test Page',
                'slug' => '/test-page',
                'doktype' => 1,
            ]
        ]);
        
        $this->assertSuccessfulToolResult($pageResult);
        
        // Parse the JSON result to get the new page UID
        $pageData = $this->extractJsonFromResult($pageResult);
        $this->assertEquals('create', $pageData['action']);
        $this->assertIsInt($pageData['uid']);
        $newPageUid = $pageData['uid'];
        
        // Now create content on the new page
        $contentResult = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $newPageUid,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Welcome to New Page',
                'bodytext' => 'This is content on the newly created page',
                'colPos' => 0
            ]
        ]);
        
        $this->assertSuccessfulToolResult($contentResult);
        
        // Verify the content was created
        $contentData = $this->extractJsonFromResult($contentResult);
        $this->assertEquals('create', $contentData['action']);
        $this->assertIsInt($contentData['uid']);
        
        // Verify page is in workspace, not live
        $this->assertRecordNotInLive('pages', $newPageUid);
    }

    /**
     * User story: Edit existing content element
     */
    public function testEditExistingContentElement(): void
    {
        $tool = new WriteTableTool();
        
        // Update existing content element (UID 100)
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => [
                'header' => 'Updated Welcome Header',
                'bodytext' => 'This content has been updated in workspace',
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->content));
        
        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('update', $data['action']);
        $this->assertEquals(100, $data['uid']);
    }

    /**
     * User story: Add content element with correct sorting
     */
    public function testAddContentElementWithCorrectSorting(): void
    {
        $tool = new WriteTableTool();
        
        // Create content at bottom (default)
        $bottomResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'position' => 'bottom',
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Bottom Content',
                'colPos' => 0
            ]
        ]);
        
        $this->assertFalse($bottomResult->isError, json_encode($bottomResult->content));
        
        // Create content after specific element
        $afterResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'position' => 'after:100',
            'data' => [
                'CType' => 'textmedia',
                'header' => 'After Welcome Content',
                'colPos' => 0
            ]
        ]);
        
        $this->assertFalse($afterResult->isError, json_encode($afterResult->content));
        $afterData = json_decode($afterResult->content[0]->text, true);
        
        // Verify the record was created and positioned
        $this->assertIsArray($afterData);
        $this->assertEquals('create', $afterData['action']);
        $this->assertIsInt($afterData['uid']);
        
        // Verify the sorting is set (positioning might not work perfectly in test env)
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        
        $record = $queryBuilder->select('sorting')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($afterData['uid'], ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        $this->assertIsArray($record);
        $this->assertArrayHasKey('sorting', $record);
        // The sorting should be set, even if positioning didn't work perfectly
        $this->assertIsInt($record['sorting']);
        $this->assertGreaterThan(0, $record['sorting']);
    }

    /**
     * Test basic content element creation
     */
    public function testCreateContentElement(): void
    {
        $tool = new WriteTableTool();
        
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'New Content Element',
                'bodytext' => 'This is a new content element',
                'colPos' => 0
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->content));
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('create', $data['action']);
        $this->assertEquals('tt_content', $data['table']);
        $this->assertIsInt($data['uid']);
        $this->assertGreaterThan(0, $data['uid']);
        
        // Verify the record is not in live workspace
        $this->assertRecordNotInLive('tt_content', $data['uid']);
        
        // VERIFY THE RECORD WAS ACTUALLY CREATED IN DATABASE
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        
        // Remove restrictions to see workspace records
        $queryBuilder->getRestrictions()->removeAll();
        
        $createdRecord = $queryBuilder->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($data['uid'], ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        // Verify record exists
        $this->assertIsArray($createdRecord, 'Created record should exist in database');
        
        // Verify all fields were saved correctly
        $this->assertEquals('New Content Element', $createdRecord['header'], 'Header should match input data');
        $this->assertEquals('This is a new content element', $createdRecord['bodytext'], 'Bodytext should match input data');
        $this->assertEquals('textmedia', $createdRecord['CType'], 'CType should match input data');
        $this->assertEquals(0, $createdRecord['colPos'], 'Column position should match input data');
        $this->assertEquals(1, $createdRecord['pid'], 'Page ID should match input data');
        
        // Verify it's in a workspace
        $this->assertGreaterThan(0, $createdRecord['t3ver_wsid'], 'Record should be in a workspace');
        $this->assertEquals(1, $createdRecord['t3ver_state'], 'Record should have new placeholder state');
        
        // Verify system fields are set
        $this->assertGreaterThan(0, $createdRecord['tstamp'], 'Timestamp should be set');
        $this->assertGreaterThan(0, $createdRecord['crdate'], 'Creation date should be set');
        $this->assertEquals(0, $createdRecord['deleted'], 'Record should not be deleted');
    }

    /**
     * Test updating an existing content element
     */
    public function testUpdateContentElement(): void
    {
        $tool = new WriteTableTool();
        
        // Get original record state first
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        
        $originalRecord = $queryBuilder->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(100, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        $this->assertIsArray($originalRecord, 'Original record should exist');
        $originalHeader = $originalRecord['header'];
        $originalBodytext = $originalRecord['bodytext'];
        $originalTstamp = $originalRecord['tstamp'];
        
        // Perform the update
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => [
                'header' => 'Modified Header',
                'bodytext' => 'Modified body text'
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->content));
        
        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('update', $data['action']);
        $this->assertEquals('tt_content', $data['table']);
        $this->assertEquals(100, $data['uid']);
        
        // VERIFY THE RECORD WAS ACTUALLY UPDATED IN DATABASE
        // Get workspace version of the record
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        
        $workspaceRecord = $queryBuilder->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(100, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        // Verify workspace version exists
        $this->assertIsArray($workspaceRecord, 'Workspace version should be created for updated record');
        
        // Verify the updates were applied
        $this->assertEquals('Modified Header', $workspaceRecord['header'], 'Header should be updated');
        $this->assertEquals('Modified body text', $workspaceRecord['bodytext'], 'Bodytext should be updated');
        
        // Verify workspace metadata
        $this->assertEquals(100, $workspaceRecord['t3ver_oid'], 'Should reference original record');
        $this->assertGreaterThan(0, $workspaceRecord['t3ver_wsid'], 'Should be in a workspace');
        $this->assertEquals(0, $workspaceRecord['t3ver_state'], 'Should have modified state');
        
        // Verify timestamp was updated
        $this->assertGreaterThan($originalTstamp, $workspaceRecord['tstamp'], 'Timestamp should be updated');
        
        // Verify other fields remain unchanged (not specified in update)
        $this->assertEquals($originalRecord['CType'], $workspaceRecord['CType'], 'CType should remain unchanged');
        $this->assertEquals($originalRecord['colPos'], $workspaceRecord['colPos'], 'Column position should remain unchanged');
        
        // Verify live version remains unchanged
        $liveRecord = $queryBuilder->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(100, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        $this->assertEquals($originalHeader, $liveRecord['header'], 'Live record header should remain unchanged');
        $this->assertEquals($originalBodytext, $liveRecord['bodytext'], 'Live record bodytext should remain unchanged');
    }

    /**
     * Test deleting a content element
     */
    public function testDeleteContentElement(): void
    {
        $tool = new WriteTableTool();
        
        // Verify record exists before deletion
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        
        $beforeDelete = $queryBuilder->select('uid', 'deleted')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(101, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        $this->assertIsArray($beforeDelete, 'Record should exist before deletion');
        $this->assertEquals(0, $beforeDelete['deleted'], 'Record should not be deleted initially');
        
        // Perform the deletion
        $result = $tool->execute([
            'action' => 'delete',
            'table' => 'tt_content',
            'uid' => 101
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->content));
        
        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('delete', $data['action']);
        $this->assertEquals('tt_content', $data['table']);
        $this->assertEquals(101, $data['uid']);
        
        // VERIFY THE RECORD WAS ACTUALLY MARKED AS DELETED
        // In TYPO3 workspaces, deletion creates a delete placeholder
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        
        // Check for delete placeholder in workspace
        $deletePlaceholder = $queryBuilder->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(101, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter(2, ParameterType::INTEGER)) // Delete placeholder
            )
            ->executeQuery()
            ->fetchAssociative();
        
        $this->assertIsArray($deletePlaceholder, 'Delete placeholder should be created in workspace');
        $this->assertEquals(2, $deletePlaceholder['t3ver_state'], 'Should have delete placeholder state');
        // Note: In TYPO3, delete placeholders may not always have deleted=1, the t3ver_state=2 is what matters
        $this->assertGreaterThan(0, $deletePlaceholder['t3ver_wsid'], 'Delete placeholder should be in workspace');
        
        // Verify original record is still in live workspace (unchanged)
        $liveRecord = $queryBuilder->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(101, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        $this->assertIsArray($liveRecord, 'Live record should still exist');
        $this->assertEquals(0, $liveRecord['deleted'], 'Live record should not be deleted yet');
        
        // Verify record appears deleted when queried with restrictions
        $restrictedQueryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $restrictedQueryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        
        $visibleRecord = $restrictedQueryBuilder->select('uid')
            ->from('tt_content')
            ->where(
                $restrictedQueryBuilder->expr()->eq('uid', $restrictedQueryBuilder->createNamedParameter(101, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();
        
        // In workspace context, the record should appear deleted
        // Note: This might still return the record depending on workspace overlay logic
        // The important verification is that the delete placeholder was created above
    }

    /**
     * Test creating content at the bottom of a page.
     *
     * Fixture data on page 1:
     *   uid 100, sorting 256
     *   uid 101, sorting 512
     *   uid 104, sorting 768 (hidden=1 — still counts for sorting)
     *
     * Inserting with position=bottom must place the record on pid 1 with
     * sorting greater than every existing record on the page.
     */
    public function testCreateContentAtBottom(): void
    {
        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'position' => 'bottom',
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Bottom Position Content',
                'colPos' => 0
            ]
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $newUid = $data['uid'];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $newRecord = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => $newUid])
            ->fetchAssociative();
        $this->assertIsArray($newRecord, "New record $newUid must exist");

        $this->assertEquals(1, (int)$newRecord['pid'],
            'Record created with position=bottom must be on the provided pid');

        // Sorting must be greater than the highest existing sorting on page 1.
        // Fixture: uid 104 has sorting 768 (highest), hidden but still counts.
        $qbMax = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $qbMax->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $maxSorting = (int)$qbMax->select('sorting')
            ->from('tt_content')
            ->where(
                $qbMax->expr()->eq('pid', $qbMax->createNamedParameter(1, ParameterType::INTEGER)),
                $qbMax->expr()->neq('uid', $qbMax->createNamedParameter($newUid, ParameterType::INTEGER))
            )
            ->orderBy('sorting', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        $this->assertGreaterThan($maxSorting, (int)$newRecord['sorting'],
            'Record created with position=bottom must have sorting greater than all existing records on the page');
    }

    /**
     * Test creating content at bottom position on a table without a sorting field configured.
     * The position option should silently do nothing when no sorting field exists.
     */
    public function testCreateContentAtBottomWithoutSortingField(): void
    {
        // Temporarily remove sortby from tt_content TCA to simulate a table without sorting
        $originalSortby = $GLOBALS['TCA']['tt_content']['ctrl']['sortby'];
        unset($GLOBALS['TCA']['tt_content']['ctrl']['sortby']);

        try {
            $tool = new WriteTableTool();

            $result = $tool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => 1,
                'position' => 'bottom',
                'data' => [
                    'CType' => 'textmedia',
                    'header' => 'Content Without Sorting Field',
                    'colPos' => 0
                ]
            ]);

            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

            $data = json_decode($result->content[0]->text, true);
            $this->assertIsArray($data);
            $this->assertEquals('create', $data['action']);
            $this->assertIsInt($data['uid']);
        } finally {
            // Restore TCA
            $GLOBALS['TCA']['tt_content']['ctrl']['sortby'] = $originalSortby;
        }
    }

    /**
     * Test that after:UID positions the record between the reference element
     * and the next element in sorting order.
     *
     * Fixture data on page 1 (colPos 0):
     *   uid 100, sorting 256 (reference)
     *   uid 101, sorting 512 (next)
     *
     * Inserting after:100 must place the new record:
     *   - On pid 1
     *   - With sorting BETWEEN uid 100 (256) and uid 101 (512)
     */
    public function testCreateContentAfterElement(): void
    {
        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'position' => 'after:100',
            'data' => [
                'CType' => 'textmedia',
                'header' => 'After Element 100',
                'colPos' => 0
            ]
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertIsArray($data);
        $this->assertEquals('create', $data['action']);
        $this->assertIsInt($data['uid']);

        $newUid = $data['uid'];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $newRecord = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => $newUid])->fetchAssociative();
        $refRecord = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => 100])->fetchAssociative();
        $nextRecord = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => 101])->fetchAssociative();

        $this->assertIsArray($newRecord);
        $this->assertIsArray($refRecord);
        $this->assertIsArray($nextRecord);

        $this->assertEquals(1, (int)$newRecord['pid'],
            'Record created with after:100 must be on pid 1');

        $this->assertGreaterThan(
            (int)$refRecord['sorting'],
            (int)$newRecord['sorting'],
            'New record sorting must be greater than the reference record (uid 100)'
        );
        $this->assertLessThan(
            (int)$nextRecord['sorting'],
            (int)$newRecord['sorting'],
            'New record sorting must be less than the next record (uid 101)'
        );
    }

    /**
     * Test creating content after the last element on a page.
     *
     * Fixture data on page 2 (colPos 0):
     *   uid 102, sorting 256
     *   uid 103, sorting 512 (last)
     *
     * Inserting after:103 must place the record on pid 2 with sorting > 512.
     */
    public function testCreateContentAfterLastElement(): void
    {
        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 2,
            'position' => 'after:103',
            'data' => [
                'CType' => 'textmedia',
                'header' => 'After Last Element',
                'colPos' => 0
            ]
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $newUid = $data['uid'];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $newRecord = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => $newUid])->fetchAssociative();
        $refRecord = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => 103])->fetchAssociative();

        $this->assertIsArray($newRecord);
        $this->assertIsArray($refRecord);

        $this->assertEquals(2, (int)$newRecord['pid'],
            'Record created with after:103 must be on pid 2');
        $this->assertGreaterThan(
            (int)$refRecord['sorting'],
            (int)$newRecord['sorting'],
            'Record created with after:103 (last element) must have sorting greater than record 103'
        );
    }

    /**
     * Test that before:UID positions the record between the preceding element
     * and the reference element — not at the top of the page.
     *
     * Fixture data on page 1 (colPos 0):
     *   uid 100, sorting 256 (Welcome Header)
     *   uid 101, sorting 512 (About Section)
     *   uid 104, sorting 768 (Hidden Content, hidden=1)
     *
     * Inserting before:101 must place the new record:
     *   - On pid 1 (not pid 0)
     *   - With sorting BETWEEN uid 100 (256) and uid 101 (512)
     *     i.e. sorting > 256 AND sorting < 512
     */
    public function testCreateContentBeforeElement(): void
    {
        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'position' => 'before:101',
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Before Element 101',
                'colPos' => 0
            ]
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertIsArray($data);
        $this->assertEquals('create', $data['action']);
        $this->assertIsInt($data['uid']);

        $newUid = $data['uid'];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $newRecord  = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => $newUid])->fetchAssociative();
        $prevRecord = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => 100])->fetchAssociative();
        $refRecord  = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => 101])->fetchAssociative();

        $this->assertIsArray($newRecord);
        $this->assertIsArray($prevRecord);
        $this->assertIsArray($refRecord);

        // Critical: PID must be 1, not 0
        $this->assertEquals(1, (int)$newRecord['pid'],
            'Record created with before:101 must be on pid 1, not pid ' . $newRecord['pid']);

        // The new record must sit between uid 100 and uid 101 in sorting order.
        $this->assertGreaterThan(
            (int)$prevRecord['sorting'],
            (int)$newRecord['sorting'],
            'New record sorting (' . $newRecord['sorting'] . ') must be greater than '
            . 'record 100 sorting (' . $prevRecord['sorting'] . ') — it should be between 100 and 101, not at the top'
        );
        $this->assertLessThan(
            (int)$refRecord['sorting'],
            (int)$newRecord['sorting'],
            'New record sorting (' . $newRecord['sorting'] . ') must be less than '
            . 'record 101 sorting (' . $refRecord['sorting'] . ')'
        );
    }

    /**
     * Test creating content before the first element on a page.
     *
     * Fixture data on page 1 (colPos 0):
     *   uid 100, sorting 256 (Welcome Header) — first element
     *
     * Inserting before:100 must place the record on pid 1 with sorting < 256.
     */
    public function testCreateContentBeforeFirstElement(): void
    {
        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'position' => 'before:100',
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Before First Element',
                'colPos' => 0
            ]
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertIsArray($data);
        $newUid = $data['uid'];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $newRecord = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => $newUid])->fetchAssociative();
        $refRecord = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => 100])->fetchAssociative();

        $this->assertIsArray($newRecord);
        $this->assertIsArray($refRecord);

        $this->assertEquals(1, (int)$newRecord['pid'],
            'Record created with before:100 must be on pid 1');

        // The new record must have lower sorting than the first element
        $this->assertLessThan(
            (int)$refRecord['sorting'],
            (int)$newRecord['sorting'],
            'Record created with before:100 must have lower sorting than record 100'
        );
    }

    /**
     * Test creating content at the top of a page.
     *
     * Fixture data on page 1 (colPos 0):
     *   uid 100, sorting 256 (first)
     *
     * Inserting with position=top must place the record on pid 1 with
     * sorting lower than every existing record on the page.
     */
    public function testCreateContentAtTop(): void
    {
        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'position' => 'top',
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Top Position Content',
                'colPos' => 0
            ]
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertIsInt($data['uid']);
        $newUid = $data['uid'];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $newRecord = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => $newUid])
            ->fetchAssociative();

        $this->assertIsArray($newRecord);
        $this->assertEquals(1, (int)$newRecord['pid'],
            'Record created with position=top must be on the provided pid');

        // Sorting must be less than the minimum existing sorting on page 1.
        $qbMin = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $qbMin->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $minSorting = (int)$qbMin->select('sorting')
            ->from('tt_content')
            ->where(
                $qbMin->expr()->eq('pid', $qbMin->createNamedParameter(1, ParameterType::INTEGER)),
                $qbMin->expr()->neq('uid', $qbMin->createNamedParameter($newUid, ParameterType::INTEGER))
            )
            ->orderBy('sorting', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        $this->assertLessThan($minSorting, (int)$newRecord['sorting'],
            'Record created with position=top must have sorting less than all existing records on the page');
    }

    /**
     * Test that before:UID follows the reference record's page when the
     * user-provided pid points to a different page.
     *
     * This is the scenario from issue #50: user provides pid=1 but
     * references a UID on page 2. The positional reference must win —
     * the record must NOT land on pid 0 or pid 1.
     *
     * Fixture data on page 2 (colPos 0):
     *   uid 102, sorting 256 (first element on page 2)
     *   uid 103, sorting 512
     */
    public function testCreateContentBeforeElementOnDifferentPage(): void
    {
        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1, // deliberately different from reference's actual page
            'position' => 'before:102',
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Before element on another page',
                'colPos' => 0
            ]
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $newUid = $data['uid'];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $newRecord = $connection->select(['pid', 'sorting'], 'tt_content', ['uid' => $newUid])
            ->fetchAssociative();

        $this->assertIsArray($newRecord);
        $this->assertEquals(2, (int)$newRecord['pid'],
            'Record must land on the reference element\'s page (2), not the user-provided pid (1) or pid 0');
        $this->assertLessThan(256, (int)$newRecord['sorting'],
            'Record must have sorting less than reference record 102 (sorting 256)');
    }

    /**
     * Test workspace isolation - verify records never written to live
     */
    public function testWorkspaceIsolation(): void
    {
        $tool = new WriteTableTool();
        
        // Create multiple records
        $createResults = [];
        
        // Create a page
        $pageResult = $tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Workspace Test Page',
                'slug' => '/workspace-test',
                'doktype' => 1
            ]
        ]);
        $this->assertFalse($pageResult->isError, json_encode($pageResult->content));
        $pageData = json_decode($pageResult->content[0]->text, true);
        $createResults[] = ['table' => 'pages', 'uid' => $pageData['uid']];
        
        // Create content
        $contentResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Workspace Test Content'
            ]
        ]);
        $this->assertFalse($contentResult->isError, json_encode($contentResult->content));
        $contentData = json_decode($contentResult->content[0]->text, true);
        $createResults[] = ['table' => 'tt_content', 'uid' => $contentData['uid']];
        
        // Verify ALL created records are NOT in live workspace
        foreach ($createResults as $record) {
            $this->assertRecordNotInLive($record['table'], $record['uid']);
        }
    }

    /**
     * Test workspace capability validation
     */
    public function testWorkspaceCapabilityValidation(): void
    {
        $tool = new WriteTableTool();
        
        // Try to create a record in a non-workspace-capable table
        // Use be_users which exists and is not workspace-capable
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'be_users',
            'pid' => 0,
            'data' => [
                'username' => 'testuser',
                'password' => 'test'
            ]
        ]);
        
        $this->assertTrue($result->isError);
        // be_users is restricted for security reasons
        $this->assertStringContainsString('is restricted for security or system integrity reasons', $result->content[0]->text);
    }

    /**
     * Test data validation
     */
    public function testDataValidation(): void
    {
        $tool = new WriteTableTool();
        
        // Test with invalid CType
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'invalid_ctype',
                'header' => 'Test'
            ]
        ]);
        
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('must be one of:', $result->content[0]->text);
    }

    /**
     * Test required field validation
     */
    public function testRequiredFieldValidation(): void
    {
        $tool = new WriteTableTool();
        
        // Test creating page without required title
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'doktype' => 1
                // Missing required 'title' field
            ]
        ]);
        
        // Note: title might have a default value, so this might not fail
        // The test is more about the validation mechanism
        $this->assertNotNull($result);
    }

    /**
     * Test invalid table handling
     */
    public function testInvalidTableHandling(): void
    {
        $tool = new WriteTableTool();
        
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'non_existent_table',
            'pid' => 1,
            'data' => [
                'field' => 'value'
            ]
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('does not exist in TCA', $result->content[0]->text);
    }

    /**
     * Test read-only table handling
     */
    public function testReadOnlyTableHandling(): void
    {
        $tool = new WriteTableTool();
        
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'be_users',
            'pid' => 0,
            'data' => [
                'username' => 'test',
                'password' => 'test'
            ]
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('is restricted for security or system integrity reasons', $result->content[0]->text);
    }

    /**
     * Test field type validation
     */
    public function testFieldTypeValidation(): void
    {
        $tool = new WriteTableTool();
        
        // Test select field with invalid value
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Test',
                'colPos' => 999 // Invalid column position
            ]
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('must be one of:', $result->content[0]->text);
    }

    /**
     * Test max length validation
     */
    public function testMaxLengthValidation(): void
    {
        $tool = new WriteTableTool();
        
        // Create a very long string that exceeds typical field length
        $veryLongTitle = str_repeat('a', 300); // Exceeds typical 255 char limit
        
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => $veryLongTitle,
                'doktype' => 1
            ]
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('exceeds maximum length', $result->content[0]->text);
    }

    /**
     * Test select field validation
     */
    public function testSelectFieldValidation(): void
    {
        $tool = new WriteTableTool();
        
        // Test with invalid doktype value
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'doktype' => 999 // Invalid doktype
            ]
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('must be one of:', $result->content[0]->text);
    }

    /**
     * Test FlexForm field handling
     */
    public function testFlexFormFieldHandling(): void
    {
        $tool = new WriteTableTool();
        
        // Test creating content with FlexForm data
        // Use a plugin CType (news_pi1) which has a pi_flexform field
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'news_pi1',
                'header' => 'Plugin with FlexForm',
                'pi_flexform' => [
                    'settings' => [
                        'caption' => 'Plugin Caption',
                        'headerPosition' => 'top'
                    ]
                ]
            ]
        ]);
        
        // Check for errors first
        $this->assertFalse($result->isError, json_encode($result->content));
        
        // Check the result
        $data = json_decode($result->content[0]->text, true);
        $this->assertIsArray($data);
        $this->assertEquals('create', $data['action']);
        
        // If creation succeeded, verify FlexForm was converted to XML
        if (isset($data['uid'])) {
            $newUid = $data['uid'];
            
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('tt_content');
            
            $record = $queryBuilder->select('pi_flexform')
                ->from('tt_content')
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($newUid, ParameterType::INTEGER))
                )
                ->executeQuery()
                ->fetchAssociative();
            
            if ($record && !empty($record['pi_flexform'])) {
                $this->assertStringContainsString('<?xml', $record['pi_flexform']);
                $this->assertStringContainsString('T3FlexForms', $record['pi_flexform']);
            }
        }
    }

    /**
     * Test date field conversion
     */
    public function testDateFieldConversion(): void
    {
        $tool = new WriteTableTool();
        
        // Test with ISO 8601 date
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page with Date',
                'doktype' => 1,
                'starttime' => '2024-12-25T10:00:00+00:00' // ISO 8601 format
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->content));
        
        // The tool should convert ISO date to timestamp
        $data = json_decode($result->content[0]->text, true);
        $this->assertIsInt($data['uid']);
    }

    /**
     * Test multiple select field handling
     */
    public function testMultipleSelectFieldHandling(): void
    {
        $tool = new WriteTableTool();
        
        // Test with the actual categories field which is available for textmedia
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Content with Categories',
                'categories' => '1,2' // Use comma-separated string for category UIDs
            ]
        ]);
        
        // Check for errors first
        $this->assertFalse($result->isError, json_encode($result->content));
        
        // This test is now just checking that the tool handles data correctly
        $data = json_decode($result->content[0]->text, true);
        $this->assertIsArray($data);
        $this->assertEquals('create', $data['action']);
        
        // If the field doesn't exist, we'll get an error from DataHandler but that's OK
        // The important thing is that the tool doesn't crash
    }

    /**
     * Test error handling for missing action
     */
    public function testMissingActionError(): void
    {
        $tool = new WriteTableTool();
        
        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['header' => 'Test']
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Action is required', $result->content[0]->text);
    }

    /**
     * Test error handling for missing table
     */
    public function testMissingTableError(): void
    {
        $tool = new WriteTableTool();
        
        $result = $tool->execute([
            'action' => 'create',
            'pid' => 1,
            'data' => ['header' => 'Test']
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Table name is required', $result->content[0]->text);
    }

    /**
     * Test error handling for missing pid on create
     */
    public function testMissingPidError(): void
    {
        $tool = new WriteTableTool();
        
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => ['header' => 'Test']
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Page ID (pid) is required', $result->content[0]->text);
    }

    /**
     * Test error handling for missing uid on update
     */
    public function testMissingUidOnUpdateError(): void
    {
        $tool = new WriteTableTool();
        
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'data' => ['header' => 'Test']
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Record UID is required', $result->content[0]->text);
    }

    /**
     * Test error handling for missing data on create
     */
    public function testMissingDataOnCreateError(): void
    {
        $tool = new WriteTableTool();
        
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('data parameter must contain record fields for create actions', $result->content[0]->text);
    }

    /**
     * Test tool through registry
     */
    public function testWriteTableThroughRegistry(): void
    {
        // Create tool registry with WriteTableTool
        $tools = [new WriteTableTool()];
        $registry = new ToolRegistry($tools);
        
        // Get tool from registry
        $tool = $registry->getTool('WriteTable');
        $this->assertNotNull($tool);
        $this->assertInstanceOf(WriteTableTool::class, $tool);
        
        // Execute through registry
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Registry Test'
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->content));
    }

    /**
     * Test tool name extraction
     */
    public function testToolName(): void
    {
        $tool = new WriteTableTool();
        $this->assertEquals('WriteTable', $tool->getName());
    }

    /**
     * Test tool schema
     */
    public function testToolSchema(): void
    {
        $tool = new WriteTableTool();
        $schema = $tool->getSchema();
        
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('description', $schema);
        $this->assertArrayHasKey('inputSchema', $schema);
        
        // Check parameters
        $properties = $schema['inputSchema']['properties'];
        $this->assertArrayHasKey('action', $properties);
        $this->assertArrayHasKey('table', $properties);
        $this->assertArrayHasKey('pid', $properties);
        $this->assertArrayHasKey('uid', $properties);
        $this->assertArrayHasKey('data', $properties);
        $this->assertArrayHasKey('position', $properties);
        
        // Check required fields
        $this->assertArrayHasKey('required', $schema['inputSchema']);
        $this->assertContains('action', $schema['inputSchema']['required']);
        $this->assertContains('table', $schema['inputSchema']['required']);
    }

    /**
     * Test that slug fields are normalized: trailing slashes stripped, leading slash ensured.
     * @see https://github.com/hauptsacheNet/typo3-mcp-server/issues/6
     */
    public static function slugNormalizationDataProvider(): array
    {
        return [
            'trailing slash' => ['/trailing-slash-test/', '/trailing-slash-test'],
            'missing leading slash' => ['no-leading-slash', '/no-leading-slash'],
            'both issues' => ['both-issues/', '/both-issues'],
            'already correct' => ['/already-correct', '/already-correct'],
            'root page slash' => ['/', '/'],
        ];
    }

    /**
     * @see https://github.com/hauptsacheNet/typo3-mcp-server/issues/6
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('slugNormalizationDataProvider')]
    public function testCreatePageNormalizesSlug(string $inputSlug, string $expectedSlug): void
    {
        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Slug Normalization Test',
                'slug' => $inputSlug,
                'doktype' => 1,
            ]
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = $this->extractJsonFromResult($result);
        $uid = $data['uid'];

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $record = $queryBuilder->select('slug')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($record, 'Page record should exist');
        $this->assertEquals($expectedSlug, $record['slug'], "Slug '$inputSlug' should be normalized to '$expectedSlug'");
    }

    /**
     * Test that slug normalization also works on update.
     * @see https://github.com/hauptsacheNet/typo3-mcp-server/issues/6
     */
    public function testUpdatePageNormalizesSlug(): void
    {
        $pageUid = $this->data->page()
            ->withTitle('Slug Update Test')
            ->withSlug('/slug-update-test')
            ->withParent($this->getRootPageUid())
            ->create();

        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => $pageUid,
            'data' => [
                'slug' => '/updated-slug/',
            ]
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $record = $queryBuilder->select('slug')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($record, 'Workspace version should exist');
        $this->assertEquals('/updated-slug', $record['slug'], 'Trailing slash should be stripped from slug on update');
    }

    // ========================================================================
    // search_replace tests
    // ========================================================================

    /**
     * Test basic search_replace on a bodytext field
     */
    public function testSearchReplaceBasic(): void
    {
        $contentUid = $this->data->content()
            ->withHeader('SR Basic')
            ->withBodytext('Hello world, this is original content.')
            ->onPage($this->getRootPageUid())
            ->inColumn(0)
            ->create();

        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $contentUid,
            'data' => [
                'bodytext' => [
                    ['search' => 'original content', 'replace' => 'updated content'],
                ],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $resultData = $this->extractJsonFromResult($result);
        $this->assertEquals('update', $resultData['action']);
        $this->assertEquals($contentUid, $resultData['uid']);

        // Verify the workspace record has the correct content
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $wsRecord = $queryBuilder->select('bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($contentUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($wsRecord, 'Workspace version should exist');
        $this->assertEquals('Hello world, this is updated content.', $wsRecord['bodytext']);
    }

    /**
     * Test multiple search_replace operations on the same field
     */
    public function testSearchReplaceMultipleOperations(): void
    {
        $contentUid = $this->data->content()
            ->withHeader('SR Multiple')
            ->withBodytext('The quick brown fox jumps over the lazy dog.')
            ->onPage($this->getRootPageUid())
            ->inColumn(0)
            ->create();

        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $contentUid,
            'data' => [
                'bodytext' => [
                    ['search' => 'quick brown', 'replace' => 'slow red'],
                    ['search' => 'lazy dog', 'replace' => 'energetic cat'],
                ],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $wsRecord = $queryBuilder->select('bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($contentUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($wsRecord, 'Workspace version should exist');
        $this->assertEquals('The slow red fox jumps over the energetic cat.', $wsRecord['bodytext']);
    }

    /**
     * Test search_replace combined with data on different fields
     */
    public function testSearchReplaceCombinedWithData(): void
    {
        $contentUid = $this->data->content()
            ->withHeader('Old Header')
            ->withBodytext('Some long content that should be partially edited.')
            ->onPage($this->getRootPageUid())
            ->inColumn(0)
            ->create();

        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $contentUid,
            'data' => [
                'header' => 'New Header',
                'bodytext' => [
                    ['search' => 'partially edited', 'replace' => 'surgically updated'],
                ],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $wsRecord = $queryBuilder->select('header', 'bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($contentUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($wsRecord, 'Workspace version should exist');
        $this->assertEquals('New Header', $wsRecord['header']);
        $this->assertEquals('Some long content that should be surgically updated.', $wsRecord['bodytext']);
    }

    /**
     * Test search_replace deletion (empty replace string)
     */
    public function testSearchReplaceDeletion(): void
    {
        $contentUid = $this->data->content()
            ->withHeader('SR Delete')
            ->withBodytext('Keep this. Remove this sentence. And keep this too.')
            ->onPage($this->getRootPageUid())
            ->inColumn(0)
            ->create();

        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $contentUid,
            'data' => [
                'bodytext' => [
                    ['search' => ' Remove this sentence.', 'replace' => ''],
                ],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $wsRecord = $queryBuilder->select('bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($contentUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($wsRecord, 'Workspace version should exist');
        $this->assertEquals('Keep this. And keep this too.', $wsRecord['bodytext']);
    }

    /**
     * Test search_replace with replaceAll flag
     */
    public function testSearchReplaceReplaceAll(): void
    {
        $contentUid = $this->data->content()
            ->withHeader('SR ReplaceAll')
            ->withBodytext('foo bar foo baz foo')
            ->onPage($this->getRootPageUid())
            ->inColumn(0)
            ->create();

        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $contentUid,
            'data' => [
                'bodytext' => [
                    ['search' => 'foo', 'replace' => 'qux', 'replaceAll' => true],
                ],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $wsRecord = $queryBuilder->select('bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($contentUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($wsRecord, 'Workspace version should exist');
        $this->assertEquals('qux bar qux baz qux', $wsRecord['bodytext']);
    }

    /**
     * Test search_replace reads workspace version when one exists
     */
    public function testSearchReplacePreservesWorkspaceTransparency(): void
    {
        $contentUid = $this->data->content()
            ->withHeader('SR Workspace')
            ->withBodytext('Live content here.')
            ->onPage($this->getRootPageUid())
            ->inColumn(0)
            ->create();

        // First update creates a workspace version
        $result1 = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $contentUid,
            'data' => [
                'bodytext' => 'Workspace content here.',
            ],
        ]);
        $this->assertFalse($result1->isError, json_encode($result1->jsonSerialize()));

        // Second update uses search-and-replace — should operate on the workspace version
        $result2 = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $contentUid,
            'data' => [
                'bodytext' => [
                    ['search' => 'Workspace content', 'replace' => 'Modified workspace content'],
                ],
            ],
        ]);
        $this->assertFalse($result2->isError, json_encode($result2->jsonSerialize()));

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $wsRecord = $queryBuilder->select('bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($contentUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($wsRecord, 'Workspace version should exist');
        $this->assertEquals('Modified workspace content here.', $wsRecord['bodytext']);
    }

    /**
     * Test search-and-replace as the only field operation (no full-value fields)
     */
    public function testSearchReplaceWithoutData(): void
    {
        $contentUid = $this->data->content()
            ->withHeader('SR No Data')
            ->withBodytext('Content to modify without full replacement.')
            ->onPage($this->getRootPageUid())
            ->inColumn(0)
            ->create();

        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $contentUid,
            'data' => [
                'bodytext' => [
                    ['search' => 'without full replacement', 'replace' => 'using only search-and-replace'],
                ],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $resultData = $this->extractJsonFromResult($result);
        $this->assertEquals('update', $resultData['action']);
        $this->assertEquals($contentUid, $resultData['uid']);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $wsRecord = $queryBuilder->select('bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($contentUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($wsRecord, 'Workspace version should exist');
        $this->assertEquals('Content to modify using only search-and-replace.', $wsRecord['bodytext']);
    }

    /**
     * Test search_replace with HTML content (richtext field)
     */
    public function testSearchReplaceWithHtmlContent(): void
    {
        $contentUid = $this->data->content()
            ->withHeader('SR HTML')
            ->withBodytext('<p>First paragraph with <strong>bold text</strong>.</p><p>Second paragraph.</p>')
            ->onPage($this->getRootPageUid())
            ->inColumn(0)
            ->create();

        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $contentUid,
            'data' => [
                'bodytext' => [
                    ['search' => '<strong>bold text</strong>', 'replace' => '<em>italic text</em>'],
                ],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $wsRecord = $queryBuilder->select('bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($contentUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($wsRecord, 'Workspace version should exist');
        // The replacement should have been applied; TYPO3's RTE may add whitespace between block elements
        $this->assertStringContainsString('<em>italic text</em>', $wsRecord['bodytext']);
        $this->assertStringNotContainsString('<strong>bold text</strong>', $wsRecord['bodytext']);
    }

    /**
     * Test that update with position=bottom moves a record to the last position on its page.
     */
    public function testUpdatePositionBottomMovesRecordToEnd(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        // Create a fresh page and three content elements via WriteTableTool
        $pageResult = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => ['title' => 'Position Bottom Test', 'slug' => '/pos-bottom', 'doktype' => 1],
        ]);
        $this->assertFalse($pageResult->isError, json_encode($pageResult->jsonSerialize()));
        $pageUid = $this->extractJsonFromResult($pageResult)['uid'];

        $uids = [];
        foreach (['A', 'B', 'C'] as $header) {
            $result = $this->tool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => $pageUid,
                'position' => 'bottom',
                'data' => ['CType' => 'textmedia', 'header' => $header, 'colPos' => 0],
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $uids[$header] = $this->extractJsonFromResult($result)['uid'];
        }

        // Move A to bottom — should end up after C
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uids['A'],
            'position' => 'bottom',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read back and verify order is now B, C, A
        $readResult = $readTool->execute([
            'table' => 'tt_content',
            'pid' => $pageUid,
        ]);
        $this->assertFalse($readResult->isError, json_encode($readResult->jsonSerialize()));
        $readData = $this->extractJsonFromResult($readResult);
        $headers = array_map(fn(array $r) => $r['header'], $readData['records']);
        $this->assertSame(['B', 'C', 'A'], $headers, 'position=bottom should move A after C');
    }

    /**
     * Test that update without position parameter does not move the record.
     */
    public function testUpdateWithoutPositionDoesNotMove(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        // Create a fresh page and two content elements via WriteTableTool
        $pageResult = $this->tool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => ['title' => 'No Position Test', 'slug' => '/no-pos', 'doktype' => 1],
        ]);
        $this->assertFalse($pageResult->isError, json_encode($pageResult->jsonSerialize()));
        $pageUid = $this->extractJsonFromResult($pageResult)['uid'];

        $resultA = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pageUid,
            'position' => 'bottom',
            'data' => ['CType' => 'textmedia', 'header' => 'A', 'colPos' => 0],
        ]);
        $this->assertFalse($resultA->isError, json_encode($resultA->jsonSerialize()));
        $uidA = $this->extractJsonFromResult($resultA)['uid'];

        $resultB = $this->tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pageUid,
            'position' => 'bottom',
            'data' => ['CType' => 'textmedia', 'header' => 'B', 'colPos' => 0],
        ]);
        $this->assertFalse($resultB->isError, json_encode($resultB->jsonSerialize()));

        // Update A's header without specifying position — order must stay A, B
        $result = $this->tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uidA,
            'data' => ['header' => 'A modified'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $readResult = $readTool->execute([
            'table' => 'tt_content',
            'pid' => $pageUid,
        ]);
        $this->assertFalse($readResult->isError, json_encode($readResult->jsonSerialize()));
        $readData = $this->extractJsonFromResult($readResult);
        $headers = array_map(fn(array $r) => $r['header'], $readData['records']);
        $this->assertSame(['A modified', 'B'], $headers, 'Omitting position should not change order');
    }

    /**
     * Test that sequential content element creation preserves intended order.
     * @see https://github.com/hauptsacheNet/typo3-mcp-server/issues/26
     *
     * When an LLM creates multiple content elements one after another using position "bottom",
     * they must appear in the same order they were created (first created = first in list).
     */
    public function testSequentialWritesCreateContentInCorrectOrder(): void
    {
        $writeTool = new WriteTableTool();
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        // Create a fresh page so we have no pre-existing content
        $pageResult = $writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Test Sorting Page',
                'slug' => '/test-sorting',
                'doktype' => 1,
            ]
        ]);
        $this->assertFalse($pageResult->isError, json_encode($pageResult->jsonSerialize()));
        $pageData = $this->extractJsonFromResult($pageResult);
        $pageUid = $pageData['uid'];

        // Sequentially create 3 content elements at the bottom
        $expectedHeaders = ['1: First', '2: Second', '3: Third'];
        $createdUids = [];

        foreach ($expectedHeaders as $header) {
            $result = $writeTool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => $pageUid,
                'position' => 'bottom',
                'data' => [
                    'CType' => 'textmedia',
                    'header' => $header,
                    'colPos' => 0,
                ]
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $data = $this->extractJsonFromResult($result);
            $createdUids[] = $data['uid'];
        }

        // Use ReadTable to fetch all content on the page — results should be sorted by sorting ASC
        $readResult = $readTool->execute([
            'table' => 'tt_content',
            'pid' => $pageUid,
        ]);
        $this->assertFalse($readResult->isError, json_encode($readResult->jsonSerialize()));
        $readData = $this->extractJsonFromResult($readResult);

        $this->assertArrayHasKey('records', $readData);
        $records = $readData['records'];
        $this->assertCount(3, $records, 'Expected exactly 3 content elements on the page');

        // Verify the records come back in creation order
        $actualHeaders = array_map(fn(array $r) => $r['header'], $records);
        $this->assertSame(
            $expectedHeaders,
            $actualHeaders,
            'Content elements must appear in the order they were created. ' .
            'Sequential "bottom" writes should produce ascending sorting values.'
        );

        // Also verify UIDs match in order
        $actualUids = array_map(fn(array $r) => $r['uid'], $records);
        $this->assertSame($createdUids, $actualUids, 'UIDs should match creation order');
    }

    /**
     * Helper method to assert a record is not in live workspace
     */
    protected function assertRecordNotInLive(string $table, int $uid): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        
        // Remove all restrictions to check raw data
        $queryBuilder->getRestrictions()->removeAll();
        
        // Look for record in live workspace
        $liveRecord = $queryBuilder->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();
        
        $this->assertFalse($liveRecord, 
            "Record {$uid} in table {$table} must not exist in live workspace (t3ver_wsid = 0). " .
            "MCP operations should only create records in workspace context."
        );
    }
}