<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Mcp\Types\TextContent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetTableSchemaNewsTest extends FunctionalTestCase
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
     * Test that news bodytext field shows richtext and typolink support
     */
    public function testNewsBodytextShowsRichtextAndTypolinkSupport(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for news table
        $result = $tool->execute([
            'table' => 'tx_news_domain_model_news'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        
        $content = $result->content[0]->text;
        
        // Verify bodytext field is present
        $this->assertStringContainsString('bodytext', $content);
        
        // Verify richtext indicator is shown
        $this->assertMatchesRegularExpression('/bodytext.*\[Richtext\/HTML\]/', $content);
        
        // Verify typolink support is indicated
        $this->assertMatchesRegularExpression('/bodytext.*\[Supports typolinks/', $content);
        
        // Verify typolink examples are included
        $this->assertStringContainsString('t3://page?uid=123', $content);
        $this->assertStringContainsString('t3://record?identifier=table&uid=456', $content);
        $this->assertStringContainsString('t3://file?uid=789', $content);
        $this->assertStringContainsString('https://example.com', $content);
        $this->assertStringContainsString('mailto:email@example.com', $content);
    }

    public function testTypeZeroIsSelectedWhenItIsNotTheFirstType(): void
    {
        // Move type "0" to the end, so a fallback to the first type would pick another one
        $items = $GLOBALS['TCA']['tx_news_domain_model_news']['columns']['type']['config']['items'];
        $zero = array_values(array_filter($items, static fn(array $item): bool => (string)($item['value'] ?? '') === '0'));
        $this->assertNotEmpty($zero, 'news must have a type "0"');
        $GLOBALS['TCA']['tx_news_domain_model_news']['columns']['type']['config']['items'] = array_merge(
            array_values(array_filter($items, static fn(array $item): bool => (string)($item['value'] ?? '') !== '0')),
            $zero
        );

        $result = (new GetTableSchemaTool())->execute([
            'table' => 'tx_news_domain_model_news',
            'type' => '0',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        $this->assertMatchesRegularExpression('/^Type: 0 \(/m', $content);
        $this->assertMatchesRegularExpression('/datetime \(.*\[Required\]/', $content);
        $this->assertDoesNotMatchRegularExpression('/^\s*(?:-|├─|└─) internalurl /mu', $content);
    }

    public function testNewsTypeZeroListsOnlyItsOwnFields(): void
    {
        $result = (new GetTableSchemaTool())->execute([
            'table' => 'tx_news_domain_model_news',
            'type' => '0',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        $this->assertMatchesRegularExpression('/datetime \(.*\[Required\]/', $content);
        $this->assertDoesNotMatchRegularExpression('/^\s*(?:-|├─|└─) internalurl /mu', $content);
        $this->assertDoesNotMatchRegularExpression('/^\s*(?:-|├─|└─) externalurl /mu', $content);
    }
}
