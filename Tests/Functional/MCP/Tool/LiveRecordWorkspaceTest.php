<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test updating and translating existing live records with MM/inline relations
 * in workspace context.
 *
 * This reproduces the scenario from GitHub issue #19 where:
 * - Records exist in live (created before workspace initialization)
 * - Records have MM relations (categories, tags) and inline relations
 * - Update and translate actions fail with "Database operation failed"
 * - Core tables (pages, tt_content) work fine but custom extension tables fail
 *
 * @see https://github.com/hauptsacheNet/typo3-mcp-server/issues/19
 */
class LiveRecordWorkspaceTest extends FunctionalTestCase
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

        // Create multi-language site configuration
        $this->createMultiLanguageSiteConfiguration();

        // Import base fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category.csv');

        // Set up backend user
        $this->setUpBackendUser(1);

        // NOTE: We intentionally do NOT set $GLOBALS['LANG'] here.
        // AbstractRecordTool::initialize() should handle this automatically.
        // This test verifies the fix for issue #19.
    }

    /**
     * Create a site configuration with multiple languages
     */
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
     * Helper: insert a news record directly into the database as live data
     */
    protected function insertLiveNewsRecord(int $pid, string $title, int $uid = 0): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_news_domain_model_news');

        $data = [
            'pid' => $pid,
            'title' => $title,
            'datetime' => time(),
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            't3ver_oid' => 0,
            't3ver_wsid' => 0,
        ];

        if ($uid > 0) {
            $data['uid'] = $uid;
        }

        $connection->insert('tx_news_domain_model_news', $data);
        return (int)$connection->lastInsertId();
    }

    /**
     * Helper: insert MM relation records directly into the database
     */
    protected function insertMmRelation(string $mmTable, int $uidLocal, int $uidForeign, array $extra = []): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($mmTable);

        $data = array_merge([
            'uid_local' => $uidLocal,
            'uid_foreign' => $uidForeign,
            'sorting' => 0,
            'sorting_foreign' => 0,
        ], $extra);

        $connection->insert($mmTable, $data);
    }

    /**
     * Helper: insert a tag directly into the database as live data
     */
    protected function insertLiveTag(int $pid, string $title): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_news_domain_model_tag');

        $connection->insert('tx_news_domain_model_tag', [
            'pid' => $pid,
            'title' => $title,
        ]);

        return (int)$connection->lastInsertId();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Update tests
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test updating an existing live news record (simple field, no relations changed)
     *
     * This is the basic case: a record exists in live, we update a simple field
     * in workspace context.
     */
    public function testUpdateExistingLiveNewsRecord(): void
    {
        // Create a news record directly in live (simulating pre-existing data)
        $newsUid = $this->insertLiveNewsRecord(1, 'Original News Title');

        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'update',
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'data' => [
                'title' => 'Updated News Title',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('update', $data['action']);
        $this->assertEquals($newsUid, $data['uid']);
    }

    /**
     * Test updating an existing live news record that has MM relations (categories)
     *
     * This is the core scenario from issue #19: a record with MM relations
     * already exists in live, and we try to update it in workspace context.
     */
    public function testUpdateExistingLiveNewsWithMmRelations(): void
    {
        // Create a news record directly in live
        $newsUid = $this->insertLiveNewsRecord(1, 'News with Categories');

        // Add MM category relations (categories 1 and 2) directly in DB
        $this->insertMmRelation('sys_category_record_mm', 1, $newsUid, [
            'tablenames' => 'tx_news_domain_model_news',
            'fieldname' => 'categories',
            'sorting_foreign' => 1,
        ]);
        $this->insertMmRelation('sys_category_record_mm', 2, $newsUid, [
            'tablenames' => 'tx_news_domain_model_news',
            'fieldname' => 'categories',
            'sorting_foreign' => 2,
        ]);

        // Update the categories count on the news record
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_news_domain_model_news')
            ->update('tx_news_domain_model_news', ['categories' => 2], ['uid' => $newsUid]);

        $tool = new WriteTableTool();

        // Update just the title (not touching relations)
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'data' => [
                'title' => 'Updated News with Categories',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('update', $data['action']);
        $this->assertEquals($newsUid, $data['uid']);
    }

    /**
     * Test updating an existing live news record that has MM tag relations
     *
     * Similar to categories but uses standard MM (not opposite field).
     */
    public function testUpdateExistingLiveNewsWithTagRelations(): void
    {
        // Create tags directly in live
        $tag1 = $this->insertLiveTag(1, 'Breaking');
        $tag2 = $this->insertLiveTag(1, 'Important');

        // Create a news record directly in live
        $newsUid = $this->insertLiveNewsRecord(1, 'News with Tags');

        // Add MM tag relations
        $this->insertMmRelation('tx_news_domain_model_news_tag_mm', $newsUid, $tag1, [
            'sorting' => 1,
        ]);
        $this->insertMmRelation('tx_news_domain_model_news_tag_mm', $newsUid, $tag2, [
            'sorting' => 2,
        ]);

        // Update the tags count on the news record
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_news_domain_model_news')
            ->update('tx_news_domain_model_news', ['tags' => 2], ['uid' => $newsUid]);

        $tool = new WriteTableTool();

        // Update just the title (not touching relations)
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'data' => [
                'title' => 'Updated News with Tags',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('update', $data['action']);
        $this->assertEquals($newsUid, $data['uid']);
    }

    /**
     * Test updating MM relations on an existing live record
     *
     * This tests changing the actual relations (not just simple fields)
     * on a pre-existing live record in workspace context.
     */
    public function testUpdateMmRelationsOnExistingLiveRecord(): void
    {
        // Create a news record directly in live with categories
        $newsUid = $this->insertLiveNewsRecord(1, 'News to Recategorize');

        // Add initial category relations
        $this->insertMmRelation('sys_category_record_mm', 1, $newsUid, [
            'tablenames' => 'tx_news_domain_model_news',
            'fieldname' => 'categories',
            'sorting_foreign' => 1,
        ]);

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_news_domain_model_news')
            ->update('tx_news_domain_model_news', ['categories' => 1], ['uid' => $newsUid]);

        $tool = new WriteTableTool();

        // Change categories to different ones
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'data' => [
                'categories' => [3, 4], // Different categories
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('update', $data['action']);
        $this->assertEquals($newsUid, $data['uid']);
    }

    /**
     * Test updating an existing live record with both MM relations and inline content
     *
     * This is the most complex scenario: a record with multiple relation types.
     */
    public function testUpdateExistingLiveNewsWithMixedRelations(): void
    {
        // Create tags
        $tag1 = $this->insertLiveTag(1, 'Tech');
        $tag2 = $this->insertLiveTag(1, 'Science');

        // Create a news record directly in live
        $newsUid = $this->insertLiveNewsRecord(1, 'Complex News Record');

        // Add category MM relations
        $this->insertMmRelation('sys_category_record_mm', 1, $newsUid, [
            'tablenames' => 'tx_news_domain_model_news',
            'fieldname' => 'categories',
            'sorting_foreign' => 1,
        ]);
        $this->insertMmRelation('sys_category_record_mm', 2, $newsUid, [
            'tablenames' => 'tx_news_domain_model_news',
            'fieldname' => 'categories',
            'sorting_foreign' => 2,
        ]);

        // Add tag MM relations
        $this->insertMmRelation('tx_news_domain_model_news_tag_mm', $newsUid, $tag1, [
            'sorting' => 1,
        ]);
        $this->insertMmRelation('tx_news_domain_model_news_tag_mm', $newsUid, $tag2, [
            'sorting' => 2,
        ]);

        // Update relation counts
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_news_domain_model_news')
            ->update('tx_news_domain_model_news', ['categories' => 2, 'tags' => 2], ['uid' => $newsUid]);

        $tool = new WriteTableTool();

        // Update just the title
        $result = $tool->execute([
            'action' => 'update',
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'data' => [
                'title' => 'Updated Complex News',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('update', $data['action']);
        $this->assertEquals($newsUid, $data['uid']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Translate tests
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test translating an existing live news record (no relations)
     */
    public function testTranslateExistingLiveNewsRecord(): void
    {
        $newsUid = $this->insertLiveNewsRecord(1, 'News to Translate');

        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'data' => [
                'sys_language_uid' => 'de',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('translate', $data['action']);
        $this->assertEquals($newsUid, $data['sourceUid']);
        $this->assertEquals('de', $data['targetLanguage']);
    }

    /**
     * Test translating an existing live news record that has MM relations
     *
     * This is the translation scenario from issue #19.
     */
    public function testTranslateExistingLiveNewsWithMmRelations(): void
    {
        // Create a news record directly in live with categories
        $newsUid = $this->insertLiveNewsRecord(1, 'Categorized News to Translate');

        // Add category MM relations
        $this->insertMmRelation('sys_category_record_mm', 1, $newsUid, [
            'tablenames' => 'tx_news_domain_model_news',
            'fieldname' => 'categories',
            'sorting_foreign' => 1,
        ]);
        $this->insertMmRelation('sys_category_record_mm', 2, $newsUid, [
            'tablenames' => 'tx_news_domain_model_news',
            'fieldname' => 'categories',
            'sorting_foreign' => 2,
        ]);

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_news_domain_model_news')
            ->update('tx_news_domain_model_news', ['categories' => 2], ['uid' => $newsUid]);

        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'data' => [
                'sys_language_uid' => 'de',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('translate', $data['action']);
        $this->assertEquals($newsUid, $data['sourceUid']);
    }

    /**
     * Test translating an existing live news record with both categories and tags
     */
    public function testTranslateExistingLiveNewsWithMixedMmRelations(): void
    {
        $tag1 = $this->insertLiveTag(1, 'Breaking');

        $newsUid = $this->insertLiveNewsRecord(1, 'Tagged News to Translate');

        // Add category and tag relations
        $this->insertMmRelation('sys_category_record_mm', 1, $newsUid, [
            'tablenames' => 'tx_news_domain_model_news',
            'fieldname' => 'categories',
            'sorting_foreign' => 1,
        ]);
        $this->insertMmRelation('tx_news_domain_model_news_tag_mm', $newsUid, $tag1, [
            'sorting' => 1,
        ]);

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_news_domain_model_news')
            ->update('tx_news_domain_model_news', ['categories' => 1, 'tags' => 1], ['uid' => $newsUid]);

        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'data' => [
                'sys_language_uid' => 'de',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertEquals('translate', $data['action']);
        $this->assertEquals($newsUid, $data['sourceUid']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Comparison tests (core tables vs extension tables)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test that updating existing live tt_content with categories works
     * (this should pass - core tables work per issue report)
     */
    public function testUpdateExistingLiveTtContentWithCategories(): void
    {
        // tt_content UID 100 exists from fixture with no categories
        // Add categories to it
        $this->insertMmRelation('sys_category_record_mm', 1, 100, [
            'tablenames' => 'tt_content',
            'fieldname' => 'categories',
            'sorting_foreign' => 1,
        ]);

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['categories' => 1], ['uid' => 100]);

        $tool = new WriteTableTool();

        $result = $tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => [
                'header' => 'Updated Content with Categories',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * Test that updating a live record with MM relations creates a workspace version
     */
    public function testUpdateLiveRecordWithMmRelationsCreatesWorkspaceVersion(): void
    {
        // Create a news record with categories in live
        $newsUid = $this->insertLiveNewsRecord(1, 'News to Verify Workspace');

        $this->insertMmRelation('sys_category_record_mm', 1, $newsUid, [
            'tablenames' => 'tx_news_domain_model_news',
            'fieldname' => 'categories',
            'sorting_foreign' => 1,
        ]);
        $this->insertMmRelation('sys_category_record_mm', 2, $newsUid, [
            'tablenames' => 'tx_news_domain_model_news',
            'fieldname' => 'categories',
            'sorting_foreign' => 2,
        ]);

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_news_domain_model_news')
            ->update('tx_news_domain_model_news', ['categories' => 2], ['uid' => $newsUid]);

        $writeTool = new WriteTableTool();

        // Update the record
        $result = $writeTool->execute([
            'action' => 'update',
            'table' => 'tx_news_domain_model_news',
            'uid' => $newsUid,
            'data' => [
                'title' => 'Updated in Workspace',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Verify workspace version was created in the database
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_news_domain_model_news');
        $queryBuilder->getRestrictions()->removeAll();

        $workspaceRecord = $queryBuilder->select('*')
            ->from('tx_news_domain_model_news')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($newsUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->gt('t3ver_wsid', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertIsArray($workspaceRecord, 'Workspace version should be created for updated live record');
        $this->assertEquals('Updated in Workspace', $workspaceRecord['title']);

        // Verify live record is unchanged
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_news_domain_model_news');
        $queryBuilder->getRestrictions()->removeAll();

        $liveRecord = $queryBuilder->select('title')
            ->from('tx_news_domain_model_news')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($newsUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        $this->assertEquals('News to Verify Workspace', $liveRecord['title'], 'Live record should remain unchanged');
    }
}
