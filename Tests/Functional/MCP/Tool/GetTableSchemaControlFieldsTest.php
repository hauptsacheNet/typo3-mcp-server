<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Mcp\Types\TextContent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetTableSchemaControlFieldsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Import backend user fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        
        // Set up backend user
        $this->setUpBackendUser(1);
    }

    /**
     * Test that control fields with LLL keys are properly translated
     */
    public function testControlFieldsAreTranslated(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for news table which has LLL keys in control fields
        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $content = $result->content[0]->text;
        
        // Verify control fields section exists
        $this->assertStringContainsString('CONTROL FIELDS:', $content);
        
        // Verify that the title field does NOT contain the raw LLL key
        $this->assertStringNotContainsString('title: LLL:EXT:news/Resources/Private/Language/locallang_db.xlf:tx_news_domain_model_news', $content);
        
        // Instead, it should contain the translated value
        // The exact translation may vary, but it should not be a LLL key
        if (preg_match('/title: (.+)$/m', $content, $matches)) {
            $titleValue = $matches[1];
            $this->assertStringNotContainsString('LLL:', $titleValue, 'Title field should be translated, not contain LLL key');
        } else {
            $this->fail('Could not find title field in control fields section');
        }
    }
}