<?php

declare(strict_types=1);

namespace Hn\McpServer\Database\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Translates a list of filter clauses into QueryBuilder expressions.
 *
 * The point is that no caller-supplied text ever reaches SQL. A field name is
 * checked against the table's accessible fields, an operator against a fixed
 * set, and a value is bound as a parameter — so the three things a WHERE clause
 * is made of are each validated separately, and none of them is concatenated.
 *
 * This replaces a raw SQL string guarded by a keyword blocklist. That blocklist
 * did not list SELECT, so a subquery against any table passed it, and it never
 * looked at field access at all — a filter could read a column the tool would
 * never hand out. It also rejected legitimate values: `header contains "update"`
 * tripped the DROP/DELETE/UPDATE substring check.
 *
 * Clauses are AND-combined. OR is deliberately absent: it invites nesting, and
 * nesting is where a filter language starts growing an evaluator. Two calls
 * with different filters cost less than a grammar.
 */
final class RecordFilterBuilder
{
    /**
     * Operators and the QueryBuilder expression each maps to. LIKE operators
     * carry their wildcard pattern; the value is escaped before it is placed
     * into the pattern, so a `%` in the value matches a literal `%`.
     */
    private const OPERATORS = [
        '=' => 'eq',
        '!=' => 'neq',
        '<' => 'lt',
        '<=' => 'lte',
        '>' => 'gt',
        '>=' => 'gte',
        'in' => 'in',
        'notIn' => 'notIn',
        'contains' => 'like',
        'startsWith' => 'like',
        'endsWith' => 'like',
        'isNull' => 'isNull',
        'isNotNull' => 'isNotNull',
    ];

    /** Operators taking a list rather than a scalar. */
    private const LIST_OPERATORS = ['in', 'notIn'];

    /** Operators taking no value at all. */
    private const VALUELESS_OPERATORS = ['isNull', 'isNotNull'];

    /** Operators matching a substring, which only makes sense on text. */
    private const TEXT_OPERATORS = ['contains', 'startsWith', 'endsWith'];

    /**
     * Control fields a filter may name even though they are not TCA columns and
     * therefore have no field-level access of their own. They are the record's
     * identity and location, both already readable in every result row.
     */
    private const ALWAYS_FILTERABLE = ['uid', 'pid'];

    /**
     * ctrl keys naming integer columns that usually have no TCA column, so
     * their type cannot be read from TCA: timestamps and manual sorting.
     */
    private const NUMERIC_CTRL_KEYS = ['tstamp', 'crdate', 'sortby'];

    /** TCA types whose values are numbers, so a filter must supply integers. */
    private const NUMERIC_TCA_TYPES = ['number', 'check', 'language', 'datetime'];

    /**
     * Filterable field names per table and TSconfig page, computed once: the
     * builder is applied to both the result and the count query.
     *
     * @var array<string, array<string, true>>
     */
    private array $filterableFields = [];

    public function __construct(
        private readonly TableAccessService $tableAccessService,
    ) {}

    /**
     * Add every clause to the query builder as an AND condition.
     *
     * Applied per query builder rather than once: parameter names are bound to
     * a single builder, so a result query and its count query each get their
     * own binding of the same clauses.
     *
     * @param array<int, mixed> $clauses
     * @throws ValidationException on an unknown field, operator, or a value the field cannot hold
     */
    public function apply(QueryBuilder $queryBuilder, string $table, array $clauses, ?int $pid = null): void
    {
        // A single clause object instead of a list of them is a likely slip.
        // Its string keys would otherwise reach buildExpression()'s int index
        // and fail as a TypeError rather than a message the caller can act on.
        if (!array_is_list($clauses)) {
            throw new ValidationException([
                'The "where" parameter must be a list of filter objects, not a single object. '
                . 'Wrap it in brackets, e.g. [{"field": "CType", "operator": "=", "value": "textmedia"}].',
            ]);
        }

        foreach ($clauses as $index => $clause) {
            $queryBuilder->andWhere($this->buildExpression($queryBuilder, $table, $clause, $index, $pid));
        }
    }

