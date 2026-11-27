<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Service\BackendUserContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * ReadTable adds relation data (categories, file references) to a record after
 * the record went through the field filter. An exclude field the user has no
 * grant for must stay out of the result there as well.
 *
 * Content element 100 has two categories, two assets and one media file. Core
 * ships the file fields of tt_content without the exclude flag; installations
 * set it to keep files from some editors, and so does this test.
 */
class ReadTableRelationFieldPermissionTest extends AbstractFunctionalTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $fixtures = __DIR__ . '/../../Fixtures/';
        $this->importCSVDataSet($fixtures . 'sys_category.csv');
        $this->importCSVDataSet($fixtures . 'sys_category_record_mm.csv');
        $this->importCSVDataSet($fixtures . 'sys_file.csv');
        $this->importCSVDataSet($fixtures . 'sys_file_metadata.csv');
        $this->importCSVDataSet($fixtures . 'sys_file_reference.csv');

        $GLOBALS['TCA']['tt_content']['columns']['assets']['exclude'] = true;
        $GLOBALS['TCA']['tt_content']['columns']['media']['exclude'] = true;

        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 1], ['uid' => 1]);
        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => 60,
            'pid' => 0,
            'title' => 'Editor workspace',
            'adminusers' => 'be_users_1',
            'members' => 'be_users_71',
        ]);
    }

    /**
     * @return array<string, mixed> content element 100 as the editor reads it
     */
    private function readAsEditor(string $nonExcludeFields, array $params = []): array
    {
        $this->connectionPool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 70,
            'pid' => 0,
            'title' => 'Editors',
            'tables_select' => 'pages,tt_content,sys_category,sys_file_reference,sys_file',
            'tables_modify' => 'pages,tt_content,sys_category,sys_file_reference,sys_file',
            'non_exclude_fields' => $nonExcludeFields,
            'db_mountpoints' => '1',
        ]);
        $this->connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 71,
            'pid' => 0,
            'username' => 'editor',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'usergroup' => '70',
            'tstamp' => time(),
            'crdate' => time(),
        ]);
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();
        GeneralUtility::makeInstance(BackendUserContextService::class)->impersonate(71);

        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute(['table' => 'tt_content', 'uid' => 100] + $params);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $records = json_decode($result->content[0]->text, true)['records'];
        $this->assertCount(1, $records);

        return $records[0];
    }

    public function testRelationsOfGrantedFieldsAreReturned(): void
    {
        $record = $this->readAsEditor('tt_content:categories,tt_content:assets,tt_content:media');

        $this->assertCount(2, $record['categories']);
        $this->assertCount(2, $record['assets']);
        $this->assertCount(1, $record['media']);
    }

    public function testCategoriesOfAnExcludeFieldWithoutGrantAreWithheld(): void
    {
        $record = $this->readAsEditor('tt_content:assets');

        $this->assertArrayNotHasKey('categories', $record);
        $this->assertCount(2, $record['assets'], 'The granted field next to it is unaffected');
    }

    public function testFileReferencesOfAnExcludeFieldWithoutGrantAreWithheld(): void
    {
        $record = $this->readAsEditor('tt_content:categories');

        $this->assertArrayNotHasKey('assets', $record);
        $this->assertArrayNotHasKey('media', $record);
        $this->assertCount(2, $record['categories'], 'The granted field next to it is unaffected');
    }

    public function testAskingForTheFieldByNameDoesNotReturnItEither(): void
    {
        $record = $this->readAsEditor('', ['fields' => ['header', 'categories', 'assets']]);

        $this->assertArrayNotHasKey('categories', $record);
        $this->assertArrayNotHasKey('assets', $record);
    }
}
