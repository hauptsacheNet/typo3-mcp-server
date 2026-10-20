<?php

declare(strict_types=1);

namespace Hn\McpServer\Relation;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Finds the parent records of inline/file child records (tt_content -> news, sys_file_reference -> tt_content, ...)
 * generically from the TCA.
 *
 * Only owning relations count: inline/file columns between two workspace-aware tables, with the relation stored
 * either in a foreign_field of the child or as uid list in the parent column. Inline MM relations are skipped,
 * their children can belong to several parents. select/group columns are references to independent records and
 * are skipped as well; changing them changes the parent record itself anyway.
 *
 * A column can opt out with ['config']['behaviour']['mcp_server']['propagateToParent'] = false.
 */
final class InlineParentResolver
{
    private const MAX_DEPTH = 10;

    /** @var array<string, list<ParentRelation>>|null child table => relations to its parents */
    private ?array $relationsByChildTable = null;

    /** @var array<string, list<ParentRelation>> parent table => relations to its children */
    private array $relationsByParentTable = [];

    /** @var array<string, array<string, mixed>|null> "table:uid" => record (null = not found) */
    private array $recordCache = [];

    /** @var array<string, array<string, mixed>|null> "workspaceId:table:uid" => workspace version (null = none) */
    private array $workspaceRecordCache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * Forgets cached records; call this whenever records may have changed since the last resolve.
     */
    public function reset(): void
    {
        $this->recordCache = [];
        $this->workspaceRecordCache = [];
    }

    public function hasParentRelations(string $table): bool
    {
        return $this->getRelations($table) !== [];
    }

    /**
     * The owning relations $table has to its own children, the other direction of getRelations().
     *
     * @return list<ParentRelation>
     */
    public function getChildRelations(string $parentTable): array
    {
        $this->initializeRelations();

        return $this->relationsByParentTable[$parentTable] ?? [];
    }

    /**
     * @param array<string, mixed> $row the child record (live record or workspace version)
     * @return array{0: string, 1: int}|null parent table and uid (live uid, or the only uid of a parent new in the workspace)
     */
    public function resolveParent(string $table, array $row, int $workspaceId): ?array
    {
        foreach ($this->getRelations($table) as $relation) {
            $parentUid = $relation->type === ParentRelation::TYPE_LIST
                ? $this->findParentUidByList($relation, $row, $workspaceId)
                : $relation->resolveParentUidFromChild($row);
            if ($parentUid !== null) {
                return [$relation->parentTable, $parentUid];
            }
        }

        return null;
    }

    /**
     * Walks up from a child record to its outermost parent.
     *
     * Each level is read in its workspace version if there is one, since a child created in the workspace
     * and the relation fields of a versioned child only exist there.
     *
     * @param array<string, mixed> $row the child record (live record or workspace version)
     * @return list<Ancestor> nearest parent first, outermost parent last
     */
    public function resolveAncestors(string $table, array $row, int $workspaceId): array
    {
        $ancestors = [];
        $startKey = $table . ':' . $this->getLiveUid($row);
        $visited = [$startKey => true];
        $childTable = $table;
        $childRow = $row;

        while (count($ancestors) < self::MAX_DEPTH) {
            $parent = $this->resolveParent($childTable, $childRow, $workspaceId);
            if ($parent === null) {
                break;
            }
            [$parentTable, $parentUid] = $parent;
            $parentKey = $parentTable . ':' . $parentUid;
            if (isset($visited[$parentKey])) {
                break;
            }
            $visited[$parentKey] = true;

            $workspaceRecord = $this->getWorkspaceRecord($workspaceId, $parentTable, $parentUid);
            $parentRow = $workspaceRecord ?? $this->getRecord($parentTable, $parentUid);
            if ($parentRow === null) {
                break;
            }
            $ancestors[] = new Ancestor($parentTable, $parentUid, $workspaceRecord);

            // continue one level up
            $childTable = $parentTable;
            $childRow = $parentRow;
        }

        return $ancestors;
    }

    /**
     * The version of a live record in the given workspace, or the record itself if it is new there.
     *
     * @return array<string, mixed>|null
     */
    public function getWorkspaceRecord(int $workspaceId, string $table, int $uid): ?array
    {
        if ($workspaceId <= 0) {
            return null;
        }
        $key = $workspaceId . ':' . $table . ':' . $uid;
        if (!array_key_exists($key, $this->workspaceRecordCache)) {
            $this->workspaceRecordCache[$key] = BackendUtility::getWorkspaceVersionOfRecord($workspaceId, $table, $uid) ?: null;
        }

        return $this->workspaceRecordCache[$key];
    }

    /**
     * Any record by uid regardless of workspace and enable fields, except deleted ones.
     *
     * @return array<string, mixed>|null
     */
    public function getRecord(string $table, int $uid): ?array
    {
        $key = $table . ':' . $uid;
        if (!array_key_exists($key, $this->recordCache)) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $this->recordCache[$key] = $queryBuilder
                ->select('*')
                ->from($table)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative() ?: null;
        }

