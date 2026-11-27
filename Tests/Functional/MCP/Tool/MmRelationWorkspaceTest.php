<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test MM relation handling in workspace and translation contexts.
 *
 * These tests verify that MM relations work correctly when:
 * - Existing live records are updated in workspace context
 * - Records with MM relations are translated
 * - Translations get independent MM relations (allowLanguageSynchronization override)
 */
class MmRelationWorkspaceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        'news',
    ];

    protected WriteTableTool $writeTool;
    protected ReadTableTool $readTool;
    protected WorkspaceContextService $workspaceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createMultiLanguageSiteConfiguration();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category.csv');

        $this->setUpBackendUser(1);

        // Required by DataHandler for localization operations
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->writeTool = new WriteTableTool();
        $this->readTool = new ReadTableTool();
        $this->workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
    }

    protected function createMultiLanguageSiteConfiguration(): void
    {
        $siteConfiguration = [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'websiteTitle' => 'Test Site',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'hreflang' => 'en-us',
                    'direction' => 'ltr',
                    'flag' => 'us',
                    'navigationTitle' => 'English',
                ],
                1 => [
                    'title' => 'German',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de/',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'hreflang' => 'de-de',
                    'direction' => 'ltr',
                    'flag' => 'de',
                    'navigationTitle' => 'Deutsch',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ];

        $configPath = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($configPath);

        $yamlContent = Yaml::dump($siteConfiguration, 99, 2);
        GeneralUtility::writeFile($configPath . '/config.yaml', $yamlContent, true);
    }

    /**
     * Test updating opposite MM relations (categories) on an existing live record in workspace.
     *
     * 1. Create a record in live (no workspace)
     * 2. Switch to workspace
     * 3. Update the record's MM relations
     * 4. Read back with includeRelations — should see workspace changes, not live data
     */
    public function testUpdateOppositeMmOnLiveRecordInWorkspace(): void
    {
        // Create in live
        $GLOBALS['BE_USER']->workspace = 0;

        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Live News',
                'datetime' => time(),
                'categories' => [1, 2],
            ]
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text)->uid;

        // Switch to workspace
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $this->assertGreaterThan(0, $GLOBALS['BE_USER']->workspace);

        // Update categories in workspace
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => ['categories' => [3, 4, 5]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read back — should see workspace categories
        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'includeRelations' => true,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $news = json_decode($result->content[0]->text)->records[0];

        $this->assertEquals($newsUid, $news->uid, 'Client should see live UID');
        $this->assertCount(3, $news->categories, 'Should see 3 workspace categories, not 2 live');
        $this->assertEquals([3, 4, 5], $news->categories);
    }

    /**
     * Test updating standard MM relations (tags) on an existing live record in workspace.
     */
    public function testUpdateStandardMmOnLiveRecordInWorkspace(): void
    {
        $GLOBALS['BE_USER']->workspace = 0;

        $tagUids = [];
        foreach (['Tag A', 'Tag B', 'Tag C', 'Tag D'] as $title) {
            $result = $this->writeTool->execute([
                'table' => 'tx_news_domain_model_tag',
                'action' => 'create',
                'pid' => 1,
                'data' => ['title' => $title],
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $tagUids[] = json_decode($result->content[0]->text)->uid;
        }

        // Create news with tags A,B in live
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Live News with Tags',
                'datetime' => time(),
                'tags' => [$tagUids[0], $tagUids[1]],
            ]
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text)->uid;

        // Switch to workspace and change to tags C,D
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => ['tags' => [$tagUids[2], $tagUids[3]]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read back — should see workspace tags C,D
        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'includeRelations' => true,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $news = json_decode($result->content[0]->text)->records[0];

        $this->assertEquals($newsUid, $news->uid);
        $this->assertCount(2, $news->tags);
        $this->assertEquals([$tagUids[2], $tagUids[3]], $news->tags);
    }

    /**
     * Read back MM relations of a record that is really live after changing them in a workspace.
     *
     * The tests above create their "live" record through WriteTableTool, which always
     * writes into the workspace: such a record is new in the workspace and keeps a single
     * uid. A record that already exists live gets a workspace version with a uid of its
     * own, and DataHandler attaches the changed MM relations to that version uid.
     */
    public function testReadMmOfLiveRecordAfterWorkspaceUpdate(): void
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $mmTable = $this->insertLiveNewsWithTags();

        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $this->assertGreaterThan(0, $GLOBALS['BE_USER']->workspace);

        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => 201,
            'data' => ['tags' => [103, 104]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => 201,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $news = json_decode($result->content[0]->text)->records[0];

        $this->assertEquals(201, $news->uid, 'Client should see the live uid');
        $this->assertEquals([103, 104], $news->tags, 'Should see the workspace tags, not the live ones');

        // Live keeps its tags until the workspace is published
        $liveTags = $connectionPool->getConnectionForTable($mmTable)
            ->select(['uid_foreign'], $mmTable, ['uid_local' => 201], [], ['sorting' => 'ASC'])
            ->fetchFirstColumn();
        $this->assertEquals([101, 102], array_map('intval', $liveTags));
    }

    /**
     * A related record with a workspace version must still be returned by its live uid.
     *
     * In a workspace DataHandler stores the related side of an MM relation by the uid of
     * the related record's workspace version (RelationHandler::convertItemArray()).
     */
    public function testReadMmReturnsLiveUidsOfRelatedWorkspaceVersions(): void
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $mmTable = $this->insertLiveNewsWithTags();

        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $workspaceId = (int)$GLOBALS['BE_USER']->workspace;
        $this->assertGreaterThan(0, $workspaceId);

        // Tag C gets a workspace version of its own
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_tag',
            'action' => 'update',
            'uid' => 103,
            'data' => ['title' => 'Tag C (workspace)'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => 201,
            'data' => ['tags' => [103, 104]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // The MM row of the news version points to the version of tag C
        $tagVersion = BackendUtility::getWorkspaceVersionOfRecord($workspaceId, 'tx_news_domain_model_tag', 103, 'uid');
        $newsVersion = BackendUtility::getWorkspaceVersionOfRecord($workspaceId, 'tx_news_domain_model_news', 201, 'uid');
        $this->assertIsArray($tagVersion);
        $this->assertIsArray($newsVersion);
        $storedTags = $connectionPool->getConnectionForTable($mmTable)
            ->select(['uid_foreign'], $mmTable, ['uid_local' => (int)$newsVersion['uid']], [], ['sorting' => 'ASC'])
            ->fetchFirstColumn();
        $this->assertEquals([(int)$tagVersion['uid'], 104], array_map('intval', $storedTags));

        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => 201,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $news = json_decode($result->content[0]->text)->records[0];

        $this->assertEquals([103, 104], $news->tags, 'Related records must be returned by their live uid');
    }

    /**
     * Editing a category in the workspace copies its MM rows to the category's version.
     * Reading a news record that is not versioned itself must still list each category once.
     */
    public function testReadMmIgnoresCopiedRelationsOfVersionedRelatedRecord(): void
    {
        $this->insertLiveNewsWithTags();
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        $result = $this->writeTool->execute([
            'table' => 'sys_category',
            'action' => 'update',
            'uid' => 1,
            'data' => ['title' => 'Technology (workspace)'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $news = $this->readNews201();
        $this->assertEquals([1, 2], $news->categories);
        $this->assertEquals([101, 102], $news->tags);
    }

    /**
     * Versions of related records in another workspace must not show up.
     */
    public function testReadMmIgnoresRelatedVersionsOfOtherWorkspace(): void
    {
        $this->insertLiveNewsWithTags();
        $workspaceId = $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_workspace')
            ->insert('sys_workspace', ['uid' => 77, 'pid' => 0, 'title' => 'Other workspace']);
        $GLOBALS['BE_USER']->setWorkspace(77);
        foreach ([['sys_category', 1], ['tx_news_domain_model_tag', 101]] as [$relatedTable, $relatedUid]) {
            $result = $this->writeTool->execute([
                'table' => $relatedTable,
                'action' => 'update',
                'uid' => $relatedUid,
                'data' => ['title' => 'Changed in workspace 77'],
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        }
        $this->assertSame(77, (int)$GLOBALS['BE_USER']->workspace, 'The writes must go to the other workspace');

        $GLOBALS['BE_USER']->setWorkspace($workspaceId);
        $news = $this->readNews201();
        $this->assertEquals([1, 2], $news->categories);
        $this->assertEquals([101, 102], $news->tags);
    }

    /**
     * Related records deleted in the workspace are no longer related.
     */
    public function testReadMmDropsRelatedRecordsDeletedInWorkspace(): void
    {
        $this->insertLiveNewsWithTags();
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        foreach ([['tx_news_domain_model_tag', 102], ['sys_category', 2]] as [$relatedTable, $relatedUid]) {
            $result = $this->writeTool->execute([
                'table' => $relatedTable,
                'action' => 'delete',
                'uid' => $relatedUid,
            ]);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        }

        $news = $this->readNews201();
        $this->assertEquals([101], $news->tags);
        $this->assertEquals([1], $news->categories);
    }

    private function readNews201(): object
    {
        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => 201,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return json_decode($result->content[0]->text)->records[0];
    }

    /**
     * A live news record with tags A (101), B (102) and categories 1, 2, plus two more live
     * tags C (103), D (104), written directly to the database so they never touch a workspace.
     *
     * @return string The MM table of the news tags
     */
    private function insertLiveNewsWithTags(): string
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $mmTable = $GLOBALS['TCA']['tx_news_domain_model_news']['columns']['tags']['config']['MM'];

        foreach ([101 => 'Tag A', 102 => 'Tag B', 103 => 'Tag C', 104 => 'Tag D'] as $uid => $title) {
            $connectionPool->getConnectionForTable('tx_news_domain_model_tag')
                ->insert('tx_news_domain_model_tag', ['uid' => $uid, 'pid' => 1, 'title' => $title]);
        }
        $connectionPool->getConnectionForTable('tx_news_domain_model_news')->insert('tx_news_domain_model_news', [
            'uid' => 201,
            'pid' => 1,
            'title' => 'Really live news',
            'datetime' => time(),
            'tags' => 2,
            'categories' => 2,
        ]);
        foreach ([101, 102] as $sorting => $tagUid) {
            $connectionPool->getConnectionForTable($mmTable)
                ->insert($mmTable, ['uid_local' => 201, 'uid_foreign' => $tagUid, 'sorting' => $sorting + 1]);
        }
        foreach ([1, 2] as $sorting => $categoryUid) {
            $connectionPool->getConnectionForTable('sys_category_record_mm')->insert('sys_category_record_mm', [
                'uid_local' => $categoryUid,
                'uid_foreign' => 201,
                'tablenames' => 'tx_news_domain_model_news',
                'fieldname' => 'categories',
                'sorting_foreign' => $sorting + 1,
            ]);
            $connectionPool->getConnectionForTable('sys_category')->update('sys_category', ['items' => 1], ['uid' => $categoryUid]);
        }

        return $mmTable;
    }

    /**
     * Test that translating a record copies its MM relations to the translation.
     */
    public function testTranslateRecordCopiesMmRelations(): void
    {
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'English News',
                'datetime' => time(),
                'categories' => [1, 2, 3],
            ]
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text)->uid;

        // Translate to German
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'translate',
            'uid' => $newsUid,
            'data' => ['sys_language_uid' => 'de'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $translationUid = json_decode($result->content[0]->text)->translationUid;

        // Translation should have the same categories as the source
        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $translationUid,
            'includeRelations' => true,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $translation = json_decode($result->content[0]->text)->records[0];

        $this->assertIsArray($translation->categories);
        $this->assertCount(3, $translation->categories);
        $this->assertEquals([1, 2, 3], $translation->categories);
    }

    /**
     * Test that translations can override MM relations independently of the source.
     *
     * News extension uses allowLanguageSynchronization for categories and tags.
     * The WriteTableTool must automatically set l10n_state to "custom" so
     * DataHandler doesn't discard the explicit values sent by the MCP client.
     */
    public function testTranslationCanOverrideSyncedMmRelations(): void
    {
        // Create news with categories [1,2]
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'English News',
                'datetime' => time(),
                'categories' => [1, 2],
            ]
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text)->uid;

        // Translate to German
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'translate',
            'uid' => $newsUid,
            'data' => ['sys_language_uid' => 'de'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $translationUid = json_decode($result->content[0]->text)->translationUid;

        // Update translation's categories to different values
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $translationUid,
            'data' => ['categories' => [3, 4, 5]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Source should still have [1,2]
        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'includeRelations' => true,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $source = json_decode($result->content[0]->text)->records[0];
        $this->assertEquals([1, 2], $source->categories, 'Source should be unchanged');

        // Translation should have [3,4,5]
        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $translationUid,
            'includeRelations' => true,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $translation = json_decode($result->content[0]->text)->records[0];
        $this->assertEquals([3, 4, 5], $translation->categories, 'Translation should have independent categories');
    }

    /**
     * Test workspace + translation combined: translate a live record in workspace,
     * then update the source's MM relations.
     */
    public function testWorkspaceTranslationMmRelations(): void
    {
        // Create in live
        $GLOBALS['BE_USER']->workspace = 0;

        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Live English News',
                'datetime' => time(),
                'categories' => [1, 2],
            ]
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text)->uid;

        // Switch to workspace
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        // Translate in workspace
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'translate',
            'uid' => $newsUid,
            'data' => ['sys_language_uid' => 'de'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $translationUid = json_decode($result->content[0]->text)->translationUid;

        // Update source record's categories in workspace
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => ['categories' => [3, 4, 5]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Source should see workspace categories
        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'includeRelations' => true,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $source = json_decode($result->content[0]->text)->records[0];
        $this->assertEquals([3, 4, 5], $source->categories);

        // Translation should have its own categories (copied at translation time)
        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $translationUid,
            'includeRelations' => true,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $translation = json_decode($result->content[0]->text)->records[0];
        $this->assertIsArray($translation->categories, 'Translation should have categories');
    }

    /**
     * Test updating a live record with BOTH MM relations AND inline relations in workspace.
     *
     * Reproduces GitHub issue #19: "WriteTable fails on existing live records
     * with MM/inline relations (update & translate)".
     */
    public function testUpdateLiveRecordWithMmAndInlineInWorkspace(): void
    {
        // Create in live with categories (opposite MM) and related_links (inline)
        $GLOBALS['BE_USER']->workspace = 0;

        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Live News with MM and Inline',
                'datetime' => time(),
                'categories' => [1, 2],
                'related_links' => [
                    ['uri' => 'https://example.com', 'title' => 'Example'],
                ],
            ]
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text)->uid;

        // Switch to workspace
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $this->assertGreaterThan(0, $GLOBALS['BE_USER']->workspace);

        // Update both MM and inline in workspace
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => [
                'title' => 'Updated News in Workspace',
                'categories' => [3, 4, 5],
                'related_links' => [
                    ['uri' => 'https://workspace.example.com', 'title' => 'Workspace Link'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read back — should see workspace data
        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'includeRelations' => true,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $news = json_decode($result->content[0]->text)->records[0];

        $this->assertEquals($newsUid, $news->uid, 'Client should see live UID');
        $this->assertEquals('Updated News in Workspace', $news->title);
        $this->assertCount(3, $news->categories, 'Should see 3 workspace categories');
        $this->assertEquals([3, 4, 5], $news->categories);
    }

    /**
     * Test translating a live record in workspace context.
     *
     * Part of GitHub issue #19: translating pre-existing live records
     * should work in workspace context.
     */
    public function testTranslateLiveRecordInWorkspace(): void
    {
        // Create in live with categories
        $GLOBALS['BE_USER']->workspace = 0;

        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'Live News to Translate',
                'datetime' => time(),
                'categories' => [1, 2, 3],
            ]
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text)->uid;

        // Switch to workspace
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);
        $this->assertGreaterThan(0, $GLOBALS['BE_USER']->workspace);

        // Translate in workspace
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'translate',
            'uid' => $newsUid,
            'data' => ['sys_language_uid' => 'de'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $translationUid = json_decode($result->content[0]->text)->translationUid;
        $this->assertNotEmpty($translationUid, 'Should get a translation UID');

        // Translation should have categories copied from source
        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $translationUid,
            'includeRelations' => true,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $translation = json_decode($result->content[0]->text)->records[0];

        $this->assertIsArray($translation->categories, 'Translation should have categories');
        $this->assertCount(3, $translation->categories, 'Translation should have same categories as source');
        $this->assertEquals([1, 2, 3], $translation->categories);
    }

    /**
     * Test updating MM-only on a live record in workspace (no other field changes).
     *
     * This verifies DataHandler auto-versioning works when the ONLY
     * changes are to MM relation fields.
     */
    public function testUpdateOnlyMmFieldOnLiveRecordInWorkspace(): void
    {
        $GLOBALS['BE_USER']->workspace = 0;

        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'News with only MM update',
                'datetime' => time(),
                'categories' => [1],
            ]
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $newsUid = json_decode($result->content[0]->text)->uid;

        // Switch to workspace
        $this->workspaceService->switchToOptimalWorkspace($GLOBALS['BE_USER']);

        // Update ONLY categories (no other fields)
        $result = $this->writeTool->execute([
            'table' => 'tx_news_domain_model_news',
            'action' => 'update',
            'uid' => $newsUid,
            'data' => ['categories' => [2, 3, 4, 5]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read back
        $result = $this->readTool->execute([
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'includeRelations' => true,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $news = json_decode($result->content[0]->text)->records[0];

        $this->assertCount(4, $news->categories, 'Should see 4 workspace categories');
        $this->assertEquals([2, 3, 4, 5], $news->categories);
    }
}
