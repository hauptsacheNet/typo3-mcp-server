<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Which pages the current backend user may see: the pages inside the user's
 * DB mounts that grant "show" permission, like the page tree of the TYPO3
 * backend. Admins see everything.
 *
 * Used by the read tools so a non-admin user cannot read records, pages or
 * search results from outside the DB mounts.
 */
class PageAccessService implements SingletonInterface
{
    /**
     * Safety net for corrupt page trees with pid cycles.
     */
    private const MAX_DEPTH = 99;

    /**
     * @var array<string, array{pages: array<int, true>, roots: int[]}>
     */
    private array $cache = [];

    /**
     * Forget resolved page sets. Called at the start of every tool call, so
     * pages created or permissions changed by an earlier call are picked up.
     */
    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Whether the current user is restricted to the DB mounts at all.
     */
    public function isRestricted(): bool
    {
        $backendUser = $this->getBackendUser();
        return !($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin());
    }

    /**
     * Default-language uids of all pages the user may see, or null when the
     * user is not restricted.
     *
     * @return int[]|null
     */
    public function getReadablePageIds(): ?array
    {
        if (!$this->isRestricted()) {
            return null;
        }
        return array_keys($this->resolve()['pages']);
    }

    /**
     * The top-most readable DB mounts, in the order they are configured. They
     * replace the root level (pid 0) when a restricted user browses the tree.
     *
     * @return int[]
     */
    public function getReadableMountRoots(): array
    {
        if (!$this->isRestricted()) {
            return [0];
        }
        return $this->resolve()['roots'];
    }

    /**
     * Whether the user may see the given page. Accepts default-language uids;
     * translated page records and workspace versions are mapped to their
     * default-language live page first.
     */
    public function isPageReadable(int $pageUid): bool
    {
        if (!$this->isRestricted()) {
            return true;
        }
        if ($pageUid <= 0) {
            return false;
        }
        $pages = $this->resolve()['pages'];
        if (isset($pages[$pageUid])) {
            return true;
        }
        $defaultLanguageUid = $this->getDefaultLanguagePageUid($pageUid);
        return $defaultLanguageUid !== null && isset($pages[$defaultLanguageUid]);
    }

    /**
     * WHERE expression limiting a query on $table to records the user may see,
     * or null when no restriction applies (admin, or a table whose records all
     * live on the root level and are governed by table permissions only).
     */
    public function buildRecordRestriction(QueryBuilder $queryBuilder, string $table): ?string
    {
        if (!$this->isRestricted() || !isset($GLOBALS['TCA'][$table])) {
            return null;
        }

        $pageIds = $this->getReadablePageIds();
        $pageList = $pageIds === [] ? '-1' : $queryBuilder->quoteArrayBasedValueListToIntegerList($pageIds);

        if ($table === 'pages') {
            $conditions = [$queryBuilder->expr()->in('uid', $pageList)];
            $transOrigPointerField = $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'] ?? '';
            if ($transOrigPointerField !== '') {
                $conditions[] = $queryBuilder->expr()->in($transOrigPointerField, $pageList);
            }
            if (!empty($GLOBALS['TCA']['pages']['ctrl']['versioningWS'])) {
                // Move pointers are selected directly and still carry their own uid
                $conditions[] = $queryBuilder->expr()->in('t3ver_oid', $pageList);
            }
            return (string)$queryBuilder->expr()->or(...$conditions);
        }

        $rootLevel = (int)($GLOBALS['TCA'][$table]['ctrl']['rootLevel'] ?? 0);
        if ($rootLevel === 1) {
            return null;
        }
        $onPages = $queryBuilder->expr()->in($table . '.pid', $pageList);
        if ($rootLevel === -1) {
            return (string)$queryBuilder->expr()->or(
                $onPages,
                $queryBuilder->expr()->eq($table . '.pid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            );
        }
        return (string)$onPages;
    }

    /**
     * @return array{pages: array<int, true>, roots: int[]}
     */
    private function resolve(): array
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication || !is_array($backendUser->user)) {
            return ['pages' => [], 'roots' => []];
        }

        $mounts = GeneralUtility::intExplode(',', (string)($backendUser->groupData['webmounts'] ?? ''), true);
        $permsClause = QueryHelper::stripLogicalOperatorPrefix($backendUser->getPagePermsClause(Permission::PAGE_SHOW));
        $workspace = (int)$backendUser->workspace;
        $cacheKey = implode('|', [(int)$backendUser->user['uid'], $workspace, implode(',', $mounts), md5($permsClause)]);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $mounts = array_values(array_unique(array_filter($mounts, static fn(int $uid): bool => $uid > 0)));
        $pages = [];
        $reachedAsChild = [];
        $readableRoots = $this->fetchReadablePageUids('uid', $mounts, $permsClause, $workspace);
        foreach ($readableRoots as $uid) {
            $pages[$uid] = true;
        }

        $parents = $readableRoots;
        for ($depth = 0; $parents !== [] && $depth < self::MAX_DEPTH; $depth++) {
            $children = [];
            foreach ($this->fetchReadablePageUids('pid', $parents, $permsClause, $workspace) as $uid) {
                $reachedAsChild[$uid] = true;
                if (!isset($pages[$uid])) {
                    $pages[$uid] = true;
                    $children[] = $uid;
                }
            }
            $parents = $children;
        }

        $roots = array_values(array_filter(
            $mounts,
            static fn(int $uid): bool => isset($pages[$uid]) && !isset($reachedAsChild[$uid])
        ));

        return $this->cache[$cacheKey] = ['pages' => $pages, 'roots' => $roots];
    }

    /**
     * Default-language pages whose $field is in $values and that grant "show".
     *
     * @param int[] $values
     * @return int[]
     */
    private function fetchReadablePageUids(string $field, array $values, string $permsClause, int $workspace): array
    {
        if ($values === []) {
            return [];
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspace));
        $queryBuilder->select('uid', 't3ver_oid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in($field, $queryBuilder->quoteArrayBasedValueListToIntegerList($values)),
                $queryBuilder->expr()->eq(
                    $GLOBALS['TCA']['pages']['ctrl']['languageField'] ?? 'sys_language_uid',
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
                )
            );
        if ($permsClause !== '') {
            $queryBuilder->andWhere($permsClause);
        }

        $uids = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            // A move pointer stands for its live page
            $uids[] = (int)($row['t3ver_oid'] ?? 0) > 0 ? (int)$row['t3ver_oid'] : (int)$row['uid'];
        }
        return $uids;
    }

    private function getDefaultLanguagePageUid(int $pageUid): ?int
    {
        $transOrigPointerField = $GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'] ?? 'l10n_parent';
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $row = $queryBuilder->select('uid', 't3ver_oid', $transOrigPointerField)
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }
        if ((int)($row['t3ver_oid'] ?? 0) > 0) {
            return $this->getDefaultLanguagePageUid((int)$row['t3ver_oid']);
        }
        if ((int)($row[$transOrigPointerField] ?? 0) > 0) {
            return (int)$row[$transOrigPointerField];
        }
        return null;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
