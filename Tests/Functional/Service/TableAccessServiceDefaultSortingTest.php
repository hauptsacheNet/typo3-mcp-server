<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Service\TableAccessService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Test parsing of ctrl.default_sortby
 *
 * TYPO3 accepts a leading "ORDER BY" in default_sortby (see QueryHelper::parseOrderBy()).
 * The prefix must be stripped, otherwise ReadTable builds invalid SQL and fails with
 * "Failed to select record".
 *
 * @see https://github.com/hauptsacheNet/typo3-mcp-server/issues/138
 */
class TableAccessServiceDefaultSortingTest extends AbstractFunctionalTest
{
    protected TableAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TableAccessService();
    }

    public static function defaultSortingProvider(): array
    {
        return [
            'single field' => [
                'title',
                [['field' => 'title', 'direction' => 'ASC']],
            ],
            'single field with direction' => [
                'title DESC',
                [['field' => 'title', 'direction' => 'DESC']],
            ],
            'lowercase direction' => [
                'title desc',
                [['field' => 'title', 'direction' => 'DESC']],
            ],
            'multiple fields' => [
                'title, crdate DESC, uid ASC',
                [
                    ['field' => 'title', 'direction' => 'ASC'],
                    ['field' => 'crdate', 'direction' => 'DESC'],
                    ['field' => 'uid', 'direction' => 'ASC'],
                ],
            ],
            'ORDER BY prefix' => [
                'ORDER BY title',
                [['field' => 'title', 'direction' => 'ASC']],
            ],
            'ORDER BY prefix with direction' => [
                'ORDER BY title DESC',
                [['field' => 'title', 'direction' => 'DESC']],
            ],
            'ORDER BY prefix with multiple fields' => [
                'ORDER BY title, crdate DESC',
                [
                    ['field' => 'title', 'direction' => 'ASC'],
                    ['field' => 'crdate', 'direction' => 'DESC'],
                ],
            ],
            'lowercase order by prefix with surrounding whitespace' => [
                '  order   by title desc ',
                [['field' => 'title', 'direction' => 'DESC']],
            ],
        ];
    }

    #[DataProvider('defaultSortingProvider')]
    public function testParseDefaultSorting(string $defaultSortby, array $expected): void
    {
        $GLOBALS['TCA']['sys_file']['ctrl']['default_sortby'] = $defaultSortby;

        $this->assertSame($expected, $this->service->parseDefaultSorting('sys_file'));
    }

    public function testParseDefaultSortingWithoutConfigurationReturnsEmptyArray(): void
    {
        unset($GLOBALS['TCA']['sys_file']['ctrl']['default_sortby']);

        $this->assertSame([], $this->service->parseDefaultSorting('sys_file'));
    }

    /**
     * ReadTable must apply a default_sortby with "ORDER BY" prefix as real sorting.
     *
     * Note: SQLite treats an unknown double-quoted identifier like "ORDER BY name" as a
     * string literal, so the broken query does not fail here as it does on MySQL/MariaDB.
     * The record order is asserted instead: a constant literal does not sort at all.
     */
    public function testReadTableAppliesDefaultSortingWithOrderByPrefix(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file.csv');
        $GLOBALS['TCA']['sys_file']['ctrl']['default_sortby'] = 'ORDER BY name DESC';

        $result = (new ReadTableTool())->execute([
            'table' => 'sys_file',
            'uid' => [1, 2, 3, 4, 5],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $payload = json_decode($result->content[0]->text, true);
        // name DESC: test.jpg, team-photo.jpg, person.jpg, logo.png, document.pdf
        $this->assertSame([1, 5, 3, 4, 2], array_column($payload['records'], 'uid'));
    }
}
