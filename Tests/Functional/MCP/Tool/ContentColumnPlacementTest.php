<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\Service\LanguageService as McpLanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
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
 *
 * Page 7 ("News") uses the page TSconfig layout below: image is only accepted in
 * the banner column 3, the main column 0 refuses it, the sidebar column 1 only
 * takes text and header.
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
    }

    /**
     * Guards the premise: the core resolves the page TSconfig layout for page 7
     * and enforces its column limits.
     */
    public function testCoreResolvesTheRestrictedLayout(): void
    {
        $backendLayoutView = GeneralUtility::makeInstance(BackendLayoutView::class);

        $this->assertSame(
            [3, 0, 1],
            array_map('intval', $backendLayoutView->getBackendLayoutForPage(self::PAGE)->getStructure()['__colPosList'])
        );
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

    private function getPageTool(): GetPageTool
    {
        return new GetPageTool(
            GeneralUtility::makeInstance(SiteInformationService::class),
            GeneralUtility::makeInstance(McpLanguageService::class)
        );
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
