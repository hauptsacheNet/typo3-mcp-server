<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

/**
 * Which fields the typed "where" clauses of ReadTable accept.
 *
 * A field is filterable when ReadTable could return it for some record of the
 * table: a TCA column readable for at least one record type, or a ctrl field
 * ReadTable always returns. These tests pin down the fields a caller
 * realistically filters by: ctrl fields without a TCA column (tstamp, "what
 * changed recently"), uid lists, fields only shown for a specific record type,
 * and the type field itself.
 */
class ReadTableFilterFieldsTest extends AbstractFunctionalTest
{
    private ReadTableTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new ReadTableTool();
    }

    /**
     * "What changed in the last 7 days" — tstamp is ctrl.tstamp, not a TCA column.
     * The former SQL where allowed `tstamp > X`.
     */
    public function testFilterContentByTstampGreaterThan(): void
    {
        $recent = time();
        $this->getConnectionForTable('tt_content')->update('tt_content', ['tstamp' => $recent], ['uid' => 100]);

        $result = $this->tool->execute([
            'table' => 'tt_content',
            'where' => [['field' => 'tstamp', 'operator' => '>', 'value' => $recent - 7 * 86400]],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([100], $this->extractUids($result));
    }

    public function testFilterContentByUidIn(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'where' => [['field' => 'uid', 'operator' => 'in', 'value' => [100, 102]]],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uids = $this->extractUids($result);
        sort($uids);
        $this->assertSame([100, 102], $uids);
    }

    /**
     * `assets` is shown for CType=textmedia but not for the default type `text`.
     */
    public function testFilterContentByTypeSpecificAssetsField(): void
    {
        $this->getConnectionForTable('tt_content')->update('tt_content', ['assets' => 1], ['uid' => 101]);

        $result = $this->tool->execute([
            'table' => 'tt_content',
            'where' => [
                ['field' => 'CType', 'operator' => '=', 'value' => 'textmedia'],
                ['field' => 'assets', 'operator' => '>', 'value' => 0],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([101], $this->extractUids($result));
    }

    /**
     * `image` is shown for CType=textpic/image but not for the default type `text`.
     */
    public function testFilterContentByTypeSpecificImageField(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'where' => [['field' => 'image', 'operator' => 'isNotNull']],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testFilterPagesByDoktype(): void
    {
        $result = $this->tool->execute([
            'table' => 'pages',
            'where' => [['field' => 'doktype', 'operator' => '=', 'value' => 1]],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertNotEmpty($this->extractUids($result));
    }

    /**
     * crdate and sorting are ctrl fields without a TCA column, like tstamp. They
     * are numbers, so integer values are bound as integers.
     */
    public function testFilterContentByCrdateAndSorting(): void
    {
        $this->getConnectionForTable('tt_content')->update('tt_content', ['crdate' => 1800000000], ['uid' => 101]);

        $result = $this->tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'where' => [
                ['field' => 'crdate', 'operator' => '>=', 'value' => 1800000000],
                ['field' => 'sorting', 'operator' => '<', 'value' => 1000],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([101], $this->extractUids($result));
    }

    /**
     * Taking the union over record types must not widen the set to plumbing
     * columns: l18n_diffsource is a TCA column, but no type shows it and
     * ReadTable never returns it.
     */
    public function testColumnNoRecordTypeShowsIsNotFilterable(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'where' => [['field' => 'l18n_diffsource', 'operator' => 'contains', 'value' => 'a']],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not a readable field', $result->content[0]->text);
    }

    /**
     * public_url is a computed sys_file field added by an AfterSchemaLoadEvent
     * listener. ReadTable returns it, but there is no column to filter on, so
     * it is refused up front instead of failing in the database.
     */
    public function testComputedFieldIsNotFilterable(): void
    {
        $result = $this->tool->execute([
            'table' => 'sys_file',
            'where' => [['field' => 'public_url', 'operator' => 'contains', 'value' => 'fileadmin']],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not a readable field', $result->content[0]->text);
    }

    /**
     * A field TCEFORM disables for every record type is not readable, so it is
     * not filterable either — for admins too.
     */
    public function testFieldDisabledByTceformIsNotFilterable(): void
    {
        $this->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => "TCEFORM.tt_content.assets.disabled = 1\n"],
            ['uid' => 1]
        );

        $result = $this->tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'where' => [['field' => 'assets', 'operator' => '>', 'value' => 0]],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not a readable field', $result->content[0]->text);
    }

    /**
     * Disabled globally but re-enabled for one record type: ReadTable returns
     * the field on records of that type, so it can be filtered on.
     */
    public function testFieldReEnabledForOneRecordTypeIsFilterable(): void
    {
        $this->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => "TCEFORM.tt_content.assets.disabled = 1\nTCEFORM.tt_content.assets.types.textmedia.disabled = 0\n"],
            ['uid' => 1]
        );
        $this->getConnectionForTable('tt_content')->update('tt_content', ['assets' => 1], ['uid' => 101]);

        $result = $this->tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'where' => [['field' => 'assets', 'operator' => '>', 'value' => 0]],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([101], $this->extractUids($result));
    }

    /**
     * imagewidth is an exclude field shown only for image-carrying types. A
     * non-admin editor without the non_exclude_fields grant cannot read it, so
     * the union over record types must not make it filterable.
     */
    public function testExcludeFieldIsNotFilterableForANonAdminWithoutTheGrant(): void
    {
        $this->setUpNonAdminEditor('tt_content:CType,tt_content:header');

        $result = $this->tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'where' => [['field' => 'imagewidth', 'operator' => '>', 'value' => 0]],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not a readable field', $result->content[0]->text);
    }

    /**
     * The same editor with the grant reads imagewidth on textmedia records, so
     * the filter is accepted — the refusal above comes from the permission,
     * not from the field being type-specific.
     */
    public function testExcludeFieldIsFilterableForANonAdminWithTheGrant(): void
    {
        $this->setUpNonAdminEditor('tt_content:CType,tt_content:header,tt_content:imagewidth');
        $this->getConnectionForTable('tt_content')->update('tt_content', ['imagewidth' => 300], ['uid' => 100]);

        $result = $this->tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'where' => [['field' => 'imagewidth', 'operator' => '>', 'value' => 0]],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame([100], $this->extractUids($result));
    }

    private function setUpNonAdminEditor(string $nonExcludeFields): void
    {
        $this->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 99,
            'pid' => 0,
            'username' => 'filter_editor',
            'password' => 'not-a-real-hash',
            'admin' => 0,
            'disable' => 0,
            'deleted' => 0,
        ]);

        $user = $this->setupDefaultBackendUser(99);
        $user->groupData['tables_select'] = 'pages,tt_content';
        $user->groupData['tables_modify'] = 'pages,tt_content';
        $user->groupData['non_exclude_fields'] = $nonExcludeFields;
        $user->groupData['webmounts'] = '1';
        $user->user['db_mountpoints'] = '1';
        $this->assertFalse($user->isAdmin());
    }

    /**
     * @return list<int>
     */
    private function extractUids($result): array
    {
        $data = json_decode($result->content[0]->text, true);
        return array_map('intval', array_column($data['records'] ?? [], 'uid'));
    }
}
