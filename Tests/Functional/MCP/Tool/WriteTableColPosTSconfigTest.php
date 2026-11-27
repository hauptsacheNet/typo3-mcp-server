<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\LanguageService as McpLanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Sites hide the column select of content elements in the edit form
 * (TCEFORM.tt_content.colPos.disabled = 1), typically because there is only one
 * column, or because editors place content in the page module anyway. The page
 * module ignores that setting: the new content element wizard sets colPos and
 * drag and drop moves elements between columns. So colPos stays writable for
 * the MCP as well, checked against the columns of the page's backend layout.
 * Other TCEFORM-disabled fields stay refused, and the error says why.
 *
 * Page 1 uses a backend layout with the columns 0 "Main" and 1 "Sidebar".
 */
class WriteTableColPosTSconfigTest extends AbstractFunctionalTest
{
    private const TSCONFIG = <<<'TSCONFIG'
TCEFORM.tt_content.colPos.disabled = 1
TCEFORM.tt_content.layout.disabled = 1
mod.web_layout.BackendLayouts {
    twocol {
        title = Two columns
        config {
            backend_layout {
                colCount = 2
                rowCount = 1
                rows {
                    1 {
                        columns {
                            1 {
                                name = Main
                                colPos = 0
                            }
                            2 {
                                name = Sidebar
                                colPos = 1
                            }
                        }
                    }
                }
            }
        }
    }
}
TSCONFIG;

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
            '',
        ]));

        $this->setPageTSconfig(self::TSCONFIG);
        $this->getConnectionForTable('pages')->update(
            'pages',
            ['backend_layout' => 'pagets__twocol', 'backend_layout_next_level' => 'pagets__twocol'],
            ['uid' => 1]
        );
    }

    public function testCreateWithColPosWorksWhenTheColumnSelectIsHidden(): void
    {
        $result = $this->write('create', null, ['pid' => 1, 'CType' => 'text', 'header' => 'New in main', 'colPos' => 0]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = (int)json_decode($result->content[0]->text, true)['uid'];
        $this->assertSame(0, $this->readColPos($uid));
    }

    public function testCreateInAnotherExistingColumnWorks(): void
    {
        $result = $this->write('create', null, ['pid' => 1, 'CType' => 'text', 'header' => 'New in sidebar', 'colPos' => 1]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = (int)json_decode($result->content[0]->text, true)['uid'];
        $this->assertSame(1, $this->readColPos($uid));
    }

    public function testUpdateMovesElementIntoAnotherExistingColumn(): void
    {
        $result = $this->write('update', 100, ['colPos' => 1]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(1, $this->readColPos(100));

        $page = $this->createGetPageTool()->execute(['uid' => 1]);
        $this->assertFalse($page->isError, json_encode($page->jsonSerialize()));
        $this->assertStringContainsString('Column: Sidebar [colPos: 1]', $page->content[0]->text);
    }

    public function testNonExistingColumnIsRefusedWithTheColumnsOfThePage(): void
    {
        $result = $this->write('create', null, ['pid' => 1, 'CType' => 'text', 'header' => 'Nowhere', 'colPos' => 5]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        // Only the substance is asserted: the refused value and the named columns.
        // The backend layout placement check of #187 refuses the same write with
        // its own wording before the field validation runs.
        $text = $result->content[0]->text;
        $this->assertMatchesRegularExpression("/colPos\\D{1,12}5\\b/", $text);
        $this->assertStringContainsString('0 (Main), 1 (Sidebar)', $text);
    }

    public function testOtherDisabledFieldIsStillRefusedAndTheErrorSaysWhy(): void
    {
        $result = $this->write('create', null, ['pid' => 1, 'CType' => 'text', 'header' => 'Teaser', 'layout' => 1]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringContainsString("Field 'layout' is not accessible", $text);
        $this->assertStringContainsString('TCEFORM.tt_content.layout.disabled = 1', $text);
    }

    /**
     * The schema still lists colPos with the columns of the page, so a client
     * knows which values it can write.
     */
    public function testSchemaShowsTheColumnsWhenTheColumnSelectIsHidden(): void
    {
        $result = (new GetTableSchemaTool())->execute(['table' => 'tt_content', 'type' => 'text', 'pid' => 1]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringContainsString('colPos (Column): select', $text);
        $this->assertStringContainsString('[Options: 0 (Main), 1 (Sidebar)]', $text);
        $this->assertDoesNotMatchRegularExpression('/\blayout \(/', $text);
    }

    public function testReadTableShowsColPos(): void
    {
        $result = (new ReadTableTool())->execute(['table' => 'tt_content', 'uid' => 100]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertArrayHasKey('colPos', $record);
    }

    /**
     * mod.SHARED.colPos_list limits the columns the page module lets a user
     * place content in. Placing an element in another column is refused, an
     * element that stays where it is remains editable.
     */
    public function testColumnsRestrictedByColPosListAreRefusedForPlacement(): void
    {
        $this->setPageTSconfig(self::TSCONFIG . "\nmod.SHARED.colPos_list = 0\n");

        $result = $this->write('update', 100, ['colPos' => 1]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringContainsString('mod.SHARED.colPos_list', $text);
        $this->assertStringContainsString('Use one of these columns: 0 (Main)', $text);

        $result = $this->write('create', null, ['pid' => 1, 'CType' => 'text', 'header' => 'Sidebar', 'colPos' => 1]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));

        $result = $this->write('create', null, ['pid' => 1, 'CType' => 'text', 'header' => 'Main', 'colPos' => 0]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testElementInARestrictedColumnStaysEditable(): void
    {
        $this->getConnectionForTable('tt_content')->update('tt_content', ['colPos' => 1], ['uid' => 100]);
        $this->setPageTSconfig(self::TSCONFIG . "\nmod.SHARED.colPos_list = 0\n");

        $result = $this->write('update', 100, ['header' => 'Still editable', 'colPos' => 1]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    private function setPageTSconfig(string $tsConfig): void
    {
        $this->getConnectionForTable('pages')->update('pages', ['TSconfig' => $tsConfig], ['uid' => 1]);
    }

    private function write(string $action, ?int $uid, array $data): CallToolResult
    {
        $params = ['action' => $action, 'table' => 'tt_content', 'data' => $data];
        if ($uid !== null) {
            $params['uid'] = $uid;
        }
        return (new WriteTableTool())->execute($params);
    }

    /**
     * colPos as the MCP client sees it (workspace version, live uid).
     */
    private function readColPos(int $uid): int
    {
        $result = (new ReadTableTool())->execute(['table' => 'tt_content', 'uid' => $uid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return (int)json_decode($result->content[0]->text, true)['records'][0]['colPos'];
    }

    private function createGetPageTool(): GetPageTool
    {
        return new GetPageTool(
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(McpLanguageService::class)
        );
    }
}
