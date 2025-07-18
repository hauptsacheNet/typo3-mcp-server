<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test integer conversion in ReadTableTool
 */
class ReadTableIntegerConversionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import backend user fixture
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');

        // Set up backend user
        $this->setUpBackendUser(1);
        
        // Initialize language service
        if (!isset($GLOBALS['LANG'])) {
            $languageServiceFactory = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class);
            $GLOBALS['LANG'] = $languageServiceFactory->create('default');
        }
    }

    /**
     * Test that select fields with all integer values are converted to integers
     */
    public function testSelectFieldWithAllIntegerValues(): void
    {
        // Tools will automatically switch to optimal workspace
        $writeTool = new WriteTableTool();
        $readTool = new ReadTableTool();
        
        // Create a pages record with integer type value
        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Test Page Integer',
                'doktype' => 1, // Standard page - integer field
                'slug' => '/test-page-integer'
            ]
        ]);
        
        $this->assertFalse($result->isError, "Write operation failed: " . json_encode($result->jsonSerialize()));
        $writeResponse = $result->content[0]->text;
        $createdRecord = json_decode($writeResponse);
        $this->assertNotNull($createdRecord, "Could not decode write response: " . $writeResponse);
        $this->assertObjectHasProperty('uid', $createdRecord, "Write response missing UID: " . $writeResponse);
        $pageUid = $createdRecord->uid;
        
        // Read it back
        $result = $readTool->execute([
            'table' => 'pages',
            'uid' => $pageUid
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $readResult = json_decode($result->content[0]->text);
        $this->assertNotNull($readResult);
        $this->assertIsObject($readResult);
        $this->assertObjectHasProperty('records', $readResult);
        $this->assertIsArray($readResult->records);
        
        
        $this->assertCount(1, $readResult->records, 'Should have one record');
        
        $page = $readResult->records[0];
        $this->assertIsObject($page);
        
        // doktype should be converted to integer because all page types use integers
        $this->assertObjectHasProperty('doktype', $page);
        $this->assertIsInt($page->doktype, 'doktype should be converted to integer');
        $this->assertEquals(1, $page->doktype);
    }
    
    /**
     * Test that select fields with mixed values are NOT converted
     */
    public function testSelectFieldWithMixedValues(): void
    {
        // This test would require a custom table with mixed select values
        // For now, we'll test that string values remain strings
        
        // Tools will automatically switch to optimal workspace
        $writeTool = new WriteTableTool();
        $readTool = new ReadTableTool();
        
        // Create content with frame_class (which might have string values)
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Test Content',
                'CType' => 'text',
                'bodytext' => 'Test content',
                'frame_class' => 'default' // String value
            ]
        ]);
        
        $this->assertFalse($result->isError);
        $createdRecord = json_decode($result->content[0]->text);
        $contentUid = $createdRecord->uid;
        
        // Read it back
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid
        ]);
        
        $this->assertFalse($result->isError);
        $readResult = json_decode($result->content[0]->text);
        $content = $readResult->records[0];
        
        // frame_class should remain a string because it uses string values
        $this->assertIsString($content->frame_class, 'frame_class should remain a string');
        $this->assertEquals('default', $content->frame_class);
    }
    
    /**
     * Test fields with eval=int are always converted
     */
    public function testFieldsWithEvalInt(): void
    {
        // Tools will automatically switch to optimal workspace
        $writeTool = new WriteTableTool();
        $readTool = new ReadTableTool();
        
        // Create content with sorting (has eval=int)
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Test Sorting',
                'CType' => 'text',
                'sorting' => 256 // Integer field with eval=int
            ]
        ]);
        
        $this->assertFalse($result->isError);
        $createdRecord = json_decode($result->content[0]->text);
        $contentUid = $createdRecord->uid;
        
        // Read it back
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid
        ]);
        
        $this->assertFalse($result->isError);
        $readResult = json_decode($result->content[0]->text);
        $content = $readResult->records[0];
        
        // sorting should be integer due to eval=int
        $this->assertIsInt($content->sorting, 'sorting should be integer due to eval=int');
        $this->assertEquals(256, $content->sorting);
    }
}