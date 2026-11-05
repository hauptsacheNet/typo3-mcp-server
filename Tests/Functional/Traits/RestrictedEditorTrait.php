<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Traits;

use Hn\McpServer\Service\BackendUserContextService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A non-admin editor whose permissions come from a real be_groups record
 * (Fixtures/restricted_editor.csv), not from patched groupData:
 *
 * - DB mount on page 20 only (page 21 is a sibling outside the mount)
 * - tables_select pages,tt_content; tables_modify tt_content only
 * - explicit_allowdeny for CType "html" only
 * - non_exclude_fields without frame_class and layout
 * - no live editing (workspace_perms 0), member of workspace 10
 *
 * The user is set up through BackendUserContextService::impersonate(), the
 * same path the /mcp endpoint uses for OAuth-authenticated requests.
 */
trait RestrictedEditorTrait
{
    protected const RESTRICTED_EDITOR_UID = 5;
    protected const EDITOR_WITHOUT_WORKSPACE_UID = 6;
    protected const EDITORIAL_WORKSPACE_UID = 10;
    protected const MOUNTED_PAGE_UID = 20;
    protected const UNMOUNTED_PAGE_UID = 21;
    protected const HTML_ELEMENT_UID = 200;
    protected const TEXT_ELEMENT_UID = 201;
    protected const UNMOUNTED_HTML_ELEMENT_UID = 202;

    protected function importRestrictedEditorFixture(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/restricted_editor.csv');
    }

    protected function actAsBackendUser(int $uid): BackendUserAuthentication
    {
        return GeneralUtility::makeInstance(BackendUserContextService::class)->impersonate($uid);
    }

    /**
     * Raw row without any overlay, deleted rows included.
     */
    protected function fetchRawRecord(string $table, int $uid): ?array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Raw rows matching all given field values (deleted rows excluded).
     *
     * @return array<int, array>
     */
    protected function fetchRawRecords(string $table, array $criteria): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder->select('*')->from($table);
        foreach ($criteria as $field => $value) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq(
                $field,
                $queryBuilder->createNamedParameter($value, is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR)
            ));
        }
        return $queryBuilder->orderBy('uid')->executeQuery()->fetchAllAssociative();
    }

    /**
     * The workspace version of a live record, or null if none exists.
     */
    protected function fetchWorkspaceVersion(string $table, int $liveUid, int $workspaceId): ?array
    {
        $rows = $this->fetchRawRecords($table, ['t3ver_oid' => $liveUid, 't3ver_wsid' => $workspaceId]);
        return $rows[0] ?? null;
    }

    /**
     * Every row of the table that lives in the live workspace.
     *
     * @return array<int, array>
     */
    protected function fetchLiveRecords(string $table): array
    {
        return $this->fetchRawRecords($table, ['t3ver_wsid' => 0]);
    }

    protected function decodeToolResult(\Mcp\Types\CallToolResult $result): array
    {
        $decoded = json_decode($result->content[0]->text, true);
        $this->assertIsArray($decoded, $result->content[0]->text);
        return $decoded;
    }
}
