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
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend layout columns can limit the content types they accept
 * (allowedContentTypes / disallowedContentTypes, TYPO3 14). The tools have to
 * show those limits and respect them like the page module does:
 *
 * - GetPage lists every column of the page's layout with the types it accepts
 * - GetTableSchema names the columns of a page that accept a type
 * - WriteTable refuses a content element in a column that does not accept its
 *   type, and names the columns that do
 *
 * Page 7 ("News") uses the page TSconfig layout below: image is only accepted in
 * the banner column 3, the main column 0 refuses it, the sidebar column 1 only
 * takes text and header. Page 5 ("Mission") uses a layout without column 0.
 */
class ContentColumnPlacementTest extends AbstractFunctionalTest
{
    private const PAGE = 7;

    private const LAYOUT_TSCONFIG = <<<'TSCONFIG'
mod.web_layout.BackendLayouts {
    restricted {
        title = Restricted Layout
        config {
            backend_layout {
                colCount = 1
                rowCount = 3
                rows {
                    1 {
                        columns {
                            1 {
                                name = Banner
                                colPos = 3
                                allowedContentTypes = image
                            }
                        }
                    }
                    2 {
                        columns {
                            1 {
                                name = Main
                                colPos = 0
                                disallowedContentTypes = image
                            }
                        }
                    }
                    3 {
                        columns {
                            1 {
                                name = Sidebar
                                colPos = 1
                                allowedContentTypes = text, header
                            }
                        }
                    }
                }
            }
        }
    }
    withoutZero {
        title = Layout without column 0
        config.backend_layout {
            colCount = 1
            rowCount = 1
            rows.1.columns.1 {
                name = Content
                colPos = 5
            }
        }
    }
}
TSCONFIG;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writeSiteConfigurationForRootPage(1);
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/backend_layout.csv');

