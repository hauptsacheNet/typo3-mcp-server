<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TCA type=datetime fields (TYPO3 12+) in every storage variant: Unix timestamps,
 * seconds since midnight (format=time) and native DATETIME/DATE/TIME columns
 * (dbType).
 *
 * The instance runs in Europe/Berlin on purpose. In UTC a value that is shifted
 * by the server's UTC offset (TYPO3 13 reads an ISO string without offset as the
 * "fake UTC" value its date picker sends) looks right, so the bug would not show.
 */
class DateTimeFieldTest extends FunctionalTestCase
{
    private const TABLE = 'tx_testdatetime_event';

    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_datetime_fields',
        'mcp_server',
        'news',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'phpTimeZone' => 'Europe/Berlin',
        ],
    ];

    private string $previousTimezone;

    protected function setUp(): void
    {
        // Bootstrap keeps the current default timezone when phpTimeZone is empty,
        // so restore it for the tests that share this process.
        $this->previousTimezone = date_default_timezone_get();
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        date_default_timezone_set($this->previousTimezone);
    }

    public function testServerRunsInEuropeBerlin(): void
    {
        $this->assertSame('Europe/Berlin', date_default_timezone_get());
    }

    public static function newsDatetimeProvider(): array
    {
        return [
            'database format without offset is server time' => [
                '2026-11-27 10:00:00',
                1795770000, // 2026-11-27T09:00:00Z
                '2026-11-27T10:00:00+01:00',
            ],
            'ISO 8601 with T and without seconds is server time' => [
                '2026-11-27T10:00',
                1795770000,
                '2026-11-27T10:00:00+01:00',
            ],
            'ISO 8601 with UTC offset' => [
                '2026-11-27T10:00:00+00:00',
                1795773600, // 2026-11-27T10:00:00Z
                '2026-11-27T11:00:00+01:00',
            ],
            'ISO 8601 with Z' => [
                '2026-11-27T10:00:00Z',
                1795773600,
                '2026-11-27T11:00:00+01:00',
            ],
            'ISO 8601 with a foreign offset' => [
                '2026-11-27T10:00:00-05:00',
                1795791600, // 2026-11-27T15:00:00Z
                '2026-11-27T16:00:00+01:00',
            ],
            'summer time without offset' => [
                '2026-07-15 10:00:00',
                1784102400, // 2026-07-15T08:00:00Z
                '2026-07-15T10:00:00+02:00',
            ],
            'summer time with summer offset' => [
                '2026-07-15T10:00:00+02:00',
                1784102400,
                '2026-07-15T10:00:00+02:00',
            ],
            'a Unix timestamp is stored as is' => [
                1795770000,
                1795770000,
                '2026-11-27T10:00:00+01:00',
            ],
        ];
    }

    /**
     * The scenario from a real TYPO3 13.4 install: news "datetime" is type=datetime
     * without eval. "10:00" must be stored as 10:00 server time, which the backend
     * form shows as 10:00, and ReadTable must return ISO 8601 instead of a timestamp.
     */
    #[DataProvider('newsDatetimeProvider')]
    public function testNewsDatetimeRoundTrip(string|int $input, int $expectedTimestamp, string $expectedOutput): void
    {
        $uid = $this->write('tx_news_domain_model_news', ['title' => 'Dated news', 'datetime' => $input]);

        $stored = (int)BackendUtility::getRecordWSOL('tx_news_domain_model_news', $uid)['datetime'];
        $this->assertSame($expectedTimestamp, $stored);

        $this->assertSame($expectedOutput, $this->read('tx_news_domain_model_news', $uid)['datetime']);
    }

    public function testNewsDatetimeShowsTheWrittenWallClockTime(): void
    {
        $uid = $this->write('tx_news_domain_model_news', ['title' => 'Dated news', 'datetime' => '2026-11-27 10:00:00']);

        // What the backend form shows: the timestamp in server time
        $stored = (int)BackendUtility::getRecordWSOL('tx_news_domain_model_news', $uid)['datetime'];
        $this->assertSame('2026-11-27 10:00', date('Y-m-d H:i', $stored));
    }

    public function testUpdateKeepsTheTimeAndReadOutputCanBeWrittenBack(): void
    {
        $uid = $this->write(self::TABLE, [
            'title' => 'Event',
            'event_datetime' => '2026-11-27 10:00',
            'native_datetime' => '2026-07-15 10:00',
        ]);
        $record = $this->read(self::TABLE, $uid);

        // Writing back exactly what ReadTable returned changes nothing
        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => self::TABLE,
            'uid' => $uid,
            'data' => [
                'event_datetime' => $record['event_datetime'],
                'native_datetime' => $record['native_datetime'],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $reread = $this->read(self::TABLE, $uid);
        $this->assertSame('2026-11-27T10:00:00+01:00', $reread['event_datetime']);
        $this->assertSame('2026-07-15T10:00:00+02:00', $reread['native_datetime']);
    }

    public function testDateFieldStoresMidnightServerTime(): void
    {
        $uid = $this->write(self::TABLE, ['title' => 'Event', 'event_date' => '2026-11-27']);

        $stored = (int)BackendUtility::getRecordWSOL(self::TABLE, $uid)['event_date'];
        $this->assertSame(1795734000, $stored); // 2026-11-26T23:00:00Z
        $this->assertSame('2026-11-27', $this->read(self::TABLE, $uid)['event_date']);
    }

    public function testDateFieldDropsTheTimeOfADateTimeValue(): void
    {
        $uid = $this->write(self::TABLE, ['title' => 'Event', 'event_date' => '2026-07-15T18:30:00']);

        $stored = (int)BackendUtility::getRecordWSOL(self::TABLE, $uid)['event_date'];
        $this->assertSame('2026-07-15 00:00:00', date('Y-m-d H:i:s', $stored));
        $this->assertSame('2026-07-15', $this->read(self::TABLE, $uid)['event_date']);
    }

    public function testTimeFieldStoresSecondsSinceMidnight(): void
    {
        $uid = $this->write(self::TABLE, ['title' => 'Event', 'event_time' => '14:30']);

        $this->assertSame(52200, (int)BackendUtility::getRecordWSOL(self::TABLE, $uid)['event_time']);
        $this->assertSame('14:30', $this->read(self::TABLE, $uid)['event_time']);
    }

    public static function nativeDatetimeProvider(): array
    {
        return [
            'without offset' => ['2026-11-27 10:00:00', '2026-11-27 10:00:00', '2026-11-27T10:00:00+01:00'],
            'ISO 8601 with T' => ['2026-11-27T10:00:00', '2026-11-27 10:00:00', '2026-11-27T10:00:00+01:00'],
            'with UTC offset' => ['2026-11-27T10:00:00Z', '2026-11-27 11:00:00', '2026-11-27T11:00:00+01:00'],
            'summer time' => ['2026-07-15 10:00', '2026-07-15 10:00:00', '2026-07-15T10:00:00+02:00'],
            'summer time with UTC offset' => ['2026-07-15T10:00:00+00:00', '2026-07-15 12:00:00', '2026-07-15T12:00:00+02:00'],
        ];
    }

    /**
     * Native DATETIME columns (dbType) hold server local time, not a timestamp.
     */
    #[DataProvider('nativeDatetimeProvider')]
    public function testNativeDatetimeField(string $input, string $expectedDatabaseValue, string $expectedOutput): void
    {
        $uid = $this->write(self::TABLE, ['title' => 'Event', 'native_datetime' => $input]);

        $this->assertSame($expectedDatabaseValue, BackendUtility::getRecordWSOL(self::TABLE, $uid)['native_datetime']);
        $this->assertSame($expectedOutput, $this->read(self::TABLE, $uid)['native_datetime']);
    }

    public function testNativeDateAndTimeFields(): void
    {
        $uid = $this->write(self::TABLE, [
            'title' => 'Event',
            'native_date' => '2026-11-27',
            'native_time' => '14:30',
        ]);

        $row = BackendUtility::getRecordWSOL(self::TABLE, $uid);
        $this->assertSame('2026-11-27', $row['native_date']);
        $this->assertSame('14:30:00', $row['native_time']);

        $record = $this->read(self::TABLE, $uid);
        $this->assertSame('2026-11-27', $record['native_date']);
        $this->assertSame('14:30', $record['native_time']);
    }

    public function testEmptyValuesReadAsNull(): void
    {
        $uid = $this->write(self::TABLE, ['title' => 'Event without dates']);

        $record = $this->read(self::TABLE, $uid);
        foreach (['event_datetime', 'event_date', 'event_time', 'native_datetime', 'native_date', 'native_time'] as $field) {
            $this->assertArrayHasKey($field, $record);
            $this->assertNull($record[$field], $field);
        }
    }

    public function testEmptyStringClearsTheField(): void
    {
        $uid = $this->write(self::TABLE, [
            'title' => 'Event',
            'event_datetime' => '2026-11-27 10:00',
            'native_datetime' => '2026-11-27 10:00',
        ]);

        $result = (new WriteTableTool())->execute([
            'action' => 'update',
            'table' => self::TABLE,
            'uid' => $uid,
            'data' => ['event_datetime' => '', 'native_datetime' => ''],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $record = $this->read(self::TABLE, $uid);
        $this->assertNull($record['event_datetime']);
        $this->assertNull($record['native_datetime']);
    }

    public static function invalidValueProvider(): array
    {
        return [
            'free text' => ['event_datetime', 'next friday'],
            'impossible date' => ['event_datetime', '2026-02-30 10:00'],
            'impossible time' => ['event_datetime', '2026-11-27 25:00'],
            'date in another notation' => ['event_date', '27.11.2026'],
            'invalid time of day' => ['event_time', '24:30'],
            'native field' => ['native_datetime', 'tomorrow'],
        ];
    }

    #[DataProvider('invalidValueProvider')]
    public function testInvalidValuesAreRejected(string $field, string $value): void
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => self::TABLE,
            'pid' => 1,
            'data' => ['title' => 'Event', $field => $value],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString("Field '{$field}' expects", $result->content[0]->text);
    }

    public function testFilterAcceptsIsoDates(): void
    {
        $early = $this->write(self::TABLE, [
            'title' => 'Early',
            'event_datetime' => '2026-11-27 09:00',
            'native_datetime' => '2026-11-27 09:00',
            'event_date' => '2026-11-26',
        ]);
        $late = $this->write(self::TABLE, [
            'title' => 'Late',
            'event_datetime' => '2026-11-27 10:00',
            'native_datetime' => '2026-11-27 10:00',
            'event_date' => '2026-11-27',
        ]);

        $this->assertSame([$late], $this->filterUids([
            ['field' => 'event_datetime', 'operator' => '>=', 'value' => '2026-11-27T10:00:00+01:00'],
        ]));
        $this->assertSame([$early], $this->filterUids([
            ['field' => 'native_datetime', 'operator' => '<', 'value' => '2026-11-27 10:00'],
        ]));
        $this->assertSame([$late], $this->filterUids([
            ['field' => 'event_date', 'operator' => 'in', 'value' => ['2026-11-27']],
        ]));
        // Raw timestamps keep working
        $this->assertSame([$late], $this->filterUids([
            ['field' => 'event_datetime', 'operator' => '=', 'value' => 1795770000],
        ]));
    }

    public function testFilterRejectsInvalidDates(): void
    {
        $result = (new ReadTableTool())->execute([
            'table' => self::TABLE,
            'where' => [['field' => 'event_datetime', 'operator' => '>', 'value' => 'yesterday']],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('holds dates', $result->content[0]->text);
    }

    public function testSchemaDescribesTheExpectedFormat(): void
    {
        $result = (new GetTableSchemaTool())->execute(['table' => self::TABLE]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $schema = $result->content[0]->text;

        $this->assertMatchesRegularExpression('/event_datetime .*datetime \[format: datetime - write as .*2026-11-27T14:30:00.*Europe\/Berlin/', $schema);
        $this->assertMatchesRegularExpression('/event_date .*\[format: date - write as a date like "2026-11-27"\]/', $schema);
        $this->assertMatchesRegularExpression('/event_time .*\[format: time - write as a time of day like "14:30"\]/', $schema);
        $this->assertMatchesRegularExpression('/native_time .*\[format: time - write as a time of day like "14:30"\]/', $schema);
    }

    private function write(string $table, array $data): int
    {
        $result = (new WriteTableTool())->execute([
            'action' => 'create',
            'table' => $table,
            'pid' => 1,
            'data' => $data,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return (int)json_decode($result->content[0]->text, true)['uid'];
    }

    private function read(string $table, int $uid): array
    {
        $result = (new ReadTableTool())->execute(['table' => $table, 'uid' => $uid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $records = json_decode($result->content[0]->text, true)['records'];
        $this->assertCount(1, $records);

        return $records[0];
    }

    /**
     * @return list<int>
     */
    private function filterUids(array $where): array
    {
        $result = (new ReadTableTool())->execute(['table' => self::TABLE, 'pid' => 1, 'where' => $where]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return array_map(
            static fn(array $record): int => (int)$record['uid'],
            json_decode($result->content[0]->text, true)['records']
        );
    }
}
