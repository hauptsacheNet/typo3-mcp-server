<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Converts the values of date and time fields between their storage format and
 * the ISO 8601 strings the MCP tools show and accept.
 *
 * TCA `type => 'datetime'` stores either a Unix timestamp (int column, the
 * default), seconds since midnight (`format => 'time'|'timesec'`), or, with
 * `dbType => 'date'|'datetime'|'time'`, a native value in server local time.
 *
 * Values without an offset are wall-clock times in the TYPO3 server timezone,
 * which is what an editor types into the backend form. They are converted here
 * into the value DataHandler stores verbatim: an int for int columns, a local
 * database string for native columns. Handing DataHandler an ISO string instead
 * is not safe: TYPO3 13 treats such strings as the "fake UTC" values its
 * date picker sends and shifts them by the server's UTC offset.
 */
class DateTimeFieldService
{
    /** Formats DataHandler and the TCA schema know for type=datetime. */
    private const FORMATS = ['datetime', 'datetimesec', 'date', 'time', 'timesec'];

    /** Field names treated as timestamps when the table has no TCA column for them. */
    private const UNCONFIGURED_TIMESTAMP_FIELDS = ['tstamp', 'crdate', 'starttime', 'endtime', 'lastlogin'];

    /**
     * ISO 8601 date, optionally followed by a time (separated by "T" or a space)
     * and an offset ("Z", "+01:00", "+0100").
     */
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/i';

    /** Time of day, optionally with seconds. */
    private const TIME_PATTERN = '/^(\d{2}):(\d{2})(?::(\d{2}))?$/';

    /**
     * The date/time settings of a field, or null when it is no date or time field.
     *
     * @return array{format: string, dbType: ?string, nullable: bool}|null
     */
    public function getFieldSettings(string $table, string $fieldName): ?array
    {
        $columnConfig = $GLOBALS['TCA'][$table]['columns'][$fieldName]['config'] ?? null;
        if (is_array($columnConfig)) {
            return $this->getSettingsFromConfig($columnConfig);
        }

        // Control fields like tstamp and crdate usually have no TCA column.
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        if (in_array($fieldName, [$ctrl['tstamp'] ?? null, $ctrl['crdate'] ?? null], true)
            || in_array($fieldName, self::UNCONFIGURED_TIMESTAMP_FIELDS, true)
        ) {
            return ['format' => 'datetime', 'dbType' => null, 'nullable' => false];
        }

        return null;
    }

    /**
     * The date/time settings of a TCA field config, or null when it is no date
     * or time field. The format resolution mirrors TYPO3's DateTimeFieldType.
     *
     * @return array{format: string, dbType: ?string, nullable: bool}|null
     */
    public function getSettingsFromConfig(array $config): ?array
    {
        $type = $config['type'] ?? '';
        $format = null;

        if ($type === 'datetime') {
            $format = $config['format'] ?? null;
        } elseif ($type === 'input') {
            // Pre TYPO3 12 definitions; TCA migration normally turns them into type=datetime
            $evalRules = GeneralUtility::trimExplode(',', (string)($config['eval'] ?? ''), true);
            foreach (['datetime', 'date', 'timesec', 'time'] as $evalFormat) {
                if (in_array($evalFormat, $evalRules, true)) {
                    $format = $evalFormat;
                    break;
                }
            }
            if ($format === null && ($config['renderType'] ?? '') !== 'inputDateTime') {
                return null;
            }
        } else {
            return null;
        }

        $dbType = in_array($config['dbType'] ?? null, QueryHelper::getDateTimeTypes(), true) ? $config['dbType'] : null;

        // A native time column holds no date, a native date column no time
        if ($dbType === 'time' && !in_array($format, ['time', 'timesec'], true)) {
            $format = 'timesec';
        } elseif ($dbType === 'date') {
            $format = 'date';
        } elseif (!in_array($format, self::FORMATS, true)) {
            $format = 'datetime';
        }

        return [
            'format' => $format,
            'dbType' => $dbType,
            // Native columns are nullable unless explicitly configured otherwise
            'nullable' => (bool)($config['nullable'] ?? ($dbType !== null)),
        ];
    }

    /**
     * Format a stored value for tool output: ISO 8601 with the server's UTC
     * offset for date and time ("2026-11-27T10:00:00+01:00"), a plain date
     * ("2026-11-27") for date fields and the time of day ("10:00") for time
     * fields. Empty values become null.
     *
     * @param array{format: string, dbType: ?string, nullable: bool} $settings
     */
    public function formatForOutput(mixed $value, array $settings): mixed
    {
        $dateTime = $this->createFromStorage($value, $settings);
        if ($dateTime === null) {
            // Leave values we cannot interpret untouched rather than hiding them
            return ($value === null || $value === '' || $this->isEmptyStorageValue($value, $settings)) ? null : $value;
        }

        return match ($settings['format']) {
            'date' => $dateTime->format('Y-m-d'),
            'time' => $dateTime->format('H:i'),
            'timesec' => $dateTime->format('H:i:s'),
            default => $dateTime->format(\DateTimeInterface::ATOM),
        };
    }

    /**
     * Format a Unix timestamp as ISO 8601 in the server timezone.
     */
    public function formatTimestamp(int $timestamp): string
    {
        return $this->createFromTimestamp($timestamp)->format(\DateTimeInterface::ATOM);
    }

