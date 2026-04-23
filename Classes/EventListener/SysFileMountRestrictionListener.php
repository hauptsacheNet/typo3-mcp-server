<?php

declare(strict_types=1);

namespace Hn\McpServer\EventListener;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Restricts sys_file reads to the file mounts the current backend user can
 * actually access. Non-admin users with no file mounts see no files at all.
 */
final class SysFileMountRestrictionListener
{
    public function __invoke(BeforeRecordReadEvent $event): void
    {
        if ($event->getTable() !== 'sys_file') {
            return;
        }

        $queryBuilder = $event->getQueryBuilder();
        $restriction = $this->buildFileMountRestriction($queryBuilder);
        if ($restriction !== null) {
            $queryBuilder->andWhere($restriction);
        }
    }

    /**
     * Build a WHERE expression restricting a sys_file query to files within
     * the current user's accessible file mounts. Returns null when no
     * restriction applies (admin user). Returns an always-false expression
     * when the user has no mounts at all.
     */
    private function buildFileMountRestriction(QueryBuilder $queryBuilder): ?CompositeExpression
    {
        $tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
        $isAdmin = false;
        $mounts = $tableAccessService->getAccessibleFileMounts($isAdmin);
        if ($isAdmin) {
            return null;
        }
        if (empty($mounts)) {
            return $queryBuilder->expr()->and('1 = 0');
        }

        $perStorage = [];
        foreach ($mounts as $mount) {
            $perStorage[$mount['storage']][] = $mount['path'];
        }

        $storageExpressions = [];
        foreach ($perStorage as $storageUid => $paths) {
            $pathExpressions = [];
            foreach ($paths as $path) {
                $normalized = rtrim($path, '/') . '/';
                $pathExpressions[] = $queryBuilder->expr()->like(
                    'identifier',
                    $queryBuilder->createNamedParameter(
                        $queryBuilder->escapeLikeWildcards($normalized) . '%'
                    )
                );
            }

            $storageExpressions[] = $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq(
                    'storage',
                    $queryBuilder->createNamedParameter($storageUid, ParameterType::INTEGER)
                ),
                $queryBuilder->expr()->or(...$pathExpressions)
            );
        }

        return $queryBuilder->expr()->or(...$storageExpressions);
    }
}
