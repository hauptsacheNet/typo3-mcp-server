<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\MCP\Tool\Record\ListTablesTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The extension settings allowedTables / excludedTables limit the tables the
 * MCP tools expose, on top of the backend user's permissions and for admins
 * as well. Typical use: keep tables with personal data (form submissions,
 * leads) away from AI clients while editors still work with them in the
 * backend.
 */
class TableExposureConfigurationTest extends AbstractFunctionalTest
{
    private mixed $previousExtensionConfiguration = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category.csv');
        $this->previousExtensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousExtensionConfiguration === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $this->previousExtensionConfiguration;
        }
        $this->resetTableAccessService();
        parent::tearDown();
    }

    public function testWithoutSettingsAllAccessibleTablesAreExposed(): void
    {
        $this->configure('', '');
        $service = GeneralUtility::makeInstance(TableAccessService::class);

        $this->assertTrue($service->canAccessTable('tt_content'));
        $this->assertTrue($service->canAccessTable('sys_category'));
        $this->assertTrue($service->canAccessTable('sys_file_reference'));
    }

    public function testExcludedTableIsHiddenFromEveryToolEvenForAdmins(): void
    {
        $this->assertTrue($GLOBALS['BE_USER']->isAdmin(), 'Test prerequisite: the backend user is an admin.');
        $this->configure('', 'tt_content');

        $list = (new ListTablesTool())->execute([]);
        $this->assertFalse($list->isError, json_encode($list->jsonSerialize()));
        $this->assertStringNotContainsString('- tt_content ', $list->content[0]->text);
        $this->assertStringContainsString('- pages ', $list->content[0]->text);

        $read = (new ReadTableTool())->execute(['table' => 'tt_content', 'uid' => 100]);
        $this->assertTrue($read->isError, 'Reading an excluded table must fail: ' . json_encode($read->jsonSerialize()));
        $this->assertStringContainsString('excludedTables', $read->content[0]->text);

        $page = $this->createGetPageTool()->execute(['uid' => 1]);
        $this->assertFalse($page->isError, json_encode($page->jsonSerialize()));
        $this->assertStringNotContainsString('Welcome Header', $page->content[0]->text);

        $search = (new SearchTool())->execute(['terms' => ['Welcome']]);
        $this->assertFalse($search->isError, json_encode($search->jsonSerialize()));
        $this->assertStringNotContainsString('Welcome Header', $search->content[0]->text);

        $write = (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => ['pid' => 1, 'CType' => 'textmedia', 'header' => 'Must not be created'],
        ]);
        $this->assertTrue($write->isError, 'Writing an excluded table must fail: ' . json_encode($write->jsonSerialize()));
    }

    public function testExcludedPagesTableHidesPageDataFromPageTools(): void
    {
        $this->configure('', 'pages');

        $page = $this->createGetPageTool()->execute(['uid' => 1]);
        $this->assertTrue($page->isError, 'GetPage must fail when pages are excluded: ' . json_encode($page->jsonSerialize()));

        $tree = (new GetPageTreeTool(
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(LanguageService::class)
        ))->execute(['startPage' => 0]);
        $this->assertTrue($tree->isError, 'GetPageTree must fail when pages are excluded: ' . json_encode($tree->jsonSerialize()));

        // Content stays searchable, but without the title of its page
        $search = (new SearchTool())->execute(['terms' => ['Welcome']]);
        $this->assertFalse($search->isError, json_encode($search->jsonSerialize()));
        $this->assertStringContainsString('Welcome Header', $search->content[0]->text);
        $this->assertStringNotContainsString('Page: Home', $search->content[0]->text);
    }

    public function testAllowedTablesWithoutPagesHidePageDataFromPageTools(): void
    {
        $this->configure('tt_content', '');

        $page = $this->createGetPageTool()->execute(['uid' => 1]);
        $this->assertTrue($page->isError, 'GetPage must fail when pages are not allowed: ' . json_encode($page->jsonSerialize()));

        $search = (new SearchTool())->execute(['terms' => ['Welcome']]);
        $this->assertFalse($search->isError, json_encode($search->jsonSerialize()));
        $this->assertStringContainsString('Welcome Header', $search->content[0]->text);
        $this->assertStringNotContainsString('Page: Home', $search->content[0]->text);
    }

    public function testTrailingAsteriskExcludesAllTablesWithThatPrefix(): void
    {
        $this->configure('', 'sys_file*');
        $service = GeneralUtility::makeInstance(TableAccessService::class);

        $this->assertFalse($service->canAccessTable('sys_file'));
        $this->assertFalse($service->canAccessTable('sys_file_reference'));
        $this->assertFalse($service->canAccessTable('sys_file_metadata'));
        $this->assertTrue($service->canAccessTable('sys_category'));
        $this->assertTrue($service->canAccessTable('tt_content'));
    }

    public function testAllowedTablesLimitTheExposedTables(): void
    {
        $this->configure('pages, tt_content', '');
        $service = GeneralUtility::makeInstance(TableAccessService::class);

        $this->assertTrue($service->canAccessTable('pages'));
        $this->assertTrue($service->canAccessTable('tt_content'));
        $this->assertFalse($service->canAccessTable('sys_category'));
        $this->assertFalse($service->canAccessTable('sys_file_reference'));

        $read = (new ReadTableTool())->execute(['table' => 'sys_category', 'uid' => 1]);
        $this->assertTrue($read->isError, 'Reading a table outside allowedTables must fail: ' . json_encode($read->jsonSerialize()));

        $read = (new ReadTableTool())->execute(['table' => 'tt_content', 'uid' => 100]);
        $this->assertFalse($read->isError, json_encode($read->jsonSerialize()));
    }

    public function testAllowedTablesAcceptPrefixPatterns(): void
    {
        $this->configure('pages,tt_content,sys_file*', '');
        $service = GeneralUtility::makeInstance(TableAccessService::class);

        $this->assertTrue($service->canAccessTable('sys_file_reference'));
        $this->assertTrue($service->canAccessTable('sys_file_metadata'));
        $this->assertFalse($service->canAccessTable('sys_category'));
    }

    public function testExcludedTablesWinOverAllowedTables(): void
    {
        $this->configure('pages,tt_content,sys_category', 'sys_category');
        $service = GeneralUtility::makeInstance(TableAccessService::class);

        $this->assertTrue($service->canAccessTable('tt_content'));
        $this->assertFalse($service->canAccessTable('sys_category'));
    }

    private function createGetPageTool(): GetPageTool
    {
        return new GetPageTool(
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(LanguageService::class)
        );
    }

    private function configure(string $allowedTables, string $excludedTables): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['allowedTables'] = $allowedTables;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['excludedTables'] = $excludedTables;
        $this->resetTableAccessService();
    }

    /**
     * TableAccessService is a singleton that caches the settings, so every
     * configuration needs a fresh instance.
     */
    private function resetTableAccessService(): void
    {
        GeneralUtility::setSingletonInstance(TableAccessService::class, new TableAccessService());
    }
}
