<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\Service\BackendUserContextService;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * tables_select says which kinds of records a user works with, not where. The
 * read tools must stay within the user's web mounts and respect the page
 * permissions, as the page tree and the list module of the backend do.
 *
 * The editor here is mounted on "About" (2) with its subpages "Team" (4) and
 * "Mission" (5); Mission grants nobody "show". "Home" (1) and everything else
 * lies outside the mount.
 */
class PageReadPermissionTest extends AbstractFunctionalTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->update('pages', ['perms_everybody' => 1], ['uid' => 1]);
        $pages->update('pages', ['perms_everybody' => 1], ['uid' => 2]);
        $pages->update('pages', ['perms_everybody' => 1], ['uid' => 4]);
        $pages->update('pages', ['perms_everybody' => 0, 'perms_user' => 0, 'perms_group' => 0], ['uid' => 5]);
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 950,
            'pid' => 5,
            'header' => 'Mission statement draft',
            'CType' => 'textmedia',
        ]);

        $this->connectionPool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 70,
            'pid' => 0,
            'title' => 'About editors',
            'tables_select' => 'pages,tt_content',
            'tables_modify' => 'pages,tt_content',
            'db_mountpoints' => '2',
        ]);
        $this->connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 71,
            'pid' => 0,
            'username' => 'about_editor',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'usergroup' => '70',
            'tstamp' => time(),
            'crdate' => time(),
        ]);
        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => 60,
            'pid' => 0,
            'title' => 'About workspace',
            'adminusers' => 'be_users_1',
            'members' => 'be_users_71',
        ]);
    }

    private function actAsEditor(): void
    {
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();
        GeneralUtility::makeInstance(BackendUserContextService::class)->impersonate(71);
    }

    private function textOf(CallToolResult $result): string
    {
        return implode("\n", array_map(fn($block) => $block->text ?? '', $result->content));
    }

    public function testReadTableOnlyReturnsRecordsOnReadablePages(): void
    {
        $this->actAsEditor();

        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute(['table' => 'tt_content', 'limit' => 1000]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);
        $pids = array_values(array_unique(array_column($data['records'], 'pid')));
        sort($pids);
        $this->assertSame([2, 4], $pids, 'Only the readable pages of the mount');
        $this->assertSame(count($data['records']), $data['total'], 'The total must not count what is withheld');
    }

    public function testReadTableReturnsNothingForAPageOutsideTheMount(): void
    {
        $this->actAsEditor();

        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute(['table' => 'tt_content', 'pid' => 1]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);
        $this->assertSame([], $data['records']);
        $this->assertSame(0, $data['total']);
    }

    public function testReadTableDoesNotReturnARecordOutsideTheMountByUid(): void
    {
        $this->actAsEditor();

        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute(['table' => 'tt_content', 'uid' => 100]);

        $this->assertStringNotContainsString('Welcome', $this->textOf($result));
    }

    public function testReadTableOnPagesStaysWithinTheMount(): void
    {
        $this->actAsEditor();

        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute(['table' => 'pages', 'limit' => 1000]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uids = array_column(json_decode($result->content[0]->text, true)['records'], 'uid');
        sort($uids);
        $this->assertSame([2, 4], $uids);
    }

    public function testSearchFindsNothingOutsideTheReadablePages(): void
    {
        $this->actAsEditor();
        $tool = GeneralUtility::makeInstance(SearchTool::class);

        $outside = $tool->execute(['terms' => ['Welcome']]);
        $unreadable = $tool->execute(['terms' => ['Mission statement']]);
        $inside = $tool->execute(['terms' => ['Team Introduction']]);

        $this->assertStringNotContainsString('Welcome Header', $this->textOf($outside));
        $this->assertStringNotContainsString('Mission statement draft', $this->textOf($unreadable));
        $this->assertStringContainsString('Team Introduction', $this->textOf($inside));
    }

    public function testGetPageRefusesAPageOutsideTheMount(): void
    {
        $this->actAsEditor();

        $result = $this->get(GetPageTool::class)->execute(['uid' => 1]);

        $this->assertTrue($result->isError);
        $this->assertStringNotContainsString('Home', $this->textOf($result));
        $this->assertStringNotContainsString('Welcome', $this->textOf($result));
    }

    public function testGetPageRefusesAPageWithoutShowPermission(): void
    {
        $this->actAsEditor();

        $result = $this->get(GetPageTool::class)->execute(['uid' => 5]);

        $this->assertTrue($result->isError);
        $this->assertStringNotContainsString('Mission', $this->textOf($result));
    }

    public function testGetPageShowsAReadablePage(): void
    {
        $this->actAsEditor();

        $result = $this->get(GetPageTool::class)->execute(['uid' => 4]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Team', $this->textOf($result));
    }

    public function testGetPageTreeStartsAtTheWebMounts(): void
    {
        $this->actAsEditor();

        $result = $this->get(GetPageTreeTool::class)->execute(['startPage' => 0, 'depth' => 5]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $this->textOf($result);
        $this->assertStringContainsString('About', $text);
        $this->assertStringContainsString('Team', $text);
        $this->assertStringNotContainsString('Home', $text);
        $this->assertStringNotContainsString('Contact', $text);
        $this->assertStringNotContainsString('Mission', $text);
    }

    public function testGetPageTreeRefusesAStartPageOutsideTheMount(): void
    {
        $this->actAsEditor();

        $result = $this->get(GetPageTreeTool::class)->execute(['startPage' => 1, 'depth' => 5]);

        $this->assertTrue($result->isError);
        $this->assertStringNotContainsString('Contact', $this->textOf($result));
    }

    /**
     * "Search string not found" against "found N times" would tell what a record
     * contains before DataHandler refuses the write.
     */
    public function testSearchReplaceDoesNotTellWhatAnUnreadableRecordContains(): void
    {
        $this->actAsEditor();
        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        $attempt = fn(string $search) => $this->textOf($tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => ['header' => [['search' => $search, 'replace' => 'x']]],
        ]));

        $this->assertSame($attempt('Welcome'), $attempt('certainly not in there'));
    }

    /**
     * An editor who is member of no workspace and may not create one ends up in
     * live. Reading there is fine; a write would be public at once.
     */
    public function testWriteTableRefusesToWriteLiveWithoutAWorkspace(): void
    {
        $this->connectionPool->getConnectionForTable('sys_workspace')->delete('sys_workspace', ['uid' => 60]);
        $this->actAsEditor();

        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 102,
            'data' => ['header' => 'Written live'],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('never written to live', $this->textOf($result));
        $header = $this->connectionPool->getConnectionForTable('tt_content')
            ->select(['header'], 'tt_content', ['uid' => 102])->fetchOne();
        $this->assertSame('Team Introduction', $header);
    }

    /**
     * tt_content.subheader is an exclude field the editor has no grant for.
     */
    public function testSearchDoesNotMatchInFieldsTheUserMayNotRead(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')
            ->update('tt_content', ['subheader' => 'confidential-subheader-value'], ['uid' => 102]);
        $this->actAsEditor();

        $result = GeneralUtility::makeInstance(SearchTool::class)->execute(['terms' => ['confidential-subheader']]);

        $this->assertStringNotContainsString('Team Introduction', $this->textOf($result));
        $this->assertStringNotContainsString('confidential-subheader-value', $this->textOf($result));
    }

    /**
     * TCEFORM.disabled arranges the edit form, it grants no rights: a field hidden
     * by TSconfig in one place may be shown on other pages or for other record
     * types, so Search keeps matching in it.
     */
    public function testSearchStillMatchesInAFieldHiddenByTsconfig(): void
    {
        $this->connectionPool->getConnectionForTable('be_groups')
            ->update('be_groups', ['TSconfig' => 'page.TCEFORM.tt_content.bodytext.disabled = 1'], ['uid' => 70]);
        $this->connectionPool->getConnectionForTable('tt_content')
            ->update('tt_content', ['bodytext' => 'tceform-hidden-term'], ['uid' => 102]);
        $this->actAsEditor();
        $this->assertFalse(
            GeneralUtility::makeInstance(TableAccessService::class)->canAccessField('tt_content', 'bodytext'),
            'precondition: TCEFORM hides bodytext'
        );

        $result = GeneralUtility::makeInstance(SearchTool::class)->execute(['terms' => ['tceform-hidden-term']]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Team Introduction', $this->textOf($result));
    }

    /**
     * Core protects the page TSconfig with displayCond HIDE_FOR_NON_ADMINS only.
     */
    public function testAdminOnlyPageFieldsAreNotReadableForAnEditor(): void
    {
        $this->connectionPool->getConnectionForTable('pages')
            ->update('pages', ['TSconfig' => 'secret.setting = 1'], ['uid' => 2]);
        $this->actAsEditor();

        $result = GeneralUtility::makeInstance(ReadTableTool::class)
            ->execute(['table' => 'pages', 'uid' => 2, 'fields' => ['title', 'TSconfig']]);

        $this->assertStringNotContainsString('secret.setting', $this->textOf($result));
    }

    public function testAnAdminStillReadsEverything(): void
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute(['table' => 'tt_content', 'pid' => 1]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertNotSame([], json_decode($result->content[0]->text, true)['records']);
    }
}