    /**
     * Convert a value given to WriteTable into the value DataHandler stores as is.
     *
     * Accepts ISO 8601 strings (with or without "T", seconds or offset; values
     * without offset are server local time), a plain date for date fields, a
     * time of day for time fields, and integers as the raw stored value (a Unix
     * timestamp, or seconds since midnight for time fields). An empty string or
     * null empties the field.
     *
     * @param array{format: string, dbType: ?string, nullable: bool} $settings
     * @throws \InvalidArgumentException when the value is no recognizable date
     */
    public function convertForStorage(mixed $value, array $settings): int|string|null
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $format = $settings['format'];
        $dbType = $settings['dbType'];
        $isTime = $format === 'time' || $format === 'timesec';

        if (is_int($value) || (is_string($value) && MathUtility::canBeInterpretedAsInteger($value))) {
            // The raw stored value (timestamp, or seconds since midnight for time fields)
            if ($dbType === null) {
                return (int)$value;
            }
            $dateTime = $isTime
                ? new \DateTimeImmutable(gmdate('Y-m-d\TH:i:s', (int)$value))
                : $this->createFromTimestamp((int)$value);
        } elseif (is_string($value)) {
            $dateTime = $this->parse(trim($value), $isTime);
        } else {
            $dateTime = null;
        }

        if ($dateTime === null) {
            throw new \InvalidArgumentException($this->describeExpectedInput($settings));
        }

        // The same normalizations DataHandler applies for the format
        if ($format === 'time') {
            $dateTime = $dateTime->setTime((int)$dateTime->format('H'), (int)$dateTime->format('i'), 0);
        } elseif ($format === 'date') {
            $dateTime = $dateTime->setTime(0, 0, 0);
        }

        if ($dbType !== null) {
            return $dateTime->format(QueryHelper::getDateTimeFormats()[$dbType]['format']);
        }
        if ($isTime) {
            return (int)$dateTime->format('H') * 3600 + (int)$dateTime->format('i') * 60 + (int)$dateTime->format('s');
        }

        return $dateTime->getTimestamp();
    }

    /**
     * What a field accepts, for error messages and schema descriptions.
     *
     * @param array{format: string, dbType: ?string, nullable: bool} $settings
     */
    public function describeExpectedInput(array $settings): string
    {
        return match ($settings['format']) {
            'date' => 'a date like "2026-11-27"',
            'time' => 'a time of day like "14:30"',
            'timesec' => 'a time of day like "14:30:15"',
            default => 'an ISO 8601 date and time like "2026-11-27T14:30:00" (server time "'
                . date_default_timezone_get() . '" unless an offset like "+01:00" or "Z" is given)',
        };
    }

    /**
     * @param array{format: string, dbType: ?string, nullable: bool} $settings
     */
    private function createFromStorage(mixed $value, array $settings): ?\DateTimeImmutable
    {
        if ($value === null || $value === '' || $this->isEmptyStorageValue($value, $settings)) {
            return null;
        }

        $isTime = $settings['format'] === 'time' || $settings['format'] === 'timesec';

        if ($settings['dbType'] === null) {
            if (!MathUtility::canBeInterpretedAsInteger($value)) {
                return null;
            }
            // Time fields hold seconds since midnight, independent of any timezone
            return $isTime
                ? new \DateTimeImmutable(gmdate('Y-m-d\TH:i:s', (int)$value))
                : $this->createFromTimestamp((int)$value);
        }

        // Native columns hold server local time like "2026-11-27 10:00:00"
        try {
            return new \DateTimeImmutable((string)$value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array{format: string, dbType: ?string, nullable: bool} $settings
     */
    private function isEmptyStorageValue(mixed $value, array $settings): bool
    {
        $dbType = $settings['dbType'];
        if ($dbType === null) {
            // 0 is "no date" in a non-nullable int column, like in DataHandler
            return !$settings['nullable'] && MathUtility::canBeInterpretedAsInteger($value) && (int)$value === 0;
        }
        // Legacy empty values of native columns
        return $dbType !== 'time' && $value === QueryHelper::getDateTimeFormats()[$dbType]['empty'];
    }

    private function parse(string $value, bool $timeOnly): ?\DateTimeImmutable
    {
        if ($timeOnly && preg_match(self::TIME_PATTERN, $value, $matches)) {
            [$hours, $minutes, $seconds] = [(int)$matches[1], (int)$matches[2], (int)($matches[3] ?? 0)];
            if ($hours > 23 || $minutes > 59 || $seconds > 59) {
                return null;
            }
            return new \DateTimeImmutable(sprintf('1970-01-01T%02d:%02d:%02d', $hours, $minutes, $seconds));
        }

        if (!preg_match(self::DATE_PATTERN, $value)) {
            return null;
        }

        $serverTimezone = new \DateTimeZone(date_default_timezone_get());
        try {
            // Without an offset, the value is wall-clock time in the server timezone
            $dateTime = new \DateTimeImmutable($value, $serverTimezone);
        } catch (\Exception) {
            return null;
        }

        // PHP silently rolls invalid dates over ("2026-02-30" becomes March 2nd)
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }
        if (!checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4))) {
            return null;
        }

        return $dateTime->setTimezone($serverTimezone);
    }

    private function createFromTimestamp(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
