<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test the fields parameter for ReadTableTool with embedded relations
 */
class ReadTableFieldFilterTest extends FunctionalTestCase
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
        $this->setUpBackendUser(1);
    }

    /**
     * Test that fields parameter excludes embedded relations when not requested
     */
    public function testFieldsParameterExcludesEmbeddedRelations(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create news with embedded links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with links',
                'bodytext' => 'Some text',
                'related_links' => [
                    ['title' => 'Link 1', 'uri' => 'https://example.com'],
                    ['title' => 'Link 2', 'uri' => 'https://example.org'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];

        // Read without related_links in fields list
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'fields' => ['title', 'bodytext'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $news = json_decode($result->content[0]->text, true)['records'][0];

        // Requested fields should be present
        $this->assertArrayHasKey('title', $news);
        $this->assertArrayHasKey('bodytext', $news);

        // Embedded relation should NOT be present since it was not requested
        $this->assertArrayNotHasKey('related_links', $news, 'Embedded relation should be excluded when not in fields list');
    }

    /**
     * Test that fields parameter includes embedded relations when requested
     */
    public function testFieldsParameterIncludesEmbeddedRelations(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create news with embedded links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with links to include',
                'bodytext' => 'Some text',
                'related_links' => [
                    ['title' => 'Included Link', 'uri' => 'https://included.com'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];

        // Read WITH related_links in fields list
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'fields' => ['title', 'related_links'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $news = json_decode($result->content[0]->text, true)['records'][0];

        // Requested fields should be present
        $this->assertArrayHasKey('title', $news);
        $this->assertArrayHasKey('related_links', $news);

        // Embedded records should be fully included
        $this->assertIsArray($news['related_links']);
        $this->assertCount(1, $news['related_links']);
        $this->assertEquals('Included Link', $news['related_links'][0]['title']);

        // Non-requested field should be excluded
        $this->assertArrayNotHasKey('bodytext', $news, 'Non-requested field bodytext should be excluded');
    }

    /**
     * Test that fields parameter excludes independent inline relations (UIDs) when not requested
     */
    public function testFieldsParameterExcludesIndependentRelations(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create a news record
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with content elements',
                'bodytext' => 'Some text',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];

        // Create a content element related to the news
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Related CE',
                'CType' => 'text',
                'tx_news_related_news' => $newsUid,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read news without content_elements in fields list
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'fields' => ['title', 'bodytext'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $news = json_decode($result->content[0]->text, true)['records'][0];

        // content_elements should NOT be resolved since it wasn't requested
        $this->assertArrayNotHasKey('content_elements', $news, 'Independent relation should be excluded when not in fields list');
    }
}