    /**
     * @param mixed $clause
     * @throws ValidationException
     */
    private function buildExpression(QueryBuilder $queryBuilder, string $table, $clause, int $index, ?int $pid): string
    {
        $position = 'where[' . $index . ']';

        if (!is_array($clause)) {
            throw new ValidationException([sprintf(
                '%s must be an object with "field", "operator" and (unless the operator is isNull/isNotNull) "value".',
                $position
            )]);
        }

        $field = $clause['field'] ?? null;
        if (!is_string($field) || $field === '') {
            throw new ValidationException([$position . '.field must be a non-empty field name.']);
        }

        $operator = $clause['operator'] ?? null;
        if (!is_string($operator) || !isset(self::OPERATORS[$operator])) {
            throw new ValidationException([sprintf(
                '%s.operator "%s" is not supported. Use one of: %s.',
                $position,
                is_string($operator) ? $operator : get_debug_type($operator),
                implode(', ', array_keys(self::OPERATORS))
            )]);
        }

        $this->assertFieldIsFilterable($table, $field, $position, $pid);

        // Not pre-quoted: TYPO3's ExpressionBuilder quotes the identifier
        // itself, and quoting twice yields """field""", which matches nothing.
        // Passing the name through is safe because it was just checked against
        // the table's readable fields — that check is what makes this an
        // allow-list rather than string trust.
        $expression = $queryBuilder->expr();

        if (in_array($operator, self::VALUELESS_OPERATORS, true)) {
            if (array_key_exists('value', $clause)) {
                throw new ValidationException([sprintf(
                    '%s uses operator "%s", which compares against no value — remove "value".',
                    $position,
                    $operator
                )]);
            }

            return $operator === 'isNull'
                ? (string)$expression->isNull($field)
                : (string)$expression->isNotNull($field);
        }

        if (!array_key_exists('value', $clause)) {
            throw new ValidationException([$position . '.value is required for operator "' . $operator . '".']);
        }
        $value = $clause['value'];

        if (in_array($operator, self::LIST_OPERATORS, true)) {
            return (string)$expression->{self::OPERATORS[$operator]}(
                $field,
                $queryBuilder->createNamedParameter(
                    $this->assertList($table, $field, $value, $operator, $position),
                    $this->isNumericField($table, $field)
                        ? ArrayParameterType::INTEGER
                        : ArrayParameterType::STRING
                )
            );
        }

        if (in_array($operator, self::TEXT_OPERATORS, true)) {
            return (string)$expression->like(
                $field,
                $queryBuilder->createNamedParameter(
                    $this->likePattern($queryBuilder, $field, $value, $operator, $position)
                )
            );
        }

        return (string)$expression->{self::OPERATORS[$operator]}(
            $field,
            $queryBuilder->createNamedParameter(
                $this->assertScalar($table, $field, $value, $position),
                $this->isNumericField($table, $field) ? ParameterType::INTEGER : ParameterType::STRING
            )
        );
    }

    /**
     * A filter may only name a field the caller could also read. Without this
     * check a filter would be a side channel: `fe_group = 5` narrows a result
     * set by a field the tool does not expose, and a LIKE on a hidden column is
     * a character-by-character oracle over its content.
     *
     * @throws ValidationException
     */
    private function assertFieldIsFilterable(string $table, string $field, string $position, ?int $pid): void
    {
        if (!isset($this->getFilterableFields($table, $pid)[$field])) {
            throw new ValidationException([sprintf(
                '%s.field "%s" is not a readable field of %s. Use GetTableSchema to see the available fields.',
                $position,
                $field,
                $table
            )]);
        }
    }

