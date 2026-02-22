<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\ArrayParameterType;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\LanguageService;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;

/**
 * Tool for retrieving the TYPO3 page tree
 */
class GetPageTreeTool extends AbstractRecordTool
{
    private const SUBPAGE_LIMIT = 10;

    protected SiteInformationService $siteInformationService;
    protected LanguageService $languageService;

    public function __construct(
        SiteInformationService $siteInformationService,
        LanguageService $languageService
    ) {
        parent::__construct();
        $this->siteInformationService = $siteInformationService;
        $this->languageService = $languageService;
    }

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        $schema = [
            'description' => 'Get the TYPO3 page tree structure as a readable text tree.Essential for understanding page hierarchy before creating new pages, finding pages by their position, and verifying parent-child relationships.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'startPage' => [
                        'type' => 'integer',
                        'description' => 'The page ID to start from (0 for root)',
                    ],
                    'depth' => [
                        'type' => 'integer',
                        'description' => 'The depth of pages to retrieve (default: 3)',
                    ],
                ],
                'required' => ['startPage'],
            ],
        ];

        // Only add language parameter if multiple languages are configured
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        if (count($availableLanguages) > 1) {
            $schema['inputSchema']['properties']['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code to show translated page titles (e.g., "de", "fr"). Shows translation status for each page.',
                'enum' => $availableLanguages,
            ];
        }

        // Add annotations
        $schema['annotations'] = [
            'readOnlyHint' => true,
            'idempotentHint' => true
        ];

        return $schema;
    }

    /**
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {

        $startPage = (int)($params['startPage'] ?? 0);
        $depth = (int)($params['depth'] ?? 3);
        $languageUid = null;

        // Handle language parameter if provided
        if (isset($params['language'])) {
            $languageUid = $this->languageService->getUidFromIsoCode($params['language']);
            if ($languageUid === null) {
                throw new \InvalidArgumentException('Unknown language code: ' . $params['language']);
            }
        }

        // Get page tree with the specified parameters
        $pageTree = $this->getPageTree($startPage, $depth, $languageUid);

        // Collect all page UIDs from the tree
        $pageUids = $this->collectPageUids($pageTree);

        // Get record counts for all pages
        $recordCounts = $this->getRecordCounts($pageUids);

        // Get plugin hints for all pages
        $pluginHints = [];
        foreach ($pageUids as $uid) {
            $hint = $this->getPluginStorageHints($uid);
            if ($hint) {
                $pluginHints[$uid] = $hint;
            }
        }

        // Convert the page tree to a text-based tree with indentation
        $textTree = $this->renderTextTree($pageTree, 0, $languageUid, $recordCounts, $pluginHints);

        return new CallToolResult([new TextContent($textTree)]);
    }

    /**
     * Get the page tree using batch queries per layer.
     *
     * Fetches one layer at a time with a single query per layer instead of
     * one query per parent page. The first layer (direct children of startPage)
     * is always fetched completely. Subsequent layers are limited to SUBPAGE_LIMIT
     * children per parent to prevent large folders from overwhelming the output.
     */
    protected function getPageTree(int $startPage, int $depth, ?int $languageUid = null): array
    {
        $pageRepository = $this->createPageRepository($languageUid);

        // Collect layer data top-down, one batch query per layer
        $layerData = [];
        $currentParentUids = [$startPage];

        for ($d = 0; $d < $depth; $d++) {
            if (empty($currentParentUids)) {
                break;
            }

            // First layer (d=0) is unlimited, subsequent layers are limited per parent
            $limit = ($d === 0) ? null : self::SUBPAGE_LIMIT;
            $layerData[$d] = $this->fetchChildrenBatch($currentParentUids, $languageUid, $pageRepository, $limit);

            // Collect UIDs of fetched pages for the next layer
            $currentParentUids = [];
            foreach ($layerData[$d] as $info) {
                foreach ($info['pages'] as $page) {
                    $currentParentUids[] = $page['uid'];
                }
            }
        }

        // Batch count subpages for leaf nodes (pages at the deepest fetched level)
        $subpageCounts = !empty($currentParentUids)
            ? $this->batchCountSubpages($currentParentUids)
            : [];

        // Assemble the nested tree from collected layer data
        return $this->buildTreeFromLayers($layerData, $subpageCounts, $startPage, $depth);
    }

    /**
     * Fetch children for multiple parent UIDs in a single query.
     *
     * @param array $parentUids Parent page UIDs to fetch children for
     * @param int|null $languageUid Language UID for overlays
     * @param PageRepository $pageRepository PageRepository with language context
     * @param int|null $perParentLimit Max children per parent (null = unlimited)
     * @return array Keyed by parent UID: [parentUid => ['pages' => [...], 'total' => int]]
     */
    protected function fetchChildrenBatch(
        array $parentUids,
        ?int $languageUid,
        PageRepository $pageRepository,
        ?int $perParentLimit = null
    ): array {
        if (empty($parentUids)) {
            return [];
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $query = $queryBuilder->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($parentUids, ArrayParameterType::INTEGER)
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
                )
            )
            ->orderBy('pid')
            ->addOrderBy('sorting');

        $allPages = $query->executeQuery()->fetchAllAssociative();

        // Initialize result structure for all requested parents
        $grouped = [];
        foreach ($parentUids as $uid) {
            $grouped[$uid] = ['pages' => [], 'total' => 0];
        }

        // Group pages by parent and apply per-parent limit
        foreach ($allPages as $page) {
            $pid = (int)$page['pid'];
            if (!isset($grouped[$pid])) {
                $grouped[$pid] = ['pages' => [], 'total' => 0];
            }

            $grouped[$pid]['total']++;

            // Skip pages beyond the per-parent limit (but keep counting total)
            if ($perParentLimit !== null && count($grouped[$pid]['pages']) >= $perParentLimit) {
                continue;
            }

            $pageData = [
                'uid' => (int)$page['uid'],
                'pid' => $pid,
                'title' => $page['title'],
                'nav_title' => $page['nav_title'],
                'hidden' => (bool)$page['hidden'],
                'doktype' => (int)$page['doktype'],
                'subpageCount' => 0,
                'url' => $this->siteInformationService->generatePageUrl((int)$page['uid']),
            ];

            // Apply language overlay
            if ($languageUid !== null && $languageUid > 0) {
                $overlaidPage = $pageRepository->getPageOverlay($page, $languageUid);

                if ($overlaidPage !== $page) {
                    $pageData['title'] = $overlaidPage['title'] ?: $pageData['title'];
                    $pageData['nav_title'] = $overlaidPage['nav_title'] ?: $pageData['nav_title'];
                    $pageData['hidden'] = (bool)$overlaidPage['hidden'];
                    $pageData['_translated'] = true;
                } else {
                    $pageData['_translated'] = false;
                }
            }

            $grouped[$pid]['pages'][] = $pageData;
        }

        return $grouped;
    }

    /**
     * Count subpages for multiple parent UIDs in a single query.
     *
     * @param array $parentUids Parent page UIDs to count children for
     * @return array Keyed by parent UID: [parentUid => count]
     */
    protected function batchCountSubpages(array $parentUids): array
    {
        if (empty($parentUids)) {
            return [];
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $counts = $queryBuilder
            ->select('pid')
            ->addSelectLiteral('COUNT(*) AS count')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($parentUids, ArrayParameterType::INTEGER)
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
                )
            )
            ->groupBy('pid')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($counts as $row) {
            $result[(int)$row['pid']] = (int)$row['count'];
        }

        return $result;
    }

    /**
     * Build the nested tree structure from pre-fetched layer data.
     *
     * @param array $layerData Layer data indexed by depth, then by parent UID
     * @param array $subpageCounts Subpage counts for leaf nodes
     * @param int $parentUid The parent UID to build children for
     * @param int $maxDepth Total depth requested
     * @param int $currentLayer Current layer index (0-based)
     * @return array Nested tree structure
     */
    protected function buildTreeFromLayers(
        array $layerData,
        array $subpageCounts,
        int $parentUid,
        int $maxDepth,
        int $currentLayer = 0
    ): array {
        if (!isset($layerData[$currentLayer][$parentUid])) {
            return [];
        }

        $layerInfo = $layerData[$currentLayer][$parentUid];
        $result = [];

        foreach ($layerInfo['pages'] as $page) {
            if ($currentLayer + 1 < $maxDepth) {
                // Not at max depth: attach children recursively
                $page['subpages'] = $this->buildTreeFromLayers(
                    $layerData,
                    $subpageCounts,
                    $page['uid'],
                    $maxDepth,
                    $currentLayer + 1
                );
                // Use total from the next layer's data for this parent
                $page['subpageCount'] = isset($layerData[$currentLayer + 1][$page['uid']])
                    ? $layerData[$currentLayer + 1][$page['uid']]['total']
                    : 0;
            } else {
                // At max depth: use batch-counted subpage counts
                $page['subpageCount'] = $subpageCounts[$page['uid']] ?? 0;
            }

            $result[] = $page;
        }

        return $result;
    }

    /**
     * Create a PageRepository with the appropriate language context.
     */
    protected function createPageRepository(?int $languageUid): PageRepository
    {
        $context = GeneralUtility::makeInstance(Context::class);

        if ($languageUid !== null && $languageUid > 0) {
            $languageAspect = new LanguageAspect(
                $languageUid,
                $languageUid,
                LanguageAspect::OVERLAYS_MIXED,
                [$languageUid]
            );
            $context->setAspect('language', $languageAspect);
        }

        return GeneralUtility::makeInstance(PageRepository::class, $context);
    }

    /**
     * Collect all page UIDs from the tree structure
     */
    protected function collectPageUids(array $pageTree): array
    {
        $uids = [];

        foreach ($pageTree as $page) {
            $uids[] = $page['uid'];

            if (!empty($page['subpages'])) {
                $uids = array_merge($uids, $this->collectPageUids($page['subpages']));
            }
        }

        return $uids;
    }

    /**
     * Get record counts for given page UIDs
     */
    protected function getRecordCounts(array $pageUids): array
    {
        if (empty($pageUids)) {
            return [];
        }

        $recordCounts = [];

        // Get accessible tables (exclude read-only system tables)
        $accessibleTables = $this->tableAccessService->getAccessibleTables(false);

        foreach ($accessibleTables as $table => $accessInfo) {
            // Skip pages table itself
            if ($table === 'pages') {
                continue;
            }

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);

            // Only apply DeletedRestriction
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            // Count records grouped by pid
            $counts = $queryBuilder
                ->select('pid')
                ->addSelectLiteral('COUNT(*) AS count')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter(
                            $pageUids,
                            ArrayParameterType::INTEGER
                        )
                    )
                )
                ->groupBy('pid')
                ->executeQuery()
                ->fetchAllAssociative();

            // Store counts
            foreach ($counts as $row) {
                $pid = (int)$row['pid'];
                $count = (int)$row['count'];

                if (!isset($recordCounts[$pid])) {
                    $recordCounts[$pid] = [];
                }

                $recordCounts[$pid][$table] = $count;
            }
        }

        return $recordCounts;
    }

    /**
     * Get plugin storage hints for a page
     */
    protected function getPluginStorageHints(int $pageId): string
    {
        $hints = '';

        // Query for plugins on this page
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $plugins = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('list'))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($plugins as $plugin) {
            // Check for news plugin with startingpoint configuration
            if (isset($plugin['list_type']) && $plugin['list_type'] === 'news_pi1' && !empty($plugin['pi_flexform'])) {
                // Simple regex to extract startingpoint value
                if (preg_match('/<field index="settings\.startingpoint">.*?<value[^>]*>(\d+)<\/value>/s', $plugin['pi_flexform'], $matches)) {
                    $storagePid = (int)$matches[1];
                    $hints .= ' [news plugin → pid:' . $storagePid . ']';
                }
            }
        }

        return $hints;
    }

    /**
     * Get human-readable doktype label
     */
    protected function getDoktypeLabel(int $doktype): string
    {
        return match ($doktype) {
            PageRepository::DOKTYPE_DEFAULT => 'Page',
            PageRepository::DOKTYPE_LINK => 'Link',
            PageRepository::DOKTYPE_SHORTCUT => 'Shortcut',
            PageRepository::DOKTYPE_BE_USER_SECTION => 'Backend Section',
            PageRepository::DOKTYPE_MOUNTPOINT => 'Mount Point',
            PageRepository::DOKTYPE_SPACER => 'Spacer',
            PageRepository::DOKTYPE_SYSFOLDER => 'System Folder',
            default => 'Unknown Type'
        };
    }

    /**
     * Render the page tree as a text-based tree with indentation
     */
    protected function renderTextTree(array $pageTree, int $level = 0, ?int $languageUid = null, array $recordCounts = [], array $pluginHints = []): string
    {
        $result = '';
        $indent = str_repeat('  ', $level);

        foreach ($pageTree as $page) {
            $title = $page['nav_title'] ?: $page['title'];
            $hiddenMark = $page['hidden'] ? ' [HIDDEN]' : '';
            $doktypeLabel = $this->getDoktypeLabel($page['doktype']);

            // Start building the line: [uid] Title [Type]
            $result .= $indent . '- [' . $page['uid'] . '] ' . $title . ' [' . $doktypeLabel . ']' . $hiddenMark;

            // Add translation status if language specified
            if ($languageUid !== null && $languageUid > 0) {
                if (isset($page['_translated'])) {
                    $result .= $page['_translated'] ? ' [TRANSLATED]' : ' [NOT TRANSLATED]';
                }
            }

            // Add record counts if available
            if (!empty($recordCounts[$page['uid']])) {
                foreach ($recordCounts[$page['uid']] as $table => $count) {
                    $result .= ' [' . $table . ': ' . $count . ']';
                }
            }

            // Add URL if available
            if (!empty($page['url'])) {
                $result .= ' - ' . $page['url'];
            }

            // If the page has no expanded subpages but has children, show the count
            if (empty($page['subpages']) && $page['subpageCount'] > 0) {
                $result .= ' (' . $page['subpageCount'] . ' subpages)';
            }

            // Add plugin hints if available
            if (!empty($pluginHints[$page['uid']])) {
                $result .= $pluginHints[$page['uid']];
            }

            $result .= PHP_EOL;

            if (!empty($page['subpages'])) {
                $result .= $this->renderTextTree($page['subpages'], $level + 1, $languageUid, $recordCounts, $pluginHints);

                // Show truncation notice if not all children were fetched
                $shownCount = count($page['subpages']);
                $totalCount = $page['subpageCount'];
                if ($totalCount > $shownCount) {
                    $childIndent = str_repeat('  ', $level + 1);
                    $result .= $childIndent . '- (showing ' . $shownCount . ' of ' . $totalCount . ' subpages, use GetPageTree with startPage: ' . $page['uid'] . ' to see all)' . PHP_EOL;
                }
            }
        }

        return $result;
    }

}
