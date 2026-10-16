<?php

declare(strict_types=1);

namespace Hn\McpServer\EventListener;

use Hn\McpServer\Event\BeforeRecordReadEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Restricts sys_file_metadata reads in two ways:
 *
 * 1. Mount restriction: a non-admin user may only see metadata for files that
 *    are within their accessible file mounts. Implemented as a subquery on
 *    sys_file using the same mount logic as SysFileMountRestrictionListener.
 *
 * 2. Orphan filter: metadata records whose `file` column points at a sys_file
 *    uid that no longer exists are filtered out. Hard-deletion of sys_file
 *    cascades to sys_file_metadata in the normal path, but old/inconsistent
 *    installations can have leftovers — and the IN-subquery on sys_file gets
 *    us this for free for both admin and non-admin users.
 */
final class SysFileMetadataRestrictionListener
{
    public function __invoke(BeforeRecordReadEvent $event): void
    {
        if ($event->getTable() !== 'sys_file_metadata') {
            return;
        }

        $queryBuilder = $event->getQueryBuilder();

        // Build a subquery: SELECT uid FROM sys_file WHERE <mount restriction or none>
        // Parameters are registered on the outer QueryBuilder so the embedded
        // SQL string keeps them bound when the wrapping query executes.
        $subQb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file');
        $subQb->getRestrictions()->removeAll();
        $subQb->select('uid')->from('sys_file');

        $mountRestriction = SysFileMountRestrictionListener::buildSysFileMountRestriction($queryBuilder);
        if ($mountRestriction !== null) {
            $subQb->andWhere($mountRestriction);
        }

        $queryBuilder->andWhere(
            $queryBuilder->expr()->in('file', '(' . $subQb->getSQL() . ')')
        );
    }
}
