<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test inline relations with sys_file_reference (dependent records with hideTable=true)
 */
class FileReferenceInlineTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        
        // Create a sys_file record for testing
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file.csv');
    }

    /**
     * Test that file references are fully embedded (not just UIDs)
     */
    public function testFileReferencesAreFullyEmbedded(): void
    {
        // Create a page with images
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Page with images',
                'doktype' => 1,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pageUid = json_decode($result->content[0]->text, true)['uid'];

        // Create file references
        $fileReferenceUids = [];
        for ($i = 1; $i <= 2; $i++) {
            $result = $writeTool->execute([
                'table' => 'sys_file_reference',
                'pid' => $pageUid,
                'data' => [
                    'uid_local' => 1, // Reference to sys_file with uid=1
                    'uid_foreign' => $pageUid,
                    'tablenames' => 'pages',
                    'fieldname' => 'media',
                    'sorting_foreign' => $i * 256,
                    'title' => "Image $i title",
                    'description' => "Image $i description",
                    'alternative' => "Image $i alt text",
                ],
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $fileReferenceUids[] = json_decode($result->content[0]->text, true)['uid'];
        }

        // Read the page record
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'pages',
            'uid' => $pageUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $page = json_decode($result->content[0]->text, true)['records'][0];
        
        // Verify media field contains full embedded records (not just UIDs)
        $this->assertArrayHasKey('media', $page, 'Page should have media field');
        $this->assertIsArray($page['media'], 'media should be an array');
        $this->assertCount(2, $page['media'], 'Should have 2 file references');
        
        // Check that we get full records, not just UIDs
        foreach ($page['media'] as $index => $fileRef) {
            $this->assertIsArray($fileRef, 'File reference should be a full record array, not just a UID');
            
            // Verify essential fields are present
            $this->assertArrayHasKey('uid', $fileRef);
            $this->assertArrayHasKey('uid_local', $fileRef);
            $this->assertArrayHasKey('uid_foreign', $fileRef);
            $this->assertArrayHasKey('title', $fileRef);
            $this->assertArrayHasKey('description', $fileRef);
            $this->assertArrayHasKey('alternative', $fileRef);
            
            // Verify values
            $this->assertEquals(1, $fileRef['uid_local']);
            $this->assertEquals($pageUid, $fileRef['uid_foreign']);
            $this->assertEquals('pages', $fileRef['tablenames']);
            $this->assertEquals('media', $fileRef['fieldname']);
            $this->assertEquals("Image " . ($index + 1) . " title", $fileRef['title']);
        }
    }

    /**
     * Test that content element images are fully embedded
     */
    public function testContentElementImagesAreFullyEmbedded(): void
    {
        // Create a content element
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'header' => 'Content with images',
                'CType' => 'image',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $contentUid = json_decode($result->content[0]->text, true)['uid'];

        // Create file reference for content element
        $result = $writeTool->execute([
            'table' => 'sys_file_reference',
            'pid' => 1,
            'data' => [
                'uid_local' => 1,
                'uid_foreign' => $contentUid,
                'tablenames' => 'tt_content',
                'fieldname' => 'image',
                'title' => 'Content image',
                'crop' => '{"default":{"cropArea":{"x":0,"y":0,"width":1,"height":1}}}',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read the content element
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $content = json_decode($result->content[0]->text, true)['records'][0];
        
        // Verify image field contains full embedded record
        $this->assertArrayHasKey('image', $content);
        $this->assertIsArray($content['image']);
        $this->assertCount(1, $content['image']);
        
        $imageRef = $content['image'][0];
        $this->assertIsArray($imageRef, 'Image reference should be a full record');
        $this->assertEquals('Content image', $imageRef['title']);
        $this->assertArrayHasKey('crop', $imageRef);
    }

    /**
     * Test that empty file references are not included
     */
    public function testEmptyFileReferencesNotIncluded(): void
    {
        // Create a page without images
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Page without images',
                'doktype' => 1,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pageUid = json_decode($result->content[0]->text, true)['uid'];

        // Read the page record
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'pages',
            'uid' => $pageUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $page = json_decode($result->content[0]->text, true)['records'][0];
        
        // Verify media field is not present when no references exist
        $this->assertArrayNotHasKey('media', $page, 'Page without images should not have media field');
    }
}