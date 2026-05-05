<?php

declare(strict_types=1);

namespace Hn\McpServer\Event;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * PSR-14 event dispatched before a tool executes a record-loading query.
 *
 * Listeners receive a mutable QueryBuilder and can attach additional WHERE
 * conditions to restrict what records the user is allowed to see. The event
 * is dispatched for top-level SELECTs, inline-relation child lookups, and
 * SearchTool's per-table search queries — so any restriction applied here
 * also filters embedded relations and search results.
 *
 * Typical uses:
 * - Filter sys_file rows to the user's file mounts
 * - Filter a tenant-scoped table to the user's tenant id
 * - Hide records based on workflow state
 *
 * Listeners that need different behavior per call site can branch on
 * getSource(); most restrictions are tool-agnostic and ignore it.
 *
 * Not dispatched on internal integrity queries from WriteTableTool (duplicate
 * checks, child lookups during save), since those must see all rows to
 * prevent corrupt writes.
 */
final class BeforeRecordReadEvent
{
    public const SOURCE_READ = 'read';
    public const SOURCE_READ_INLINE = 'read-inline';
    public const SOURCE_SEARCH = 'search';
    public const SOURCE_SEARCH_PARENT = 'search-parent';

    /**
     * @param string $table The table being queried
     * @param QueryBuilder $queryBuilder Mutable query builder — listeners may call andWhere()
     * @param string $queryType 'select' for the result query, 'count' for the total-count query
     * @param string $source The originating call site, e.g. self::SOURCE_READ, self::SOURCE_SEARCH
     */
    public function __construct(
        private readonly string $table,
        private readonly QueryBuilder $queryBuilder,
        private readonly string $queryType,
        private readonly string $source = self::SOURCE_READ,
    ) {}

    public function getTable(): string
    {
        return $this->table;
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    public function getQueryType(): string
    {
        return $this->queryType;
    }

    public function getSource(): string
    {
        return $this->source;
    }
}
