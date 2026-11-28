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
    single {
        title = One column
        config {
            backend_layout {
                colCount = 1
                rowCount = 1
                rows {
                    1 {
                        columns {
                            1 {
                                name = Main
                                colPos = 0
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

    /**
     * Moving an element to another page without colPos keeps its column. When
     * the target page has no such column, the move is refused instead of hiding
     * the element in a column the page does not show.
     */
    public function testMoveWithoutColPosIsCheckedAgainstTheColumnsOfTheTargetPage(): void
    {
        $this->getConnectionForTable('pages')->update('pages', ['backend_layout' => 'pagets__single'], ['uid' => 2]);
        $this->getConnectionForTable('tt_content')->update('tt_content', ['colPos' => 1], ['uid' => 100]);

        $result = $this->write('update', 100, ['pid' => 2]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;
        $this->assertStringContainsString('would be in colPos 1, which page 2 does not have', $text);
        $this->assertStringContainsString('the backend layout of page 2 has these columns: 0 (Main)', $text);

        $result = $this->write('update', 100, ['pid' => 2, 'colPos' => 0]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testMoveWithoutColPosIsCheckedAgainstTheColPosListOfTheTargetPage(): void
    {
        $this->getConnectionForTable('pages')->update('pages', ['backend_layout' => 'pagets__twocol'], ['uid' => 2]);
        $this->setPageTSconfig("mod.SHARED.colPos_list = 0\n", 2);
        $this->getConnectionForTable('tt_content')->update('tt_content', ['colPos' => 1], ['uid' => 100]);

        $result = $this->write('update', 100, ['pid' => 2]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('mod.SHARED.colPos_list', $result->content[0]->text);
    }

    /**
     * After a move in the workspace, the element lives on the new page. Later
     * writes are checked there, not on the page of the live record.
     */
    public function testWorkspaceVersionIsCheckedOnThePageItWasMovedTo(): void
    {
        $this->getConnectionForTable('pages')->update('pages', ['backend_layout' => 'pagets__twocol'], ['uid' => 2]);
        $this->setPageTSconfig("mod.SHARED.colPos_list = 0\n", 2);

        $result = $this->write('update', 100, ['pid' => 2]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $result = $this->write('update', 100, ['colPos' => 1]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('this column of page 2 is restricted', $result->content[0]->text);
    }

    public function testSingleColumnInAListIsCheckedLikeAPlainValue(): void
    {
        $result = $this->write('update', 100, ['colPos' => [1]]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(1, $this->readColPos(100));

    }

    public function testSingleColumnInAListIsCheckedAgainstTheColPosList(): void
    {
        $this->setPageTSconfig(self::TSCONFIG . "\nmod.SHARED.colPos_list = 0\n");

        $result = $this->write('update', 100, ['colPos' => [1]]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('mod.SHARED.colPos_list', $result->content[0]->text);
    }

    public function testListOfColumnsIsRefused(): void
    {
        $result = $this->write('update', 100, ['colPos' => [0, 1]]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("Field 'colPos' takes a single column number, not a list", $result->content[0]->text);
    }

    /**
     * DataHandler places a record with "after:N" on N's page, whatever pid says
     * (#50). The columns are therefore checked on N's page: page 2 has only
     * column 0, so column 1 is refused there although page 1 has it. Record 102
     * is on page 2, record 101 on page 1.
     */
    public function testCreatePositionedNextToARecordOnAnotherPageIsCheckedOnThatPage(): void
    {
        $this->getConnectionForTable('pages')->update('pages', ['backend_layout' => 'pagets__single'], ['uid' => 2]);

        $result = $this->write('create', null, ['pid' => 1, 'CType' => 'text', 'header' => 'Elsewhere', 'colPos' => 1], 'after:102');
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('the backend layout of page 2 has these columns: 0 (Main)', $result->content[0]->text);
        $this->assertSame(0, $this->countRecordsWithHeader('Elsewhere'));

        $result = $this->write('create', null, ['pid' => 1, 'CType' => 'text', 'header' => 'Elsewhere', 'colPos' => 0], 'after:102');
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testCreatePositionedNextToARecordOnTheSamePageWorks(): void
    {
        $result = $this->write('create', null, ['pid' => 1, 'CType' => 'text', 'header' => 'Next to 101', 'colPos' => 1], 'after:101');
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $result = $this->write('create', null, ['pid' => 1, 'CType' => 'text', 'header' => 'Before 101', 'colPos' => 0], 'before:101');
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * An update with "after:N" moves the element to N's page like a pid would,
     * so its column is checked there.
     */
    public function testUpdatePositionedNextToARecordOnAnotherPageIsCheckedOnThatPage(): void
    {
        $this->getConnectionForTable('pages')->update('pages', ['backend_layout' => 'pagets__single'], ['uid' => 2]);
        $this->getConnectionForTable('tt_content')->update('tt_content', ['colPos' => 1], ['uid' => 100]);

        $result = $this->write('update', 100, ['header' => 'Moved'], 'after:102');
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('would be in colPos 1, which page 2 does not have', $result->content[0]->text);

        $result = $this->write('update', 100, ['header' => 'Moved', 'colPos' => 0], 'after:102');
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $page = $this->getConnectionForTable('tt_content')
            ->select(['pid'], 'tt_content', ['t3ver_oid' => 100])
            ->fetchOne();
        $this->assertSame(2, (int)$page);
    }

    /**
     * "1," passes the select validation as column 1 and DataHandler stores 1,
     * so the column checks have to see 1 as well.
     */
    public function testCommaSeparatedSingleColumnIsCheckedLikeAPlainValue(): void
    {
        $this->setPageTSconfig(self::TSCONFIG . "\nmod.SHARED.colPos_list = 0\n");

        $result = $this->write('update', 100, ['colPos' => '1,']);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('mod.SHARED.colPos_list', $result->content[0]->text);

        $result = $this->write('update', 100, ['colPos' => '0,1']);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("Field 'colPos' takes a single column number, not a list", $result->content[0]->text);
    }

    private function countRecordsWithHeader(string $header): int
    {
        return (int)$this->getConnectionForTable('tt_content')->count('uid', 'tt_content', ['header' => $header]);
    }

    private function setPageTSconfig(string $tsConfig, int $pageUid = 1): void
    {
        $this->getConnectionForTable('pages')->update('pages', ['TSconfig' => $tsConfig], ['uid' => $pageUid]);
    }

    private function write(string $action, ?int $uid, array $data, ?string $position = null): CallToolResult
    {
        $params = ['action' => $action, 'table' => 'tt_content', 'data' => $data];
        if ($uid !== null) {
            $params['uid'] = $uid;
        }
        if ($position !== null) {
            $params['position'] = $position;
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
