<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stages the inline parents of a written record in the current workspace.
 *
 * Editing an inline child in the TYPO3 backend means saving the parent's form, so
 * DataHandler versions the parent along with the child and the workspace module lists
 * the parent as modified (e.g. a news record when one of its content elements changed).
 * WriteTable writes children on their own instead: independent children (tt_content in
 * a news record) are separate records for the MCP client, and embedded children only
 * send what changed. This service closes the gap by versioning every record up the
 * inline chain that has no version in the workspace yet.
 *
 * See Documentation/Architecture/InlineRelations.md.
 */
class InlineParentService
{
    /**
     * Inline relations with a foreign_field, built from TCA on first use.
     *
     * @var list<array{parentTable: string, childTable: string, foreignField: string, foreignTableField: ?string, foreignMatchFields: array<string, mixed>}>|null
     */
    protected ?array $relations = null;

    /**
     * The inline parents a record currently belongs to, as live uids.
     *
     * Collect these before a write that can detach the record from its parent
     * (a delete, or a change of the foreign field) and hand them to markModified().
     *
     * @return list<array{table: string, uid: int}>
     */
    public function findParents(string $table, int $uid): array
    {
        if ($this->getWorkspaceId() === 0) {
            return [];
        }

        $relations = array_filter($this->getRelations(), static fn(array $relation): bool => $relation['childTable'] === $table);
        if ($relations === []) {
            return [];
        }

        $row = BackendUtility::getRecord($table, $uid);
        if (is_array($row)) {
            // The workspace version decides, its foreign field may differ from live.
            BackendUtility::workspaceOL($table, $row);
        }
        if (!is_array($row)) {
            return [];
        }

        $parents = [];
        foreach ($relations as $relation) {
            $parentUid = (int)($row[$relation['foreignField']] ?? 0);
            if ($parentUid <= 0) {
                continue;
            }
            if ($relation['foreignTableField'] !== null) {
                // A child table shared by several parent tables records which one it belongs to
                // (sys_file_reference.tablenames). Together with the foreign field that names the
                // parent, whatever field it hangs in: fieldname may be a FlexForm field that is
                // no TCA column of the parent and so matches none of its relations.
                if ((string)($row[$relation['foreignTableField']] ?? '') !== $relation['parentTable']) {
                    continue;
                }
                $parents[$relation['parentTable'] . ':' . $parentUid] = ['table' => $relation['parentTable'], 'uid' => $parentUid];
                continue;
            }
            foreach ($relation['foreignMatchFields'] as $matchField => $matchValue) {
                if ((string)($row[$matchField] ?? '') !== (string)$matchValue) {
                    continue 2;
                }
            }
            $parents[$relation['parentTable'] . ':' . $parentUid] = ['table' => $relation['parentTable'], 'uid' => $parentUid];
        }

        return array_values($parents);
    }

    /**
     * Mark a written record and all its inline ancestors as modified in the workspace.
     *
     * Records that already have a version in the workspace (or were created in it) are
     * left alone, so calling this after every write is cheap and idempotent.
     *
     * @param list<array{table: string, uid: int}> $previousParents parents the record
     *        belonged to before the write, which changed as well
     */
    public function markModified(string $table, int $uid, array $previousParents = []): void
    {
        if ($this->getWorkspaceId() === 0) {
            return;
        }

        $queue = [['table' => $table, 'uid' => $uid], ...$previousParents];
        $visited = [];
        while ($queue !== []) {
            $record = array_shift($queue);
            $key = $record['table'] . ':' . $record['uid'];
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;

            $this->ensureWorkspaceVersion($record['table'], $record['uid']);
            array_push($queue, ...$this->findParents($record['table'], $record['uid']));
        }
    }