        $connection = $this->getConnectionForTable('pages');
        $connection->update('pages', ['TSconfig' => self::LAYOUT_TSCONFIG], ['uid' => 1]);
        $connection->update('pages', ['backend_layout' => 'pagets__restricted'], ['uid' => self::PAGE]);
        $connection->update('pages', ['backend_layout' => 'pagets__withoutZero'], ['uid' => 5]);
    }

    /**
     * Guards the premise: the core resolves the page TSconfig layout for page 7.
     */
    public function testCoreResolvesTheRestrictedLayout(): void
    {
        $backendLayoutView = GeneralUtility::makeInstance(BackendLayoutView::class);

        $this->assertSame(
            [3, 0, 1],
            array_map('intval', $backendLayoutView->getBackendLayoutForPage(self::PAGE)->getStructure()['__colPosList'])
        );
    }

    /**
     * Guards the premise: the core enforces the column limits of that layout.
     */
    public function testCoreEnforcesTheColumnLimits(): void
    {
        if (GeneralUtility::makeInstance(\TYPO3\CMS\Core\Information\Typo3Version::class)->getMajorVersion() < 14) {
            $this->markTestSkipped('The core enforces allowedContentTypes / disallowedContentTypes since TYPO3 14.');
        }

        $backendLayoutView = GeneralUtility::makeInstance(BackendLayoutView::class);

        $this->assertTrue($backendLayoutView->isCTypeAllowedInColPosByPage('image', 3, self::PAGE));
        $this->assertFalse($backendLayoutView->isCTypeAllowedInColPosByPage('image', 0, self::PAGE));
    }

    public function testGetPageListsEveryColumnWithTheTypesItAccepts(): void
    {
        $result = $this->getPageTool()->execute(['uid' => self::PAGE]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;

        $this->assertStringContainsString('CONTENT COLUMNS', $text);
        $this->assertMatchesRegularExpression('/colPos 3 "Banner": only image\b/', $text);
        $this->assertMatchesRegularExpression('/colPos 0 "Main": all types except image\b/', $text);
        $this->assertMatchesRegularExpression('/colPos 1 "Sidebar": only text \([^)]*\), header\b/', $text);
    }

    public function testGetPageListsColumnsWithoutRestrictions(): void
    {
        $result = $this->getPageTool()->execute(['uid' => 2]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertStringContainsString('colPos 0 "Main Content Only": all types', $result->content[0]->text);
    }

    public function testSchemaNamesTheColumnsThatAcceptTheType(): void
    {
        $text = $this->schemaText(['table' => 'tt_content', 'type' => 'text', 'pid' => self::PAGE]);

        $this->assertStringContainsString(
            'Columns of page 7 that accept this type: colPos 0 "Main", colPos 1 "Sidebar" (not accepted in colPos 3 "Banner")',
            $text
        );
    }

    public function testSchemaWithoutPidNamesNoColumns(): void
    {
        $text = $this->schemaText(['table' => 'tt_content', 'type' => 'text']);

        $this->assertStringNotContainsString('that accept this type', $text);
    }

    public function testCreateInAColumnThatRefusesTheTypeNamesTheFittingColumn(): void
    {
        $result = $this->write('create', ['pid' => self::PAGE, 'CType' => 'image', 'header' => 'Banner', 'colPos' => 0]);

        $this->assertTrue($result->isError, 'An image must not be saved into column 0');
        $text = $result->content[0]->text;
        $this->assertStringContainsString('colPos 0 "Main" does not accept the type "image"', $text);
        $this->assertStringContainsString('colPos 3 "Banner"', $text);
        $this->assertStringNotContainsString('colPos 1 "Sidebar"', $text);
    }

    public function testCreateWithoutColPosLandsInColumnZeroAndIsRefused(): void
    {
        $result = $this->write('create', ['pid' => self::PAGE, 'CType' => 'image', 'header' => 'Banner']);

        $this->assertTrue($result->isError, 'Without colPos the element lands in column 0, which refuses image');
        $this->assertStringContainsString('colPos 3 "Banner"', $result->content[0]->text);
    }

    public function testCreateInAColumnThatDoesNotExistNamesTheFittingColumn(): void
    {
        $result = $this->write('create', ['pid' => self::PAGE, 'CType' => 'image', 'header' => 'Banner', 'colPos' => 4]);

        $this->assertTrue($result->isError);
        $text = $result->content[0]->text;
        $this->assertStringContainsString('colPos 4 does not exist on page ' . self::PAGE, $text);
        $this->assertStringContainsString('colPos 3 "Banner"', $text);
    }

    public function testCreateWithoutColPosOnALayoutWithoutColumnZeroNamesTheColumns(): void
    {
        $result = $this->write('create', ['pid' => 5, 'CType' => 'text', 'header' => 'Text']);

        $this->assertTrue($result->isError, 'Without colPos the element would land in column 0, which page 5 does not have');
        $text = $result->content[0]->text;
        $this->assertStringContainsString('colPos 0 does not exist on page 5', $text);
        $this->assertStringContainsString('colPos 5 "Content"', $text);
    }

    public function testCreateInTheColumnThatAcceptsTheTypeSucceeds(): void
    {
        $result = $this->write('create', ['pid' => self::PAGE, 'CType' => 'image', 'header' => 'Banner', 'colPos' => 3]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $uid = (int)json_decode($result->content[0]->text, true)['uid'];
        $record = $this->readRecord($uid);
        $this->assertSame('image', $record['CType']);
        $this->assertSame(3, (int)$record['colPos']);
    }

    public function testCreateOfAnUnrestrictedTypeWithoutColPosSucceeds(): void
    {
        $result = $this->write('create', ['pid' => self::PAGE, 'CType' => 'text', 'header' => 'Plain text']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testTypeRefusedByTheSidebarPointsToTheMainColumn(): void
    {
        $result = $this->write('create', ['pid' => self::PAGE, 'CType' => 'bullets', 'header' => 'List', 'colPos' => 1]);

        $this->assertTrue($result->isError);
        $text = $result->content[0]->text;
        $this->assertStringContainsString('colPos 1 "Sidebar" does not accept the type "bullets"', $text);
        $this->assertStringContainsString('colPos 0 "Main"', $text);
    }

    public function testUpdateToATypeTheColumnRefusesIsRefused(): void
    {
        $result = $this->write('update', ['CType' => 'image'], 106);

        $this->assertTrue($result->isError, 'Element 106 sits in column 0, which refuses image');
        $this->assertStringContainsString('colPos 3 "Banner"', $result->content[0]->text);
        $this->assertSame('textmedia', $this->readRecord(106)['CType']);
    }

    public function testMovingAnElementIntoAColumnThatRefusesItsTypeIsRefused(): void
    {
        $created = $this->write('create', ['pid' => self::PAGE, 'CType' => 'image', 'header' => 'Banner', 'colPos' => 3]);
        $this->assertFalse($created->isError, json_encode($created->jsonSerialize()));
        $uid = (int)json_decode($created->content[0]->text, true)['uid'];

        $result = $this->write('update', ['colPos' => 1], $uid);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('colPos 1 "Sidebar" does not accept the type "image"', $result->content[0]->text);
        $this->assertSame(3, (int)$this->readRecord($uid)['colPos']);
    }

    public function testMovingAnElementToAnotherPageChecksTheTargetPageLayout(): void
    {
        // Element 109 is a textmedia in column 1 of page 7; the sidebar refuses textmedia
        $this->assertSame(1, (int)$this->readRecord(109)['colPos']);
        $connection = $this->getConnectionForTable('tt_content');
        $connection->update('tt_content', ['pid' => 6], ['uid' => 109]);

        $result = $this->write('update', ['pid' => self::PAGE], 109);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('colPos 1 "Sidebar" does not accept the type "textmedia"', $result->content[0]->text);
    }

    /**
     * Element 110 sits in colPos 10, which is not part of the layout. Editing its
     * text without touching its column must keep working, as in the backend.
     */
    public function testEditingAnElementInAColumnOutsideTheLayoutStillWorks(): void
    {
        $result = $this->write('update', ['header' => 'Banner Ad (edited)'], 110);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    private function getPageTool(): GetPageTool
    {
        return new GetPageTool(
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(McpLanguageService::class)
        );
    }

    private function write(string $action, array $data, ?int $uid = null): CallToolResult
    {
        $params = ['action' => $action, 'table' => 'tt_content', 'data' => $data];
        if ($uid !== null) {
            $params['uid'] = $uid;
        }
        return GeneralUtility::makeInstance(WriteTableTool::class)->execute($params);
    }

    private function readRecord(int $uid): array
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tt_content',
            'uid' => $uid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $records = json_decode($result->content[0]->text, true)['records'] ?? [];
        $this->assertCount(1, $records);
        return $records[0];
    }

    private function schemaText(array $params): string
    {
        $result = GeneralUtility::makeInstance(GetTableSchemaTool::class)->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return $result->content[0]->text;
    }

    private function writeSiteConfigurationForRootPage(int $rootPageId): void
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