    /**
     * Every field ReadTable can hand out for some record of the table.
     *
     * ReadTable returns, per record, the fields getAvailableFields() lists for
     * that record's type plus the essential ctrl fields. A filter runs across
     * records of every type at once, so the allow-list is the union of that
     * over all record types: a field shown only for CType=textmedia (assets)
     * is still a field the caller can read on those records. Each per-type
     * list has already passed canAccessField(), so exclude fields the user is
     * not granted and TCEFORM-disabled fields stay out, and a field disabled
     * globally but re-enabled for one type is in, exactly as ReadTable would
     * return it for records of that type.
     *
     * Only real TCA columns are taken from those lists: getAvailableFields()
     * also carries computed fields added by AfterSchemaLoadEvent listeners,
     * which have no database column to filter on. canAccessField() alone
     * cannot serve as the check: it returns true for a name it does not know,
     * so it validates restrictions but never existence.
     *
     * @return array<string, true>
     */
    private function getFilterableFields(string $table, ?int $pid): array
    {
        $cacheKey = $table . ':' . ($pid ?? '');
        if (isset($this->filterableFields[$cacheKey])) {
            return $this->filterableFields[$cacheKey];
        }

        // uid/pid plus the ctrl fields ReadTable puts into every row (type,
        // label, language, tstamp, crdate, hidden, sorting), derived from the
        // table's ctrl section.
        $fields = array_fill_keys(
            array_merge(self::ALWAYS_FILTERABLE, $this->tableAccessService->getEssentialFields($table)),
            true
        );

        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        // '' stands for the default type and covers tables without types.
        $recordTypes = array_unique(array_merge(
            [''],
            array_map('strval', array_keys($GLOBALS['TCA'][$table]['types'] ?? []))
        ));
        foreach ($recordTypes as $recordType) {
            foreach (array_keys($this->tableAccessService->getAvailableFields($table, $recordType, $pid)) as $fieldName) {
                if (isset($columns[$fieldName])) {
                    $fields[$fieldName] = true;
                }
            }
        }

        return $this->filterableFields[$cacheKey] = $fields;
    }

    private function isNumericField(string $table, string $field): bool
    {
        if (in_array($field, self::ALWAYS_FILTERABLE, true)) {
            return true;
        }

        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        foreach (self::NUMERIC_CTRL_KEYS as $ctrlKey) {
            if (($ctrl[$ctrlKey] ?? null) === $field) {
                return true;
            }
        }

        $config = $this->tableAccessService->getFieldConfig($table, $field);

        return in_array($config['config']['type'] ?? '', self::NUMERIC_TCA_TYPES, true);
    }

    /**
     * @param mixed $value
     * @return array<int, int|string>
     * @throws ValidationException
     */
    private function assertList(string $table, string $field, $value, string $operator, string $position): array
    {
        if (!is_array($value) || $value === []) {
            throw new ValidationException([sprintf(
                '%s uses operator "%s", which needs a non-empty array of values.',
                $position,
                $operator
            )]);
        }

        $numeric = $this->isNumericField($table, $field);
        $values = [];
        foreach (array_values($value) as $entryIndex => $entry) {
            $values[] = $numeric
                ? $this->assertInteger($entry, $field, $position . '.value[' . $entryIndex . ']')
                : $this->assertString($entry, $field, $position . '.value[' . $entryIndex . ']');
        }

        return $values;
    }

    /**
     * @param mixed $value
     * @return int|string
     * @throws ValidationException
     */
    private function assertScalar(string $table, string $field, $value, string $position)
    {
        return $this->isNumericField($table, $field)
            ? $this->assertInteger($value, $field, $position . '.value')
            : $this->assertString($value, $field, $position . '.value');
    }

    /**
     * @param mixed $value
     * @throws ValidationException
     */
    private function likePattern(QueryBuilder $queryBuilder, string $field, $value, string $operator, string $position): string
    {
        $text = $this->assertString($value, $field, $position . '.value');
        // Escape first, then wrap: a "%" the caller sent is a literal to match,
        // only the wildcards added here are wildcards.
        $escaped = $queryBuilder->escapeLikeWildcards($text);

        return match ($operator) {
            'contains' => '%' . $escaped . '%',
            'startsWith' => $escaped . '%',
            'endsWith' => '%' . $escaped,
        };
    }

    /**
     * @param mixed $value
     * @throws ValidationException
     */
    private function assertInteger($value, string $field, string $position): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        throw new ValidationException([sprintf(
            '%s must be a number because %s holds numbers, got %s.',
            $position,
            $field,
            get_debug_type($value)
        )]);
    }

    /**
     * @param mixed $value
     * @throws ValidationException
     */
    private function assertString($value, string $field, string $position): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            // A number written into a text field is unambiguous, and TCA text
            // fields routinely hold numeric strings (CType keys, list_type).
            return (string)$value;
        }

        throw new ValidationException([sprintf(
            '%s must be a string because %s holds text, got %s.',
            $position,
            $field,
            get_debug_type($value)
        )]);
    }
}
