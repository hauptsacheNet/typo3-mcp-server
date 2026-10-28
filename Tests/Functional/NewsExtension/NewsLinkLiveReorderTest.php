<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Reordering embedded inline children that already exist LIVE, from inside a workspace.
 *
 * The tests in NewsLinkInlineTest only reorder children that were created (and never
 * published) in the workspace. The real-world case is a news record whose links are
 * already live: reordering them via WriteTable must stage the new order in the
 * workspace (move versions / sorting overlays), leave live untouched, read back in
 * the new order transparently, and land in live after publishing.
 */
class NewsLinkLiveReorderTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'news',
        'mcp_server',
    ];

    private const NEWS_UID = 100;
    private const LINK_FIRST = 201;
    private const LINK_SECOND = 202;
    private const LINK_THIRD = 203;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        // Live parent with three live children (sorting 256/512/768 = First, Second, Third)
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connectionPool->getConnectionForTable('tx_news_domain_model_news')->insert('tx_news_domain_model_news', [
            'uid' => self::NEWS_UID,
            'pid' => 1,
            'title' => 'Live news with live links',
            'related_links' => 3,
            'tstamp' => 1734875000,
            'crdate' => 1734875000,
        ]);
        $linkConnection = $connectionPool->getConnectionForTable('tx_news_domain_model_link');
        foreach ([
            [self::LINK_FIRST, 'First link', 256],
            [self::LINK_SECOND, 'Second link', 512],
            [self::LINK_THIRD, 'Third link', 768],
        ] as [$uid, $title, $sorting]) {
            $linkConnection->insert('tx_news_domain_model_link', [
                'uid' => $uid,
                'pid' => 1,
                'parent' => self::NEWS_UID,
                'title' => $title,
                'uri' => 'https://example.com/' . $uid,
                'sorting' => $sorting,
                'tstamp' => 1734875000,
                'crdate' => 1734875000,
            ]);
        }
    }

    /**
     * Reordering live children inside a workspace must be read back (workspace
     * transparent, live uids) in the new order, without duplicates.
     *
     * The reorder issues DataHandler move commands, which stage move pointers
     * (t3ver_state=4) for the live children. WorkspaceRestriction admits both the
     * live rows and the move pointers; WorkspaceDeletePlaceholderRestriction drops
     * the superseded live rows, so each child is read once from its move pointer,
     * which carries the workspace sorting.
     */
    public function testReorderedLiveLinksAreReadBackInNewOrderWithoutDuplicates(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        // Sanity check: initial order as read through the tool
        $this->assertSame(
            [self::LINK_FIRST, self::LINK_SECOND, self::LINK_THIRD],
            array_map('intval', array_column($this->readLinksViaTool($readTool), 'uid'))
        );

        $this->reorderLinksViaTool();

        $links = $this->readLinksViaTool($readTool);
        $uids = array_map('intval', array_column($links, 'uid'));
        $this->assertSame(
            [self::LINK_THIRD, self::LINK_FIRST, self::LINK_SECOND],
            $uids,
            'Workspace read must return exactly the three live uids in the new order. Got: ' . json_encode($links)
        );
    }

    /**
     * Reorder live children, drop one and add a new one in a single write. The read
     * must contain the kept live children and the new child in the requested order,
     * without the dropped child and without duplicates; live stays untouched.
     */
    public function testReorderWithDroppedAndNewLinkIsReadBackInWorkspaceOrder(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => self::NEWS_UID,
            'data' => [
                'related_links' => [
                    ['uid' => self::LINK_THIRD],
                    ['title' => 'New link', 'uri' => 'https://example.com/new'],
                    ['uid' => self::LINK_FIRST],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $links = $this->readLinksViaTool(GeneralUtility::makeInstance(ReadTableTool::class));
        $this->assertSame(
            ['Third link', 'New link', 'First link'],
            array_column($links, 'title'),
            'Workspace read must reflect the new order, the new link and the removal. Got: ' . json_encode($links)
        );
        $uids = array_map('intval', array_column($links, 'uid'));
        $this->assertSame(self::LINK_THIRD, $uids[0]);
        $this->assertSame(self::LINK_FIRST, $uids[2]);
        $this->assertNotContains(self::LINK_SECOND, $uids);

        $this->assertSame(
            [self::LINK_FIRST, self::LINK_SECOND, self::LINK_THIRD],
            $this->getLiveLinkOrder(),
            'Live must not change before publishing'
        );
    }

    /**
     * Reordering live children inside a workspace must leave live untouched and,
     * once the workspace is published, live must have the new order.
     */
    public function testReorderedLiveLinksLeaveLiveUntouchedAndPublishNewOrder(): void
    {
        $workspaceId = $this->reorderLinksViaTool();

        // Live records untouched before publishing
        $liveRows = $this->getLiveLinkRows();
        $this->assertSame(
            [self::LINK_FIRST, self::LINK_SECOND, self::LINK_THIRD],
            array_map('intval', array_column($liveRows, 'uid')),
            'Live order must not change before publishing'
        );
        $this->assertSame([256, 512, 768], array_map('intval', array_column($liveRows, 'sorting')));

        // Publish the whole workspace (same command array the Workspaces module builds)
        $cmd = GeneralUtility::makeInstance(WorkspaceService::class)->getCmdArrayForPublishWS($workspaceId);
        $this->assertNotEmpty($cmd, 'Workspace should contain versions to publish');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();
        $this->assertEmpty($dataHandler->errorLog, 'Publishing failed: ' . json_encode($dataHandler->errorLog));

        $liveRows = $this->getLiveLinkRows();
        $this->assertSame(
            [self::LINK_THIRD, self::LINK_FIRST, self::LINK_SECOND],
            array_map('intval', array_column($liveRows, 'uid')),
            'After publishing, live must contain exactly the three links in the new order. Rows: ' . json_encode($liveRows)
        );
    }

    /**
     * Reorder to Third, First, Second via WriteTable (works in a workspace).
     *
     * @return int the workspace the tool wrote into
     */
    private function reorderLinksViaTool(): int
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => self::NEWS_UID,
            'data' => [
                'related_links' => [
                    ['uid' => self::LINK_THIRD],
                    ['uid' => self::LINK_FIRST],
                    ['uid' => self::LINK_SECOND],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $workspaceId = (int)$GLOBALS['BE_USER']->workspace;
        $this->assertGreaterThan(0, $workspaceId, 'WriteTable must operate in a workspace');
        return $workspaceId;
    }

    private function readLinksViaTool(ReadTableTool $readTool): array
    {
        $result = $readTool->execute(['table' => 'tx_news_domain_model_news', 'uid' => self::NEWS_UID]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return json_decode($result->content[0]->text, true)['records'][0]['related_links'];
    }

    /**
     * @return list<int> live link uids of the news record, ordered by sorting
     */
    private function getLiveLinkOrder(): array
    {
        return array_map('intval', array_column($this->getLiveLinkRows(), 'uid'));
    }

    private function getLiveLinkRows(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_news_domain_model_link');
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder->select('uid', 'pid', 'title', 'sorting', 't3ver_state')
            ->from('tx_news_domain_model_link')
            ->where(
                $queryBuilder->expr()->eq('parent', self::NEWS_UID),
                $queryBuilder->expr()->eq('t3ver_wsid', 0),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
