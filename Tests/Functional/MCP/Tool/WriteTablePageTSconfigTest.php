<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Page TSconfig commonly disables a field globally and re-enables it for selected
 * pages. The field set used by the record tools must therefore be resolved at the
 * record's own page: for a page record that page itself, for any other record the
 * page it lives on.
 *
 * Resolved elsewhere, a field that is enabled exactly where the record lives
 * disappears from the writable and readable set even though the backend form offers
 * it.
 *
 * A site configuration is mandatory for these tests rather than incidental.
 * TableAccessService::resolveTSconfigPid() falls back to the first site's root page,
 * and with no site at all it falls back to page 0, whose empty rootline carries no
 * TSconfig. The field set is then never filtered, every assertion below passes
 * regardless of the code under test, and the bug this class exists for goes
 * unnoticed.
 *
 * @see https://github.com/hauptsacheNet/typo3-mcp-server/issues/120
 */
class WriteTablePageTSconfigTest extends AbstractFunctionalTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeSiteConfigurationForRootPage(1);

        $connection = $this->getConnectionForTable('pages');

        // Root page 1 disables the field for the whole tree.
        $connection->update(
            'pages',
            ['TSconfig' => "TCEFORM.pages.nav_title.disabled = 1\n"],
            ['uid' => 1]
        );

        // Page 2 ("About") re-enables it for itself and its subtree. Page 3, a
        // sibling, keeps the inherited disable.
        $connection->update(
            'pages',
            ['TSconfig' => "TCEFORM.pages.nav_title.disabled = 0\n"],
            ['uid' => 2]
        );
    }

    /**
     * Guards the premise of every other test here: without a site the TSconfig
     * fallback lands on page 0 and disables nothing, so the rest would pass on
     * broken code.
     */
    public function testTheFallbackPageResolvesToTheSiteRoot(): void
    {
        $tableAccessService = GeneralUtility::makeInstance(\Hn\McpServer\Service\TableAccessService::class);

        $this->assertSame(1, $tableAccessService->resolveTSconfigPid(null));
        $this->assertArrayNotHasKey(
            'nav_title',
            $tableAccessService->getAvailableFields('pages', '1'),
            'The site root disables nav_title, so the pid-less field set must not carry it.'
        );
    }

    /**
     * The record being edited is page 2, which re-enables the field. Resolving
     * TSconfig anywhere else, including at page 2's parent or at the site root,
     * reports it as disabled and rejects a write the backend form allows.
     */
    public function testFieldReEnabledOnTheEditedPageIsWritable(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 2,
            'data' => ['nav_title' => 'About us'],
        ]);

        // The write itself is staged in a workspace, so assert on the tool result
        // rather than on the live row.
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * A page that only inherits the disable stays rejected, so the fix does not
     * turn the TSconfig check off wholesale.
     */
    public function testFieldStillDisabledOnAPageThatOnlyInheritsTheDisable(): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 3,
            'data' => ['nav_title' => 'should not be written'],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('nav_title', $result->content[0]->text);
    }

    /**
     * Reads have to agree with writes: a field enabled at the record's own page
     * must survive the schema filter instead of being dropped silently.
     */
    public function testReadReturnsAFieldReEnabledOnTheRecordsOwnPage(): void
    {
        $this->getConnectionForTable('pages')
            ->update('pages', ['nav_title' => 'About us'], ['uid' => 2]);

        $result = (new ReadTableTool())->execute([
            'table' => 'pages',
            'uid' => 2,
            'fields' => ['uid', 'nav_title'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('nav_title', $result->content[0]->text);
        $this->assertStringContainsString('About us', $result->content[0]->text);
    }

    protected function writeSiteConfigurationForRootPage(int $rootPageId): void
    {
        $directory = Environment::getConfigPath() . '/sites/main';
        GeneralUtility::mkdir_deep($directory);
        file_put_contents($directory . '/config.yaml', implode("\n", [
            'rootPageId: ' . $rootPageId,
            "base: 'https://example.com/'",
            'languages:',
            '  -',
            '    title: English',
            '    languageId: 0',
            "    base: '/'",
            '    locale: en_US.UTF-8',
            '',
        ]));
    }
}
