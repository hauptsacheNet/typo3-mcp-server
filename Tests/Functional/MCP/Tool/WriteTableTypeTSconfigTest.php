<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Page TSconfig commonly disables a field globally and re-enables it for selected
 * record types only (or the other way round):
 *
 *     TCEFORM.tt_content.layout.disabled = 1
 *     TCEFORM.tt_content.layout.types.textmedia.disabled = 0
 *
 * The backend form applies the `types.<recordType>` override, and GetTableSchema /
 * ReadTable do so as well. The write validation has to resolve the same record
 * type, otherwise it rejects fields the backend and the schema offer ("Field
 * 'layout' is not accessible") and accepts fields they hide.
 *
 * The record type is the one the record will have after the write: the type
 * given in `data`, else the type of the existing record (its workspace version),
 * else the TCA default for a new record.
 *
 * A site configuration is mandatory: without it the TSconfig fallback resolves
 * at page 0, which carries no TSconfig, and every assertion passes on broken code
 * (see WriteTablePageTSconfigTest).
 */
class WriteTableTypeTSconfigTest extends AbstractFunctionalTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $directory = Environment::getConfigPath() . '/sites/main';
        GeneralUtility::mkdir_deep($directory);
        file_put_contents($directory . '/config.yaml', implode("\n", [
            'rootPageId: 1',
            "base: 'https://example.com/'",
            'languages:',
            '  -',
            '    title: English',
            '    languageId: 0',
            "    base: '/'",
            '    locale: en_US.UTF-8',
            '  -',
            '    title: German',
            '    languageId: 1',
            "    base: '/de/'",
            '    locale: de_DE.UTF-8',
            '',
        ]));

        // `layout` is disabled globally and re-enabled for textmedia only.
        // `header_link` is not disabled globally, but disabled for textmedia.
        $this->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => implode("\n", [
                'TCEFORM.tt_content.layout.disabled = 1',
                'TCEFORM.tt_content.layout.types.textmedia.disabled = 0',
                'TCEFORM.tt_content.header_link.types.textmedia.disabled = 1',
                '',
            ])],
            ['uid' => 1]
        );
    }

    public function testCreateAcceptsFieldReEnabledForTheRecordType(): void
    {
        $result = $this->create(['CType' => 'textmedia', 'header' => 'Teaser', 'layout' => 1]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testCreateRejectsGloballyDisabledFieldForOtherRecordTypes(): void
    {
        $result = $this->create(['CType' => 'text', 'header' => 'Teaser', 'layout' => 1]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("Field 'layout' is not accessible", $result->content[0]->text);
    }

    public function testCreateRejectsFieldDisabledForTheRecordType(): void
    {
        $result = $this->create(['CType' => 'textmedia', 'header' => 'Teaser', 'header_link' => 'https://example.com']);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("Field 'header_link' is not accessible", $result->content[0]->text);
    }

    public function testCreateAcceptsFieldDisabledOnlyForOtherRecordTypes(): void
    {
        $result = $this->create(['CType' => 'text', 'header' => 'Teaser', 'header_link' => 'https://example.com']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * Without the type field in `data`, the type comes from the existing record
     * (fixture record 100 is textmedia).
     */
    public function testUpdateWithoutTypeUsesTheTypeOfTheExistingRecord(): void
    {
        $result = $this->update(100, ['layout' => 1]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $result = $this->update(100, ['header_link' => 'https://example.com']);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("Field 'header_link' is not accessible", $result->content[0]->text);
    }

    /**
     * The type changed in the workspace counts, not the live one: the backend form
     * edits the workspace version and resolves TCEFORM for its type.
     */
    public function testUpdateUsesTheTypeOfTheWorkspaceVersion(): void
    {
        $result = $this->update(100, ['CType' => 'text']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $result = $this->update(100, ['header_link' => 'https://example.com']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $result = $this->update(100, ['layout' => 1]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("Field 'layout' is not accessible", $result->content[0]->text);
    }

    /**
     * A type given in `data` wins over the stored one, so a record can be switched
     * to a type and filled with that type's fields in one write.
     */
    public function testUpdateWithTypeChangeUsesTheNewType(): void
    {
        $result = $this->update(100, ['CType' => 'text', 'header_link' => 'https://example.com']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * A translation has the type of its original (fixture record 100 is textmedia).
     */
    public function testTranslateUsesTheTypeOfTheOriginalRecord(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => ['sys_language_uid' => 1, 'header' => 'Willkommen', 'layout' => 1],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testSearchReplaceUsesTheTypeOfTheExistingRecord(): void
    {
        $this->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => implode("\n", [
                'TCEFORM.tt_content.header_link.disabled = 1',
                'TCEFORM.tt_content.header_link.types.textmedia.disabled = 0',
                '',
            ])],
            ['uid' => 1]
        );
        $this->getConnectionForTable('tt_content')
            ->update('tt_content', ['header_link' => 'https://example.com/old'], ['uid' => 100]);

        $result = $this->update(100, [
            'header_link' => [['search' => '/old', 'replace' => '/new']],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    private function create(array $data): \Mcp\Types\CallToolResult
    {
        return (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => $data,
        ]);
    }

    private function update(int $uid, array $data): \Mcp\Types\CallToolResult
    {
        return (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => $data,
        ]);
    }
}