        return $this->recordCache[$key];
    }

    /**
     * Finds the parent whose uid list contains the child, live or in the given workspace.
     *
     * Versions keep pointing to the live uids of their children, so the live uid of the child is searched.
     *
     * @param array<string, mixed> $childRow
     */
    private function findParentUidByList(ParentRelation $relation, array $childRow, int $workspaceId): ?int
    {
        $childUid = $this->getLiveUid($childRow);
        if ($childUid <= 0) {
            return null;
        }

        // a parent version (or a parent new) in the workspace listing the child
        $workspaceParent = $this->findRecordListingChild($relation, $childUid, $workspaceId);
        if ($workspaceParent !== null) {
            return (int)$workspaceParent['t3ver_oid'] > 0 ? (int)$workspaceParent['t3ver_oid'] : (int)$workspaceParent['uid'];
        }

        // a live parent listing the child, unless its workspace version no longer lists it
        $liveParent = $this->findRecordListingChild($relation, $childUid, 0);
        if ($liveParent === null) {
            return null;
        }
        $liveParentUid = (int)$liveParent['uid'];
        if ($this->getWorkspaceRecord($workspaceId, $relation->parentTable, $liveParentUid) !== null) {
            return null;
        }

        return $liveParentUid;
    }

    /**
     * @return array{uid: int, t3ver_oid: int}|null
     */
    private function findRecordListingChild(ParentRelation $relation, int $childUid, int $workspaceId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($relation->parentTable);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('uid', 't3ver_oid')
            ->from($relation->parentTable)
            ->where(
                // inSet() builds a LIKE expression on SQLite and rejects placeholders there, so the uid
                // (an int, nothing to escape beyond quoting) goes into the query as a quoted literal
                $queryBuilder->expr()->inSet($relation->parentField, $queryBuilder->quote((string)$childUid)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative() ?: null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function getLiveUid(array $row): int
    {
        return (int)($row['t3ver_oid'] ?? 0) > 0 ? (int)$row['t3ver_oid'] : (int)($row['uid'] ?? 0);
    }

    /**
     * @return list<ParentRelation>
     */
    private function getRelations(string $childTable): array
    {
        $this->initializeRelations();

        return $this->relationsByChildTable[$childTable] ?? [];
    }

    private function initializeRelations(): void
    {
        if ($this->relationsByChildTable !== null) {
            return;
        }

        $this->relationsByChildTable = [];
        $this->relationsByParentTable = [];
        foreach ($GLOBALS['TCA'] ?? [] as $parentTable => $tableConfiguration) {
            if (!$this->isWorkspaceAware($parentTable)) {
                continue;
            }
            foreach ($tableConfiguration['columns'] ?? [] as $field => $column) {
                $config = $column['config'] ?? [];
                $childTable = (string)($config['foreign_table'] ?? '');
                if (!in_array($config['type'] ?? '', ['inline', 'file'], true)
                    || !empty($config['MM'])
                    || $childTable === ''
                    || !$this->isWorkspaceAware($childTable)
                    || ($config['behaviour']['mcp_server']['propagateToParent'] ?? true) === false
                ) {
                    continue;
                }
                $sortByField = $this->getSortByField($config, $childTable);
                if (!empty($config['foreign_field'])) {
                    $relation = new ParentRelation(
                        $parentTable,
                        $field,
                        $childTable,
                        ParentRelation::TYPE_FIELD,
                        (string)$config['foreign_field'],
                        !empty($config['foreign_table_field']) ? (string)$config['foreign_table_field'] : null,
                        array_map('strval', $config['foreign_match_fields'] ?? []),
                        $sortByField,
                    );
                } elseif (($config['type'] ?? '') === 'inline') {
                    $relation = new ParentRelation(
                        $parentTable,
                        $field,
                        $childTable,
                        ParentRelation::TYPE_LIST,
                        sortByField: $sortByField,
                    );
                } else {
                    // type "file" without foreign_field is invalid TCA
                    continue;
                }
                $this->relationsByChildTable[$childTable][] = $relation;
                $this->relationsByParentTable[$parentTable][] = $relation;
            }
        }
    }

    /**
     * The field the children of a relation are sorted by, if any.
     *
     * @param array<string, mixed> $config the column configuration of the parent
     */
    private function getSortByField(array $config, string $childTable): ?string
    {
        $sortBy = (string)($config['foreign_sortby'] ?? $GLOBALS['TCA'][$childTable]['ctrl']['sortby'] ?? '');
        // foreign_sortby may carry a direction ("sorting DESC")
        $sortBy = trim(explode(' ', trim($sortBy))[0]);

        return $sortBy !== '' ? $sortBy : null;
    }

    private function isWorkspaceAware(string $table): bool
    {
        return $this->tcaSchemaFactory->has($table)
            && $this->tcaSchemaFactory->get($table)->hasCapability(TcaSchemaCapability::Workspace);
    }
}
