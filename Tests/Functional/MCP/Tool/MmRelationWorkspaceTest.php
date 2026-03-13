<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Symfony\Component\Yaml\Yaml;
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
}
