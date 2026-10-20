<?php

declare(strict_types=1);

namespace Hn\McpServer\EventListener;

use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\Service\PageAccessService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Restricts record reads to the pages inside the current backend user's DB
 * mounts that grant "show" permission, the same pages the TYPO3 backend shows
 * that user. Admins are not restricted; tables whose records only live on the
 * root level (rootLevel = 1) are governed by table permissions alone.
 */
final class PageMountRestrictionListener
{
    public function __invoke(BeforeRecordReadEvent $event): void
    {
        $queryBuilder = $event->getQueryBuilder();
        $restriction = GeneralUtility::makeInstance(PageAccessService::class)
            ->buildRecordRestriction($queryBuilder, $event->getTable());
        if ($restriction !== null) {
            $queryBuilder->andWhere($restriction);
        }
    }
}