    /**
     * Create a workspace version of a live record, the same way DataHandler does when
     * the backend saves a form for a record that is not versioned yet.
     */
    protected function ensureWorkspaceVersion(string $table, int $uid): void
    {
        $workspaceId = $this->getWorkspaceId();
        $backendUser = $GLOBALS['BE_USER'];

        $schemaFactory = GeneralUtility::makeInstance(TcaSchemaFactory::class);
        $isWorkspaceAware = $schemaFactory->has($table)
            && $schemaFactory->get($table)->hasCapability(TcaSchemaCapability::Workspace);
        if (!$isWorkspaceAware || !$backendUser->check('tables_modify', $table)) {
            return;
        }

        $row = BackendUtility::getRecord($table, $uid, 'uid,t3ver_wsid');
        // Only live records get versioned: a record created in a workspace is listed there already.
        if (!is_array($row) || (int)$row['t3ver_wsid'] !== 0) {
            return;
        }
        // Any version counts, a delete placeholder or move pointer must not be replaced.
        if (BackendUtility::getWorkspaceVersionOfRecord($workspaceId, $table, $uid, 'uid')) {
            return;
        }

        // Versioning the parent also versions its inline children, and while doing so
        // RelationHandler::writeForeignField() renumbers the sorting (and bumps tstamp) of
        // the children it was handed: the live rows, in workspace order. Restore them so
        // that only new version rows remain, live must not change before publishing.
        $descendants = $this->fetchDescendantRows($table, $uid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;
        $dataHandler->start([], [
            $table => [
                $uid => [
                    'version' => [
                        'action' => 'new',
                        'label' => 'Auto-created for WS #' . $workspaceId,
                    ],
                ],
            ],
        ]);
        // Best effort: if the user may not edit the parent (e.g. page permissions), the
        // child write itself has succeeded and stays valid, DataHandler logs the refusal.
        $dataHandler->process_cmdmap();

        $this->restoreRows($descendants);
    }

    /**
     * All rows reachable from a record through inline relations that versioning it can
     * write to: live rows and rows created in the current workspace (versions of live rows
     * are copied, not written). Recursive, children of children are versioned as well.
     *
     * @return array<string, array{table: string, row: array<string, mixed>}>
     */
    protected function fetchDescendantRows(string $table, int $uid): array
    {
        $workspaceId = $this->getWorkspaceId();
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

        $rows = [];
        $queue = [['table' => $table, 'uid' => $uid]];
        $visited = [];
        while ($queue !== []) {
            $parent = array_shift($queue);
            $parentKey = $parent['table'] . ':' . $parent['uid'];
            if (isset($visited[$parentKey])) {
                continue;
            }
            $visited[$parentKey] = true;

            // foreign_match_fields are left out: they would miss FlexForm file references
            // (fieldname is no TCA column), and fetching a row too many costs nothing.
            $queried = [];
            foreach ($this->getRelations() as $relation) {
                $childTable = $relation['childTable'];
                $queryKey = $childTable . ':' . $relation['foreignField'] . ':' . $relation['foreignTableField'];
                if ($relation['parentTable'] !== $parent['table'] || isset($queried[$queryKey])) {
                    continue;
                }
                $queried[$queryKey] = true;
                $queryBuilder = $connectionPool->getQueryBuilderForTable($childTable);
                $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                $queryBuilder
                    ->select('*')
                    ->from($childTable)
                    ->where(
                        $queryBuilder->expr()->eq($relation['foreignField'], $queryBuilder->createNamedParameter($parent['uid'], ParameterType::INTEGER)),
                        $queryBuilder->expr()->in('t3ver_wsid', $queryBuilder->createNamedParameter([0, $workspaceId], ArrayParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
                    );
                if ($relation['foreignTableField'] !== null) {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->eq($relation['foreignTableField'], $queryBuilder->createNamedParameter($parent['table']))
                    );
                }

                foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
                    $rows[$childTable . ':' . $row['uid']] = ['table' => $childTable, 'row' => $row];
                    $queue[] = ['table' => $childTable, 'uid' => (int)$row['uid']];
                }
            }
        }

        return $rows;
    }

    /**
     * Write back every column that changed since the rows were fetched.
     *
     * @param array<string, array{table: string, row: array<string, mixed>}> $rows
     */
    protected function restoreRows(array $rows): void
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ($rows as ['table' => $table, 'row' => $before]) {
            $connection = $connectionPool->getConnectionForTable($table);
            $after = $connection->select(['*'], $table, ['uid' => (int)$before['uid']])->fetchAssociative();
            if (!is_array($after)) {
                continue;
            }
            $changed = [];
            foreach ($before as $field => $value) {
                if (array_key_exists($field, $after) && $after[$field] !== $value) {
                    $changed[$field] = $value;
                }
            }
            if ($changed !== []) {
                $connection->update($table, $changed, ['uid' => (int)$before['uid']]);
            }
        }
    }

    /**
     * @return list<array{parentTable: string, childTable: string, foreignField: string, foreignTableField: ?string, foreignMatchFields: array<string, mixed>}>
     */
    protected function getRelations(): array
    {
        if ($this->relations !== null) {
            return $this->relations;
        }

        $schemaFactory = GeneralUtility::makeInstance(TcaSchemaFactory::class);
        $this->relations = [];
        foreach ($GLOBALS['TCA'] ?? [] as $parentTable => $tableConfig) {
            foreach ($tableConfig['columns'] ?? [] as $column) {
                $config = $column['config'] ?? [];
                if (!in_array($config['type'] ?? '', ['inline', 'file'], true)) {
                    continue;
                }
                // Relations stored as a uid list on the parent (no foreign_field) can't be
                // resolved from the child.
                $childTable = (string)($config['foreign_table'] ?? '');
                $foreignField = (string)($config['foreign_field'] ?? '');
                if ($childTable === '' || $foreignField === '') {
                    continue;
                }
                // Only workspace-aware children carry the t3ver_* columns queried above
                // (and only they get versioned along with the parent).
                if (!$schemaFactory->has($childTable) || !$schemaFactory->get($childTable)->hasCapability(TcaSchemaCapability::Workspace)) {
                    continue;
                }
                $this->relations[] = [
                    'parentTable' => (string)$parentTable,
                    'childTable' => $childTable,
                    'foreignField' => $foreignField,
                    'foreignTableField' => !empty($config['foreign_table_field']) ? (string)$config['foreign_table_field'] : null,
                    'foreignMatchFields' => is_array($config['foreign_match_fields'] ?? null) ? $config['foreign_match_fields'] : [],
                ];
            }
        }

        return $this->relations;
    }

    protected function getWorkspaceId(): int
    {
        return (int)($GLOBALS['BE_USER']->workspace ?? 0);
    }
}
