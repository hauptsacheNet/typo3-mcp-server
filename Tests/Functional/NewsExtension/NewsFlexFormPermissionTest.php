<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\NewsExtension;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Tests\Functional\Traits\PluginContentTrait;
use Hn\McpServer\Tests\Functional\Traits\RestrictedEditorTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ReadTable returns the pi_flexform of plugins even though the field is not
 * part of the plugin's type definition. That exception must not bypass the
 * field permissions: an exclude field the user has no grant for, and a field
 * disabled through TCEFORM TSconfig, stay hidden like every other field.
 *
 * The exception only kicks in on TYPO3 13 (CType=list with a list_type
 * subtype, whose pi_flexform is not in the "list" type's showitem). On
 * TYPO3 14 plugins have their own CType that lists pi_flexform, so the
 * regular field filter applies and the tests guard against regressions.
 */
class NewsFlexFormPermissionTest extends FunctionalTestCase
{
    use PluginContentTrait;
    use RestrictedEditorTrait;

    private const PLUGIN_UID = 300;

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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importRestrictedEditorFixture();

        $this->insertPluginContentElement(self::PLUGIN_UID, self::MOUNTED_PAGE_UID, 'news_pi1', [
            'pi_flexform' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>'
                . '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
                . '<field index="settings.orderBy"><value index="vDEF">datetime</value></field>'
                . '</language></sheet></data></T3FlexForms>',
        ]);
    }

    public function testRestrictedEditorReadsFlexFormOfPlugin(): void
    {
        $record = $this->readPluginAs(self::RESTRICTED_EDITOR_UID);

        $this->assertArrayHasKey('pi_flexform', $record, 'Precondition: the plugin FlexForm is readable by default');
        $this->assertSame('datetime', $record['pi_flexform']['settings']['orderBy'] ?? null);
    }

    public function testExcludedFlexFormIsHiddenWithoutGrant(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['exclude'] = true;

        $record = $this->readPluginAs(self::RESTRICTED_EDITOR_UID);

        $this->assertArrayNotHasKey('pi_flexform', $record);
        $this->assertSame('Plugin news_pi1', $record['header'] ?? null, 'Other fields are still returned');
    }

    public function testExcludedFlexFormIsReturnedForAdmins(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['exclude'] = true;

        $record = $this->readPluginAs(1);

        $this->assertArrayHasKey('pi_flexform', $record);
    }

    public function testFlexFormDisabledByTsconfigIsHidden(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages')
            ->update('pages', ['TSconfig' => 'TCEFORM.tt_content.pi_flexform.disabled = 1'], ['uid' => self::MOUNTED_PAGE_UID]);

        $record = $this->readPluginAs(self::RESTRICTED_EDITOR_UID);

        $this->assertArrayNotHasKey('pi_flexform', $record);
    }

    private function readPluginAs(int $backendUserUid): array
    {
        $this->actAsBackendUser($backendUserUid);

        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tt_content',
            'uid' => self::PLUGIN_UID,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = $this->decodeToolResult($result);
        $this->assertCount(1, $data['records'] ?? [], json_encode($data));
        return $data['records'][0];
    }
}
