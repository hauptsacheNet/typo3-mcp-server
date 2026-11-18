<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test embedded inline relations with tx_news_domain_model_link (hideTable=true)
 */
class NewsLinkInlineTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        // Don't import workspace fixture - let WorkspaceContextService handle it
        $this->setUpBackendUser(1);
        // Don't set workspace - let the MCP tools handle it automatically
    }

    /**
     * Test creating news with embedded related_links
     */
    public function testCreateNewsWithEmbeddedLinks(): void
    {
        // The WorkspaceContextService will handle workspace creation automatically
        
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create news with embedded links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with embedded links',
                'bodytext' => 'This news has related links',
                'related_links' => [
                    [
                        'title' => 'External link',
                        'uri' => 'https://example.com',
                        'description' => 'Link to external website'
                    ],
                    [
                        'title' => 'Internal page',
                        'uri' => 't3://page?uid=42',
                        'description' => 'Link to internal page'
                    ],
                    [
                        'title' => 'Email link',
                        'uri' => 'mailto:info@example.com'
                    ]
                ]
            ],
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Read the news record (ReadTableTool will automatically use the same workspace)
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        $response = json_decode($result->content[0]->text, true);
        $this->assertArrayHasKey('records', $response, 'Response should have records key. Got: ' . json_encode($response));
        $this->assertNotEmpty($response['records'], 'Records array should not be empty');
        $news = $response['records'][0];
        
        // Verify related_links contains full embedded records
        $this->assertArrayHasKey('related_links', $news, 'News should have related_links field');
        $this->assertIsArray($news['related_links']);
        $this->assertCount(3, $news['related_links']);
        
        // Create a map of links by title for order-independent testing
        $linksByTitle = [];
        foreach ($news['related_links'] as $link) {
            $this->assertIsArray($link);
            $this->assertArrayHasKey('title', $link);
            $linksByTitle[$link['title']] = $link;
        }
        
        // Verify External link. The foreign field 'parent' is intentionally
        // dropped from embedded children — the parent is implied by the embedding.
        $this->assertArrayHasKey('External link', $linksByTitle);
        $externalLink = $linksByTitle['External link'];
        $this->assertArrayHasKey('uid', $externalLink);
        $this->assertArrayHasKey('uri', $externalLink);
        $this->assertArrayHasKey('description', $externalLink);
        $this->assertArrayNotHasKey('parent', $externalLink);
        $this->assertEquals('https://example.com', $externalLink['uri']);
        $this->assertEquals('Link to external website', $externalLink['description']);

        // Verify Internal page link
        $this->assertArrayHasKey('Internal page', $linksByTitle);
        $internalLink = $linksByTitle['Internal page'];
        $this->assertEquals('t3://page?uid=42', $internalLink['uri']);
        $this->assertEquals('Link to internal page', $internalLink['description']);

        // Verify Email link (no description)
        $this->assertArrayHasKey('Email link', $linksByTitle);
        $emailLink = $linksByTitle['Email link'];
        $this->assertEquals('mailto:info@example.com', $emailLink['uri']);
    }

    /**
     * Test updating news with embedded links
     */
    public function testUpdateNewsWithEmbeddedLinks(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create initial news
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News to update with links',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Update with embedded links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'related_links' => [
                    [
                        'title' => 'Documentation',
                        'uri' => 'https://docs.typo3.org',
                        'description' => 'TYPO3 documentation'
                    ],
                    [
                        'title' => 'GitHub',
                        'uri' => 'https://github.com/typo3/typo3',
                    ]
                ]
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Read and verify
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertCount(2, $news['related_links']);
        
        // Create a map by title to check order-independently
        $linksByTitle = [];
        foreach ($news['related_links'] as $link) {
            $linksByTitle[$link['title']] = $link;
        }
        
        $this->assertArrayHasKey('Documentation', $linksByTitle);
        $this->assertArrayHasKey('GitHub', $linksByTitle);
        $this->assertEquals('https://docs.typo3.org', $linksByTitle['Documentation']['uri']);
        $this->assertEquals('TYPO3 documentation', $linksByTitle['Documentation']['description']);
        $this->assertEquals('https://github.com/typo3/typo3', $linksByTitle['GitHub']['uri']);
    }

    /**
     * Test removing all embedded links
     */
    public function testRemoveAllEmbeddedLinks(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Create news with links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with links to remove',
                'related_links' => [
                    ['title' => 'Link 1', 'uri' => 'https://example1.com'],
                    ['title' => 'Link 2', 'uri' => 'https://example2.com']
                ]
            ],
        ]);
        $newsUid = json_decode($result->content[0]->text, true)['uid'];
        
        // Update with empty array to remove all links
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'related_links' => []  // Empty array removes all
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Verify links are removed
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);
        
        $news = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertArrayHasKey('related_links', $news, 'Should have related_links field');
        $this->assertEmpty($news['related_links'], 'related_links should be empty when all are removed');
    }

    /**
     * Embedded children must come out in the order they were passed in, not reversed.
     *
     * tx_news_domain_model_link declares `ctrl.sortby = 'sorting'` and isn't a
     * `foreign_match_fields` relation (no tablenames/fieldname), making it the
     * real-world case for the "embedded inline children created in reverse order" bug:
     * DataHandler auto-manages that table's own sortby field, overwriting whatever
     * WriteTableTool writes to it with its own "insert at top" number, so array order
     * has to be enforced separately.
     */
    public function testEmbeddedLinksSorting(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create news with links in specific order
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with sorted links',
                'related_links' => [
                    ['title' => 'First link', 'uri' => 'https://first.com'],
                    ['title' => 'Second link', 'uri' => 'https://second.com'],
                    ['title' => 'Third link', 'uri' => 'https://third.com']
                ]
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];

        // Read and verify order
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
        ]);

        $news = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertCount(3, $news['related_links']);

        $titles = array_column($news['related_links'], 'title');
        $this->assertSame(
            ['First link', 'Second link', 'Third link'],
            $titles,
            'Embedded links must be returned in the order they were passed in. Actual order: ' . json_encode($titles)
        );

        // The underlying "sorting" column (tx_news_domain_model_link.ctrl.sortby) must
        // itself be ascending in array order, not just however ReadTableTool happens to
        // return rows.
        $queryBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
            ->getQueryBuilderForTable('tx_news_domain_model_link');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder->select('title', 'sorting')
            ->from('tx_news_domain_model_link')
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $this->assertSame(
            ['First link', 'Second link', 'Third link'],
            array_column($rows, 'title'),
            'Sorting values must ascend in array order. Rows: ' . json_encode($rows)
        );
    }

    /**
     * Reordering existing embedded children by uid must actually take effect.
     *
     * Before the fix this had no effect for a table like tx_news_domain_model_link
     * that manages its own sortby field: DataHandler::fillInFieldArray() silently
     * drops a manually written value for a field that isn't a real TCA column, so
     * the only way to reorder was to delete and recreate the children.
     */
    public function testReorderExistingLinksByArrayOrder(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News for reorder',
                'related_links' => [
                    ['title' => 'First link', 'uri' => 'https://first.com'],
                    ['title' => 'Second link', 'uri' => 'https://second.com'],
                    ['title' => 'Third link', 'uri' => 'https://third.com'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text, true)['uid'];

        $result = $readTool->execute(['table' => 'tx_news_domain_model_news', 'uid' => $newsUid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $news = json_decode($result->content[0]->text, true)['records'][0];
        $byTitle = [];
        foreach ($news['related_links'] as $link) {
            $byTitle[$link['title']] = (int)$link['uid'];
        }

        // Reorder to Third, First, Second by passing back only the existing uids —
        // no field changes, so this exercises the escape hatch that used to be a no-op.
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'related_links' => [
                    ['uid' => $byTitle['Third link']],
                    ['uid' => $byTitle['First link']],
                    ['uid' => $byTitle['Second link']],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $result = $readTool->execute(['table' => 'tx_news_domain_model_news', 'uid' => $newsUid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $news = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertCount(3, $news['related_links'], 'No links should be lost during reorder');

        // Assert the uids themselves, not just the titles — a delete-and-recreate
        // implementation could pass a title-only check while discarding the
        // original child records.
        $this->assertSame(
            [$byTitle['Third link'], $byTitle['First link'], $byTitle['Second link']],
            array_map('intval', array_column($news['related_links'], 'uid')),
            'Reordering must retain the existing child records, not delete and recreate them.'
        );

        $titles = array_column($news['related_links'], 'title');
        $this->assertSame(
            ['Third link', 'First link', 'Second link'],
            $titles,
            'Embedded children must follow the order supplied in the update payload. Actual order: ' . json_encode($titles)
        );
    }

    /**
     * Test validation errors for embedded links
     */
    public function testEmbeddedLinksValidationErrors(): void
    {
        // Test validation for embedded relations
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        
        // Test non-array value
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with invalid links',
                'related_links' => 'not-an-array'
            ],
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('must be an array', $result->jsonSerialize()['content'][0]->text);
        
        // Test array with non-array items
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with invalid link items',
                'related_links' => [
                    'not-an-array',
                    ['title' => 'Valid link', 'uri' => 'https://example.com']
                ]
            ],
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('must contain record data arrays', $result->jsonSerialize()['content'][0]->text);
        
        // Test empty record data
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with empty link',
                'related_links' => [
                    []  // Empty array
                ]
            ],
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('is empty', $result->jsonSerialize()['content'][0]->text);
    }
}