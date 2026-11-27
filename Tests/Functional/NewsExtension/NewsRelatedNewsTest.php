<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * "Related news" is a group field with an MM table (tx_news_domain_model_news.related,
 * read from the other side as related_from). The column only holds the number of
 * relations, so ReadTable has to read the MM table to return uids instead of a count.
 */
class NewsRelatedNewsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'news',
        'mcp_server',
    ];

    private const MM_TABLE = 'tx_news_domain_model_news_related_mm';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    public function testReadRelatedNewsOfLiveRecord(): void
    {
        [$first, $second, $third] = $this->createLiveNews(['First', 'Second', 'Third']);
        $this->relateLive($first, [$second, $third]);

        $this->switchToWorkspace();

        $news = $this->readNews($first);
        $this->assertSame([$second, $third], $news['related']);

        // The other side of the relation reads the same MM table
        $this->assertSame([$first], $this->readNews($second)['related_from']);
        $this->assertSame([], $this->readNews($third)['related']);
    }

    public function testReadRelatedNewsWithoutWorkspace(): void
    {
        [$first, $second, $third] = $this->createLiveNews(['First', 'Second', 'Third']);
        $this->relateLive($first, [$third, $second]);

        $this->assertSame(0, (int)$GLOBALS['BE_USER']->workspace);
        $this->assertSame([$third, $second], $this->readNews($first)['related'], 'The MM sorting is kept');
    }

    public function testReadRelatedNewsWithFieldsFilter(): void
    {
        [$first, $second] = $this->createLiveNews(['First', 'Second']);
        $this->relateLive($first, [$second]);
        $this->switchToWorkspace();

        $result = $this->executeRead(['uid' => $first, 'fields' => ['related']]);
        $this->assertSame(['uid' => $first, 'related' => [$second]], $result['records'][0]);
    }

    public function testChangeRelatedNewsOfLiveRecordInWorkspace(): void
    {
        [$first, $second, $third] = $this->createLiveNews(['First', 'Second', 'Third']);
        $this->relateLive($first, [$second]);

        $this->switchToWorkspace();
        $this->writeRelated($first, [$third, $second]);

        $this->assertSame([$third, $second], $this->readNews($first)['related']);

        // Live keeps its relations until the workspace is published: the only MM row
        // between live records is still the one between the first and the second news.
        $liveUids = [$first, $second, $third];
        $liveRows = array_values(array_filter(
            GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(self::MM_TABLE)
                ->select(['uid_local', 'uid_foreign'], self::MM_TABLE)
                ->fetchAllAssociative(),
            static fn(array $row): bool => in_array((int)$row['uid_local'], $liveUids, true)
                && in_array((int)$row['uid_foreign'], $liveUids, true)
        ));
        $this->assertCount(1, $liveRows);
        $liveRow = array_map('intval', $liveRows[0]);
        sort($liveRow);
        $this->assertSame([$first, $second], $liveRow);
    }

    public function testReadRelatedNewsOfRecordCreatedInWorkspace(): void
    {
        [$second, $third] = $this->createLiveNews(['Second', 'Third']);
        $this->switchToWorkspace();

        $result = (new WriteTableTool())->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Created in workspace',
                'datetime' => time(),
                'related' => [$second, $third],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newUid = (int)json_decode($result->content[0]->text, true)['uid'];

        $this->assertSame([$second, $third], $this->readNews($newUid)['related']);
    }

    /**
     * In a workspace DataHandler stores a related record that has a workspace version by
     * the version's uid. The client must still get the live uid.
     */
    public function testRelatedNewsWithWorkspaceVersionIsReturnedByLiveUid(): void
    {
        [$first, $second, $third] = $this->createLiveNews(['First', 'Second', 'Third']);
        $this->relateLive($first, [$second]);
        $this->switchToWorkspace();

        $result = (new WriteTableTool())->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $second,
            'data' => ['title' => 'Second (workspace)'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->writeRelated($first, [$second, $third]);

        $this->assertSame([$second, $third], $this->readNews($first)['related']);
    }

    public function testRelatedNewsDeletedInWorkspaceIsDropped(): void
    {
        [$first, $second, $third] = $this->createLiveNews(['First', 'Second', 'Third']);
        $this->relateLive($first, [$second, $third]);
        $this->switchToWorkspace();

        $result = (new WriteTableTool())->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'delete',
            'uid' => $third,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertSame([$second], $this->readNews($first)['related']);
    }

    /**
     * @param string[] $titles
     * @return int[] Live uids in the order of the titles
     */
    private function createLiveNews(array $titles): array
    {
        $GLOBALS['BE_USER']->workspace = 0;
        $data = [];
        foreach ($titles as $index => $title) {
            $data['tx_news_domain_model_news']['NEW' . $index] = [
                'pid' => 1,
                'title' => $title,
                'datetime' => time(),
            ];
        }
        $dataHandler = $this->runDataHandler($data);

        return array_map(
            static fn(int $index): int => (int)$dataHandler->substNEWwithIDs['NEW' . $index],
            array_keys($titles)
        );
    }

    /**
     * @param int[] $related
     */
    private function relateLive(int $newsUid, array $related): void
    {
        $GLOBALS['BE_USER']->workspace = 0;
        $this->runDataHandler([
            'tx_news_domain_model_news' => [
                $newsUid => ['related' => implode(',', $related)],
            ],
        ]);
    }

    private function runDataHandler(array $data): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
        $this->assertSame([], $dataHandler->errorLog, 'DataHandler errors: ' . implode(', ', $dataHandler->errorLog));

        return $dataHandler;
    }

    private function switchToWorkspace(): void
    {
        GeneralUtility::makeInstance(WorkspaceContextService::class)->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $this->assertGreaterThan(0, (int)$GLOBALS['BE_USER']->workspace);
    }

    /**
     * @param int[] $related
     */
    private function writeRelated(int $newsUid, array $related): void
    {
        $result = (new WriteTableTool())->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => ['related' => $related],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    private function readNews(int $uid): array
    {
        $result = $this->executeRead(['uid' => $uid]);
        $this->assertCount(1, $result['records']);
        $this->assertSame($uid, (int)$result['records'][0]['uid'], 'The client sees the live uid');

        return $result['records'][0];
    }

    private function executeRead(array $params): array
    {
        $result = (new ReadTableTool())->execute(['table' => 'tx_news_domain_model_news'] + $params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return json_decode($result->content[0]->text, true);
    }
}
