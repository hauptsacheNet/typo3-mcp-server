<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Relation\Ancestor;
use Hn\McpServer\Relation\InlineParentResolver;
use Hn\McpServer\Relation\ParentRelation;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\ReferenceIndexUpdater;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates workspace versions of the parent records of changed inline children.
 *
 * TYPO3 only versions the record that changes: a changed content element of a news record or a new image
 * reference of a content element leaves the parent live-only. Workspace module and kanban board then list the
 * children without context, and publishing/stage changes do not group them with the parent.
 *
 * For each changed child this versions the outermost ancestor that has no workspace version yet. The core
 * versions all inline children of a record along with it, so every level below gets a version too, and
 * publishing, stage changes and discarding then always handle the whole family (workspaces CommandMap).
 */
final class ParentVersioningService
{
    /** how many levels of children are protected from the core's sorting rewrite */
    private const MAX_FAMILY_DEPTH = 10;

    public function __construct(
        private readonly InlineParentResolver $parentResolver,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<array{0: string, 1: int}> $records table and uid (live or workspace) of changed records
     * @param array<int, string> $errorLog receives the errors of the versioning DataHandler
     * @return list<array{ancestor: Ancestor, child: array{0: string, 1: int}, versionUid: int|null}> versioned
     *         parents; versionUid is null when versioning failed
     */
    public function propagate(array $records, BackendUserAuthentication $backendUser, array &$errorLog = []): array
    {
        $workspaceId = (int)$backendUser->workspace;
        if ($workspaceId <= 0) {
            return [];
        }

        $this->parentResolver->reset();

        $targets = [];
        $referenceUpdates = [];
        foreach ($records as [$table, $uid]) {
            if (!$this->parentResolver->hasParentRelations($table)) {
                continue;
            }
            $row = $this->parentResolver->getRecord($table, $uid);
            if ($row !== null && (int)($row['t3ver_wsid'] ?? 0) !== $workspaceId) {
                $row = $this->parentResolver->getWorkspaceRecord($workspaceId, $table, $uid);
            }
            if ($row === null) {
                // the child itself has no change in this workspace (e.g. the DataHandler denied the change)
                continue;
            }

            $ancestors = $this->parentResolver->resolveAncestors($table, $row, $workspaceId);
            $target = null;
            foreach (array_reverse($ancestors) as $ancestor) {
                if (!$ancestor->isInWorkspace()) {
                    $target = $ancestor;
                    break;
                }
                // ancestors above the versioned one get new relations in the workspace
                $referenceUpdates[] = [$ancestor->table, (int)$ancestor->workspaceRecord['uid']];
            }
            if ($target === null) {
                continue;
            }

            $targetKey = $target->table . ':' . $target->uid;
            $targets[$targetKey] ??= [
                'ancestor' => $target,
                'child' => [$table, (int)$row['uid']],
                'stage' => (int)($row['t3ver_stage'] ?? 0),
            ];
        }

        // A sub DataHandler is not the outermost instance and never flushes its reference index updates itself,
        // so it gets an updater of its own. Without up-to-date sys_refindex rows the workspaces CommandMap
        // would not find the children when the parent is published.
        $referenceIndexUpdater = GeneralUtility::makeInstance(ReferenceIndexUpdater::class);

        // Ancestors that already have a version also need their reference index updated when nothing gets
        // versioned: a child created standalone (not through the parent's form) is not in the index of its
        // parent's version otherwise.
        $liveChildSortings = $targets === [] ? [] : $this->captureLiveChildSortings($targets);
        $result = $targets === [] ? [] : $this->versionTargets($targets, $workspaceId, $backendUser, $referenceIndexUpdater, $errorLog);
        $this->restoreLiveChildSortings($liveChildSortings);

        foreach ($referenceUpdates as [$table, $uid]) {
            $referenceIndexUpdater->registerForUpdate($table, $uid, $workspaceId);
        }
        $referenceIndexUpdater->update();

        return $result;
    }

    /**
     * @param array<string, array{ancestor: Ancestor, child: array{0: string, 1: int}, stage: int}> $targets
     * @param array<int, string> $errorLog
     * @return list<array{ancestor: Ancestor, child: array{0: string, 1: int}, versionUid: int|null}>
     */
    private function versionTargets(
        array $targets,
        int $workspaceId,
        BackendUserAuthentication $backendUser,
        ReferenceIndexUpdater $referenceIndexUpdater,
        array &$errorLog,
    ): array {
        $commandMap = [];
        foreach ($targets as $target) {
            $commandMap[$target['ancestor']->table][$target['ancestor']->uid]['version'] = [
                'action' => 'new',
                'label' => 'Auto-created for changed child records in WS #' . $workspaceId,
            ];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $commandMap, $backendUser, $referenceIndexUpdater);
        $dataHandler->process_cmdmap();
        $errorLog = array_merge($errorLog, $dataHandler->errorLog);

        $result = [];
        foreach ($targets as $target) {
            $ancestor = $target['ancestor'];
            $versionUid = isset($dataHandler->copyMappingArray[$ancestor->table][$ancestor->uid])
                ? (int)$dataHandler->copyMappingArray[$ancestor->table][$ancestor->uid]
                : null;
            if ($versionUid !== null && $target['stage'] !== 0) {
                // keep the family in one stage: a new version starts in "editing", the child may be further
                $this->setStage($ancestor->table, $versionUid, $target['stage']);
            }
            $result[] = ['ancestor' => $ancestor, 'child' => $target['child'], 'versionUid' => $versionUid];
        }

        return $result;
    }

    /**
     * Reads the sorting of the live children of the records about to be versioned.
     *
     * Versioning a parent makes the core rewrite its inline relation (process_cmdmap() -> processRemapStack()
     * -> checkValue_inline_processDBdata() -> RelationHandler::writeForeignField()), which renumbers the
     * sorting of the LIVE children from 1. On its own that would only be cosmetic, since the order survives,
     * but it leaves two scales in the same column: a child version pulled before the renumbering keeps the old
     * values, while move pointers created afterwards are calculated against the new ones. A reorder staged in
     * the workspace then publishes in the wrong order, even though the Workspaces module and ReadTable still
     * show the staged one (they read the live row of a child that only has a plain version).
     *
     * Walks down all owning relations, since versioning a parent versions the whole family below it.
     *
     * @param array<string, array{ancestor: Ancestor, child: array{0: string, 1: int}, stage: int}> $targets
     * @return array<string, array<string, array<int, int>>> table => sorting field => live uid => sorting
     */
    private function captureLiveChildSortings(array $targets): array
    {
        $sortings = [];
        $visited = [];
        $level = [];
        foreach ($targets as $target) {
            $level[] = [$target['ancestor']->table, $target['ancestor']->uid];
        }

        for ($depth = 0; $depth < self::MAX_FAMILY_DEPTH && $level !== []; $depth++) {
            $nextLevel = [];
            foreach ($level as [$parentTable, $parentUid]) {
                foreach ($this->parentResolver->getChildRelations($parentTable) as $relation) {
                    if ($relation->sortByField === null) {
                        continue;
                    }
                    foreach ($this->findLiveChildren($relation, $parentUid) as $child) {
                        $childUid = (int)$child['uid'];
                        $key = $relation->childTable . ':' . $childUid;
                        if (isset($visited[$key])) {
                            continue;
                        }
                        $visited[$key] = true;
                        $sortings[$relation->childTable][$relation->sortByField][$childUid] = (int)$child['sorting'];
                        $nextLevel[] = [$relation->childTable, $childUid];
                    }
                }
            }
            $level = $nextLevel;
        }

        return $sortings;
    }

    /**
     * @return list<array{uid: int, sorting: int}> the live children of $parentUid with their sorting
     */
    private function findLiveChildren(ParentRelation $relation, int $parentUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($relation->childTable);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $queryBuilder
            ->select('uid', $relation->sortByField . ' AS sorting')
            ->from($relation->childTable)
            ->where($queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));

        if ($relation->type === ParentRelation::TYPE_LIST) {
            $parentRow = $this->parentResolver->getRecord($relation->parentTable, $parentUid);
            $childUids = GeneralUtility::intExplode(',', (string)($parentRow[$relation->parentField] ?? ''), true);
            if ($childUids === []) {
                return [];
            }
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($childUids, Connection::PARAM_INT_ARRAY))
            );
        } else {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq((string)$relation->foreignField, $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT))
            );
            if ($relation->foreignTableField !== null) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($relation->foreignTableField, $queryBuilder->createNamedParameter($relation->parentTable))
                );
            }
            foreach ($relation->matchFields as $field => $value) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)));
            }
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Writes the captured sorting back wherever the versioning renumbered a live child.
     *
     * @param array<string, array<string, array<int, int>>> $liveChildSortings table => field => uid => sorting
     */
    private function restoreLiveChildSortings(array $liveChildSortings): void
    {
        foreach ($liveChildSortings as $table => $sortingsByField) {
            foreach ($sortingsByField as $field => $sortingByUid) {
                $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
                $queryBuilder->getRestrictions()->removeAll();
                $currentRows = $queryBuilder
                    ->select('uid', $field . ' AS sorting')
                    ->from($table)
                    ->where($queryBuilder->expr()->in(
                        'uid',
                        $queryBuilder->createNamedParameter(array_keys($sortingByUid), Connection::PARAM_INT_ARRAY)
                    ))
                    ->executeQuery()
                    ->fetchAllAssociative();

                $connection = $this->connectionPool->getConnectionForTable($table);
                foreach ($currentRows as $currentRow) {
                    $uid = (int)$currentRow['uid'];
                    $captured = $sortingByUid[$uid] ?? null;
                    if ($captured === null || $captured === (int)$currentRow['sorting']) {
                        continue;
                    }
                    $connection->update($table, [$field => $captured], ['uid' => $uid], [$field => Connection::PARAM_INT]);
                }
            }
        }
    }

    private function setStage(string $table, int $versionUid, int $stage): void
    {
        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            ['t3ver_stage' => $stage],
            ['uid' => $versionUid],
            ['t3ver_stage' => Connection::PARAM_INT]
        );
    }
}
