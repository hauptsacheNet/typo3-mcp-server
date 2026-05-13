<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolve UIDs between live records and their workspace counterparts.
 *
 * Live UIDs are the only identifiers exposed through MCP — workspace UIDs
 * never cross the tool boundary. This service keeps the conversion logic in
 * one place so multiple tools can share it without depending on each other.
 */
class WorkspaceVersionService
{
    /**
     * Get the live UID for a workspace record.
     *
     * - For a workspace overlay of an existing live record, returns t3ver_oid.
     * - For a brand-new workspace record (t3ver_state = 1) the workspace UID
     *   IS the identifier used externally until publishing.
     */
    public function getLiveUid(string $table, int $workspaceUid): int
    {
        $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
        if ($currentWorkspace === 0) {
            return $workspaceUid;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $record = $queryBuilder
            ->select('t3ver_oid', 't3ver_state', 't3ver_wsid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($workspaceUid, ParameterType::INTEGER)
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$record) {
            return $workspaceUid;
        }

        if ((int)$record['t3ver_oid'] > 0) {
            return (int)$record['t3ver_oid'];
        }

        return $workspaceUid;
    }

    /**
     * Resolve a live UID to its workspace version, if any.
     * Returns the live UID unchanged when no workspace overlay exists.
     */
    public function resolveToWorkspaceUid(string $table, int $liveUid): int
    {
        $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
        if ($currentWorkspace === 0) {
            return $liveUid;
        }

        $record = BackendUtility::getRecord($table, $liveUid);
        if (!$record) {
            return $liveUid;
        }

        BackendUtility::workspaceOL($table, $record);

        // After workspaceOL, _ORIG_uid holds the workspace alt's uid while
        // $record['uid'] gets swapped to the live uid. See
        // BackendUtility::workspaceOL():
        //   $wsAlt['_ORIG_uid'] = $wsAlt['uid'];   // workspace uid
        //   $wsAlt['uid']       = $row['uid'];     // live uid
        if (isset($record['_ORIG_uid']) && (int)$record['_ORIG_uid'] !== $liveUid) {
            return (int)$record['_ORIG_uid'];
        }

        return $liveUid;
    }
}
