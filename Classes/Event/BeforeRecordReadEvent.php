<?php

declare(strict_types=1);

namespace Hn\McpServer\Event;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * PSR-14 event dispatched before ReadTableTool executes a database query.
 *
 * Listeners receive a mutable QueryBuilder and can attach additional WHERE
 * conditions to restrict what records the user is allowed to see. The event
 * is dispatched for each top-level SELECT and for inline-relation child
 * lookups — so any restriction applied here also filters embedded relations.
 *
 * Typical uses:
 * - Filter sys_file rows to the user's file mounts
 * - Filter a tenant-scoped table to the user's tenant id
 * - Hide records based on workflow state
 *
 * Not dispatched on internal integrity queries from WriteTableTool (duplicate
 * checks, child lookups during save), since those must see all rows to
 * prevent corrupt writes.
 */
final class BeforeRecordReadEvent
{
    /**
     * @param string $table The table being queried
     * @param QueryBuilder $queryBuilder Mutable query builder — listeners may call andWhere()
     * @param string $queryType 'select' for the result query, 'count' for the total-count query
     */
    public function __construct(
        private readonly string $table,
        private readonly QueryBuilder $queryBuilder,
        private readonly string $queryType,
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
}
