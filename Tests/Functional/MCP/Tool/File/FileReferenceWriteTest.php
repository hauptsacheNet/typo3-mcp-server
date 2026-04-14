<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for file reference creation and reading via type=file fields
 */
class FileReferenceWriteTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;
    private ReadTableTool $readTool;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure sys_file fixtures exist
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file.csv');

        $this->writeTool = new WriteTableTool();
        $this->readTool = new ReadTableTool();
    }

    /**
     * Creating a page with file references using plain sys_file UIDs
     */
    public function testCreatePageWithFileReferenceUidShorthand(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Page with media',
                'media' => [1],
            ],
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        $this->assertArrayHasKey('uid', $data);

        // Verify sys_file_reference was created
        $references = $this->getFileReferences('pages', 'media', $data['uid']);
        $this->assertCount(1, $references);
        $this->assertEquals(1, $references[0]['uid_local']);
        $this->assertEquals('pages', $references[0]['tablenames']);
        $this->assertEquals('media', $references[0]['fieldname']);
    }

    /**
     * Creating a page with file references using object format with metadata
     */
    public function testCreatePageWithFileReferenceObjectFormat(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Page with titled media',
                'media' => [
                    ['uid_local' => 1, 'title' => 'Test Image', 'alternative' => 'Alt text'],
                ],
            ],
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $references = $this->getFileReferences('pages', 'media', $data['uid']);
        $this->assertCount(1, $references);
        $this->assertEquals(1, $references[0]['uid_local']);
        $this->assertEquals('Test Image', $references[0]['title']);
        $this->assertEquals('Alt text', $references[0]['alternative']);
    }

    /**
     * Creating content with multiple file references
     */
    public function testCreateContentWithMultipleFileReferences(): void
    {
        // Create additional sys_file records
        $connection = $this->getConnectionForTable('sys_file');
        $connection->insert('sys_file', [
            'uid' => 2,
            'pid' => 0,
            'name' => 'test2.jpg',
            'identifier' => '/test2.jpg',
            'storage' => 1,
            'type' => 2,
            'size' => 654321,
        ]);

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Content with assets',
                'assets' => [1, 2],
            ],
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $references = $this->getFileReferences('tt_content', 'assets', $data['uid']);
        $this->assertCount(2, $references);
    }

    /**
     * File field validation rejects invalid data (string instead of array)
     */
    public function testFileFieldRejectsStringValue(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Invalid media',
                'media' => 'not_an_array',
            ],
        ]);

        $this->assertToolError($result);
    }

    /**
     * File field validation rejects objects without uid_local
     */
    public function testFileFieldRejectsObjectWithoutUidLocal(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Missing uid_local',
                'media' => [
                    ['title' => 'No uid_local provided'],
                ],
            ],
        ]);

        $this->assertToolError($result, 'uid_local');
    }

    /**
     * ReadTableTool expands file field relations
     */
    public function testReadTableExpandsFileFields(): void
    {
        // Create a page with file reference first
        $createResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => $this->getRootPageUid(),
            'data' => [
                'title' => 'Page for reading',
                'media' => [
                    ['uid_local' => 1, 'title' => 'Readable Image'],
                ],
            ],
        ]);

        $this->assertSuccessfulToolResult($createResult);
        $createData = $this->extractJsonFromResult($createResult);

        // Now read the page and check that media field is expanded
        $readResult = $this->readTool->execute([
            'table' => 'pages',
            'uid' => $createData['uid'],
            'fields' => ['title', 'media'],
        ]);

        $this->assertSuccessfulToolResult($readResult);
        $readData = json_decode($readResult->content[0]->text, true);

        // The media field should contain expanded file reference records
        $records = $readData['records'] ?? [];
        $this->assertNotEmpty($records);

        $record = $records[0];
        $this->assertEquals('Page for reading', $record['title']);
        $this->assertIsArray($record['media']);
    }

    /**
     * Helper: get sys_file_reference records for a parent record
     */
    private function getFileReferences(string $tablenames, string $fieldname, int $uidForeign): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file_reference');

        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($tablenames)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($fieldname)),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uidForeign, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
