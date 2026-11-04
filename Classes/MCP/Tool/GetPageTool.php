<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\LanguageService as McpLanguageService;
use Hn\McpServer\Service\RecordUrlService;
use Hn\McpServer\Utility\RecordFormattingUtility;
use Hn\McpServer\Service\TableAccessService;

/**
 * Tool for retrieving detailed information about a TYPO3 page
 */
class GetPageTool extends AbstractRecordTool
{
    protected SiteInformationService $siteInformationService;
    protected McpLanguageService $languageService;
    protected RecordUrlService $recordUrlService;
    
    public function __construct(
        SiteInformationService $siteInformationService,
        McpLanguageService $languageService
    ) {
        parent::__construct();
        $this->siteInformationService = $siteInformationService;
        $this->languageService = $languageService;
        $this->recordUrlService = GeneralUtility::makeInstance(RecordUrlService::class);
    }

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        // Get available domains text dynamically
        $domainsText = $this->siteInformationService->getAvailableDomainsText();
        
        $schema = [
            'description' => 'Get detailed information about a TYPO3 page including its records. Can fetch by page ID or URL. Shows content in the specified language when available.'
                . ($this->recordUrlService->isPreviewLinkEnabled() ? ' When the page or its content has unpublished changes, a temporary preview URL showing them without login is included.' : ''),
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The page ID to retrieve information for',
                    ],
                    'url' => [
                        'type' => 'string',
                        'description' => 'The URL of the page to retrieve (alternative to uid). Can be full URL, path, or slug; a language prefix like "/en/" shows the page in that language. ' . $domainsText,
                    ],
                ],
                'required' => [],
            ],
        ];
        
        // Only add language parameter if multiple languages are configured
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        if (count($availableLanguages) > 1) {
            $schema['inputSchema']['properties']['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code to show page and content in specific language (e.g., "de", "fr"). The page and its translated records are then listed under the uids of their translation records — edit those, not the default-language originals. Untranslated records are labeled as such.',
                'enum' => $availableLanguages,
            ];
            
            // Add deprecated languageId for backward compatibility
            $schema['inputSchema']['properties']['languageId'] = [
                'type' => 'integer',
                'description' => 'DEPRECATED: Use "language" parameter with ISO code instead. Language ID for URL generation.',
                'deprecated' => true,
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
        
        // Handle language parameter
        $languageId = 0;
        if (isset($params['language'])) {
            // Convert ISO code to language UID
            $languageId = $this->languageService->getUidFromIsoCode($params['language']);
            if ($languageId === null) {
                throw new \InvalidArgumentException('Unknown language code: ' . $params['language']);
            }
        } elseif (isset($params['languageId'])) {
            // Backward compatibility with numeric languageId
            $languageId = (int)$params['languageId'];
        }

        // Determine page UID from either uid parameter or url parameter
        $uid = 0;
        if (isset($params['uid'])) {
            $uid = (int)$params['uid'];
        } elseif (isset($params['url'])) {
            try {
                [$uid, $urlLanguageId] = $this->resolveUrlToPageUid($params['url']);
                // A language prefix in the URL (e.g. "/en/") selects the language
                // unless one was requested explicitly
                if ($urlLanguageId !== null && !isset($params['language']) && !isset($params['languageId'])) {
                    $languageId = $urlLanguageId;
                }
            } catch (\Throwable $e) {
                // Re-throw as InvalidArgumentException to preserve the message in error handling
                throw new \InvalidArgumentException($e->getMessage(), 0, $e);
            }
        }

        if ($uid <= 0) {
            throw new \InvalidArgumentException('Invalid page UID or URL. Please provide a valid page ID or URL.');
        }

        // Language views list translated pages under the translation's uid; such a uid
        // shows its default-language page in the translation's language
        $translatedPage = BackendUtility::getRecordWSOL('pages', $uid);
        if ((int)($translatedPage['sys_language_uid'] ?? 0) > 0 && (int)($translatedPage['l10n_parent'] ?? 0) > 0) {
            $uid = (int)$translatedPage['l10n_parent'];
            if (!isset($params['language']) && !isset($params['languageId'])) {
                $languageId = (int)$translatedPage['sys_language_uid'];
            }
        }

        // Get page data (with language overlay if applicable)
        $pageData = $this->getPageData($uid, $languageId);
        
        // Live URL, plus a preview URL when the page or its content has unpublished changes
        $pageUrls = $this->recordUrlService->getUrls('pages', [$pageData['_versioning']], $languageId)[0] ?? [];
        
        // Get records on this page (filtered by language if specified)
        $recordsInfo = $this->getPageRecords($uid, $languageId);
        
        // Get available translations for this page
        $translations = $this->getPageTranslations($uid);
        
        // Build a text representation of the page information
        $result = $this->formatPageInfo($pageData, $recordsInfo, $pageUrls, $languageId, $translations);

        $result = $this->getWorkspaceHint() . $result;

        return new CallToolResult([new TextContent($result)]);
    }

    /**
     * Get basic page data with language overlay if applicable
     *
     * This method uses direct QueryBuilder instead of PageRepository to properly
     * handle workspace-only pages (pages that exist only in a workspace, not yet live).
     */
    protected function getPageData(int $uid, int $languageId = 0): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');

        // Apply proper workspace restrictions
        $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $currentWorkspace))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $currentWorkspace));

        $queryBuilder->select('*')->from('pages');

        // Handle workspace UID lookup (both live UID and workspace versions)
        if ($currentWorkspace > 0) {
            // In workspace context, check both live UID and workspace versions (t3ver_oid)
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
                )
            );
        } else {
            // In live workspace, just filter by UID
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            );
        }

        $page = $queryBuilder->executeQuery()->fetchAssociative();

        if (!$page) {
            throw new \RuntimeException('Page not found: ' . $uid);
        }

        // Apply workspace overlay: replaces live page data with workspace-modified version
        if ($currentWorkspace > 0) {
            BackendUtility::workspaceOL('pages', $page);
            if ($page === false) {
                throw new \RuntimeException('Page ' . $uid . ' is marked for deletion in current workspace');
            }
        }

        // Restore live UID for workspace overlay records. workspaceOL() replaces uid with the
        // overlay record's uid — we swap back to the live uid (t3ver_oid) for consistent API output
        // and because getPageOverlay() below expects the live uid to find translations.
        if (isset($page['t3ver_oid']) && $page['t3ver_oid'] > 0) {
            $page['uid'] = (int)$page['t3ver_oid'];
        }

        // Versioning state of the default language page for URL resolution, kept
        // apart because a language overlay replaces the t3ver_* fields below
        $page['_versioning'] = [
            'uid' => (int)$page['uid'],
            'pid' => (int)$page['pid'],
            't3ver_oid' => (int)($page['t3ver_oid'] ?? 0),
            't3ver_wsid' => (int)($page['t3ver_wsid'] ?? 0),
            't3ver_state' => (int)($page['t3ver_state'] ?? 0),
        ];

        // Apply language overlay if language is specified. The overlay keeps the
        // default-language uid in 'uid' (needed for URLs and the record lookup);
        // the translation record's uid is kept apart in '_translationUid'.
        if ($languageId > 0) {
            $pageRepository = $this->createLanguageOverlayRepository($languageId);
            $overlaidPage = $pageRepository->getPageOverlay($page, $languageId);
            $translationUid = $this->getOverlayTranslationUid($overlaidPage);

            // Add our custom metadata
            if ($translationUid !== null) {
                // Page was overlaid
                $overlaidPage['_translated'] = true;
                $overlaidPage['_translationUid'] = $translationUid;
                $overlaidPage['_language_uid'] = $languageId;
                if (isset($page['title']) && isset($overlaidPage['title']) && $page['title'] !== $overlaidPage['title']) {
                    $overlaidPage['_original_title'] = $page['title'];
                }
                $page = $overlaidPage;
            } else {
                // No overlay found
                $page['_translated'] = false;
                $page['_language_uid'] = $languageId;
            }
        }

        // Convert some values to their proper types
        $page['uid'] = (int)$page['uid'];
        $page['pid'] = (int)$page['pid'];
        $page['hidden'] = (bool)$page['hidden'];
        $page['deleted'] = (bool)($page['deleted'] ?? false);

        return $page;
    }


    
    /**
     * Get available translations for a page
     */
    protected function getPageTranslations(int $pageUid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        // Apply proper workspace restrictions
        $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $currentWorkspace))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $currentWorkspace));

        $translations = $queryBuilder->select('uid', 'sys_language_uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER))
            )
            ->orderBy('sys_language_uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($translations as $translation) {
            $languageId = (int)$translation['sys_language_uid'];
            $isoCode = $this->languageService->getIsoCodeFromUid($languageId);
            if ($isoCode) {
                // Workspace changes of the title show up like everywhere else
                $workspaceTranslation = BackendUtility::getRecordWSOL('pages', (int)$translation['uid']);
                $result[] = [
                    'uid' => (int)$translation['uid'],
                    'languageId' => $languageId,
                    'isoCode' => $isoCode,
                    'title' => $workspaceTranslation['title'] ?? $translation['title'],
                ];
            }
        }

        return $result;
    }
    
    /**
     * Get records on the page grouped by table
     */
    protected function getPageRecords(int $pageId, int $languageId = 0): array
    {
        // Get all tables that can be on a page
        $tables = $this->getContentTables();
        $pageRepository = $languageId > 0 ? $this->createLanguageOverlayRepository($languageId) : null;

        $recordsInfo = [];

        foreach ($tables as $table) {
            // The language is filtered in the query, before the row limit, so the
            // total counts the records of this view and nothing is cut off silently
            $tableInfo = $this->getTableRecordsInfo($table, $pageId, $languageId);

            if ($pageRepository !== null && !empty($tableInfo['records']) && $this->tableHasLanguageSupport($table)) {
                $tableInfo['records'] = $this->overlayRecordsForLanguage($table, $tableInfo['records'], $languageId, $pageRepository);
            }
            if (!empty($tableInfo['records'])) {
                $recordsInfo[$table] = $tableInfo;
            }
        }

        return $recordsInfo;
    }
    
    /**
     * Get a list of content tables that can be on a page using TableAccessService
     */
    protected function getContentTables(): array
    {
        // Get all accessible tables from TableAccessService (include read-only tables)
        $accessibleTables = $this->tableAccessService->getAccessibleTables(true);
        
        // Filter to only include tables that can be on a page (have pid field)
        $contentTables = [];
        
        foreach (array_keys($accessibleTables) as $table) {
            // Check if the table has a pid column in its TCA configuration
            // This means it can be associated with a page
            if (isset($GLOBALS['TCA'][$table]['ctrl'])) {
                $contentTables[] = $table;
            }
        }
        
        return $contentTables;
    }
    
    /**
     * Get information about records from a specific table on a page
     */
    /**
     * Records of a table on a page, at most 20, with the total count.
     *
     * Language-aware tables only load the rows of the requested view: default-language
     * and "all languages" rows, plus in a language view the rows that only exist in that
     * language. Connected translations are added by overlayRecordsForLanguage().
     */
    protected function getTableRecordsInfo(string $table, int $pageId, int $languageId = 0): array
    {
        // Skip if table is not accessible
        if (!$this->tableAccessService->canAccessTable($table)) {
            return [
                'total' => 0,
                'records' => []
            ];
        }

        // Always include hidden records (like the TYPO3 backend does)
        $queryBuilder = $this->createRecordQueryBuilder($table);

        // First, get the total count of records
        $countQueryBuilder = clone $queryBuilder;
        $totalCount = $countQueryBuilder->count('*')
            ->from($table)
            ->where(
                $countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
                ...$this->getLanguageViewConditions($countQueryBuilder, $table, $languageId)
            )
            ->executeQuery()
            ->fetchOne();

        // Now get the limited records
        $query = $queryBuilder->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
                ...$this->getLanguageViewConditions($queryBuilder, $table, $languageId)
            );

        // Limit to 20 records per table
        $query->setMaxResults(20);

        // Order by uid as default, but use TCA sorting field if available
        if (!empty($GLOBALS['TCA'][$table]['ctrl']['sortby'])) {
            $query->orderBy($GLOBALS['TCA'][$table]['ctrl']['sortby']);
        } elseif (!empty($GLOBALS['TCA'][$table]['ctrl']['default_sortby'])) {
            // Parse the default_sortby field which might contain ORDER BY statements
            $sortbyFields = GeneralUtility::trimExplode(',', str_replace('ORDER BY', '', $GLOBALS['TCA'][$table]['ctrl']['default_sortby']));
            foreach ($sortbyFields as $sortbyField) {
                $sortbyFieldAndDirection = GeneralUtility::trimExplode(' ', $sortbyField);
                $query->addOrderBy(
                    $sortbyFieldAndDirection[0],
                    (isset($sortbyFieldAndDirection[1]) && strtolower($sortbyFieldAndDirection[1]) === 'desc') ? 'DESC' : 'ASC'
                );
            }
        } else {
            $query->orderBy('uid');
        }

        $rows = $query->executeQuery()->fetchAllAssociative();
        $records = $this->applyWorkspaceOverlay($table, $rows);
        // Records marked for deletion in this workspace are excluded
        $totalCount = max(0, (int)$totalCount - (count($rows) - count($records)));

        return [
            'total' => $totalCount,
            'records' => $records
        ];
    }

    /**
     * Query builder for page records: deleted records excluded, current workspace applied
     */
    protected function createRecordQueryBuilder(string $table): QueryBuilder
    {
        $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $currentWorkspace))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $currentWorkspace));
        return $queryBuilder;
    }

    /**
     * Rows a language view loads directly: default-language and "all languages" rows,
     * plus rows that only exist in the requested language (no translation pointer)
     *
     * @return string[] Conditions to AND, none for tables without language support
     */
    protected function getLanguageViewConditions(QueryBuilder $queryBuilder, string $table, int $languageId): array
    {
        if (!$this->tableHasLanguageSupport($table)) {
            return [];
        }
        $languageField = $GLOBALS['TCA'][$table]['ctrl']['languageField'];
        $pointerField = $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '';
        $expr = $queryBuilder->expr();
        $defaultLanguage = $expr->in(
            $languageField,
            $queryBuilder->createNamedParameter([0, -1], ArrayParameterType::INTEGER)
        );
        if ($languageId === 0) {
            return [$defaultLanguage];
        }
        $requestedLanguage = $expr->eq($languageField, $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER));
        if ($pointerField !== '') {
            $requestedLanguage = $expr->and(
                $requestedLanguage,
                $expr->eq($pointerField, $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            );
        }
        return [(string)$expr->or($defaultLanguage, $requestedLanguage)];
    }

    /**
     * Replace live rows with their workspace version and expose the live uid, drop rows
     * deleted in the workspace
     */
    protected function applyWorkspaceOverlay(string $table, array $rows): array
    {
        $currentWorkspace = $GLOBALS['BE_USER']->workspace ?? 0;
        $records = [];
        foreach ($rows as $record) {
            if ($currentWorkspace > 0) {
                BackendUtility::workspaceOL($table, $record);
                if ($record === false) {
                    continue;
                }
            }
            // Workspace transparency: expose live UID for workspace overlay records
            if (isset($record['t3ver_oid']) && $record['t3ver_oid'] > 0) {
                $record['uid'] = (int)$record['t3ver_oid'];
            }
            $records[] = $record;
        }
        return $records;
    }

    /**
     * Translations of the given originals in a language, by original uid
     */
    protected function loadTranslations(string $table, string $languageField, string $pointerField, array $originalUids, int $languageId): array
    {
        if ($originalUids === []) {
            return [];
        }
        $queryBuilder = $this->createRecordQueryBuilder($table);
        $rows = $queryBuilder->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in($pointerField, $queryBuilder->createNamedParameter($originalUids, ArrayParameterType::INTEGER)),
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER))
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
        $translations = [];
        foreach ($this->applyWorkspaceOverlay($table, $rows) as $record) {
            $translations[(int)$record[$pointerField]] ??= $record;
        }
        return $translations;
    }

    /**
     * Check if a table has a hidden field using TCA
     */
    protected function tableHasHiddenField(string $table): bool
    {
        return isset($GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled']);
    }
    
    /**
     * Check if table has language support
     */
    protected function tableHasLanguageSupport(string $table): bool
    {
        return isset($GLOBALS['TCA'][$table]['ctrl']['languageField']);
    }
    
    /**
     * Show the records of one table as they appear in the given language.
     *
     * Every default-language record is overlaid with its translation by the core.
     * A translated record is listed once, under the translation's uid; untranslated
     * ones keep their own uid. Records only existing in the language (free mode)
     * are kept; other translations are covered by their original or orphaned.
     * '_languageLabel' tells the client which record it is looking at.
     *
     * @param array $records Workspace-overlaid rows with live uids, in display order
     */
    protected function overlayRecordsForLanguage(string $table, array $records, int $languageId, PageRepository $pageRepository): array
    {
        $languageField = $GLOBALS['TCA'][$table]['ctrl']['languageField'];
        $pointerField = $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '';
        $isoCode = strtoupper($this->languageService->getIsoCodeFromUid($languageId) ?? (string)$languageId);
        $defaultIsoCode = strtoupper($this->languageService->getDefaultIsoCode() ?? 'default');

        // Translations by their original's uid. Fallback for translations the core
        // overlay does not select, e.g. because of frontend group restrictions.
        $loadedTranslations = [];
        if ($pointerField !== '') {
            $originalUids = [];
            foreach ($records as $record) {
                if ((int)($record[$languageField] ?? 0) === 0) {
                    $originalUids[] = (int)$record['uid'];
                }
            }
            $loadedTranslations = $this->loadTranslations($table, $languageField, $pointerField, $originalUids, $languageId);
        }

        $entries = [];
        $listedTranslationUids = [];
        foreach ($records as $record) {
            $recordLanguage = (int)($record[$languageField] ?? 0);
            if ($recordLanguage === -1) {
                $record['_languageLabel'] = 'all languages';
                $entries[] = $record;
            } elseif ($recordLanguage === 0) {
                $originalUid = (int)$record['uid'];
                $overlaid = $pageRepository->getLanguageOverlay($table, $record);
                $translationUid = is_array($overlaid) ? $this->getOverlayTranslationUid($overlaid) : null;
                if ($translationUid === null && isset($loadedTranslations[$originalUid])) {
                    $overlaid = $loadedTranslations[$originalUid];
                    $translationUid = (int)$overlaid['uid'];
                }
                if ($translationUid !== null) {
                    $overlaid['uid'] = $translationUid;
                    $overlaid['_languageLabel'] = $isoCode . ', translates ' . $originalUid;
                    $listedTranslationUids[$translationUid] = true;
                    $entries[] = $overlaid;
                } else {
                    $record['_languageLabel'] = $defaultIsoCode . ' original, not translated';
                    $entries[] = $record;
                }
            } elseif ($recordLanguage === $languageId) {
                // Decided below, once all overlays are known
                $entries[] = $record;
            }
        }

        $result = [];
        foreach ($entries as $entry) {
            if (!isset($entry['_languageLabel'])) {
                $isConnectedTranslation = $pointerField !== '' && (int)($entry[$pointerField] ?? 0) > 0;
                if ($isConnectedTranslation || isset($listedTranslationUids[(int)$entry['uid']])) {
                    continue;
                }
                $entry['_languageLabel'] = $isoCode;
            }
            $result[] = $entry;
        }
        return $result;
    }
    
    /**
     * Format page information as readable text
     */
    protected function formatPageInfo(array $pageData, array $recordsInfo, array $pageUrls = [], int $languageId = 0, array $translations = []): string
    {
        $result = "PAGE INFORMATION\n";
        $result .= "================\n\n";
        
        // Basic page info. In a language view the translation record is the one to
        // edit, so its uid is the primary one.
        $result .= "UID: " . ($pageData['_translationUid'] ?? $pageData['uid']) . "\n";
        $result .= "Title: " . $pageData['title'] . "\n";
        
        if (isset($pageUrls['frontend_url'])) {
            $result .= "URL: " . $pageUrls['frontend_url'] . "\n";
        } elseif (isset($pageUrls['preview_url'])) {
            $result .= "URL: none yet, the page is not published\n";
        }
        if (isset($pageUrls['preview_url'])) {
            $endtime = $this->recordUrlService->getPreviewLinkEndtime();
            $result .= "Preview URL: " . $pageUrls['preview_url'] . "\n";
            $result .= "  (shows this page including unpublished changes, no login needed"
                . ($endtime !== null ? ", valid until " . date('Y-m-d H:i', $endtime) : '')
                . "; it grants read access to all unpublished changes, share it only with people who may see them)\n";
        }
        
        if (!empty($pageData['nav_title'])) {
            $result .= "Navigation Title: " . $pageData['nav_title'] . "\n";
        }
        
        if (!empty($pageData['subtitle'])) {
            $result .= "Subtitle: " . $pageData['subtitle'] . "\n";
        }
        
        $result .= "Parent Page (PID): " . $pageData['pid'] . "\n";
        $result .= "Doktype: " . $pageData['doktype'] . "\n";
        $result .= "Hidden: " . ($pageData['hidden'] ? 'Yes' : 'No') . "\n";
        $result .= "Created: " . date('Y-m-d H:i:s', (int)$pageData['crdate']) . "\n";
        $result .= "Last Modified: " . date('Y-m-d H:i:s', (int)$pageData['tstamp']) . "\n";
        
        // Add language/translation information
        if ($languageId > 0) {
            $isoCode = strtoupper($this->languageService->getIsoCodeFromUid($languageId) ?? 'unknown');
            $result .= "Language: " . $isoCode . " (ID: $languageId)\n";
            if (isset($pageData['_translationUid'])) {
                $result .= "Translated: Yes (" . $isoCode . " translation of page " . $pageData['uid'] . ")\n";
            } else {
                $result .= "Translated: No (default-language record — use WriteTable action \"translate\" to create the "
                    . $isoCode . " translation)\n";
            }
        }
        
        if ($languageId === 0) {
            $result .= $this->formatTranslationList((int)$pageData['uid'], $translations);
        } elseif (!empty($translations)) {
            $result .= "Available Translations: "
                . strtoupper(implode(', ', array_column($translations, 'isoCode'))) . "\n";
        }
        
        $result .= "\n";
        
        // Records on the page
        $result .= "RECORDS ON THIS PAGE\n";
        $result .= "===================\n\n";
        
        // Handle tt_content specially - group by column position
        if (isset($recordsInfo['tt_content'])) {
            $result .= $this->formatContentElements($recordsInfo['tt_content'], (int)$pageData['uid']);
            // Remove tt_content from the recordsInfo so we don't process it again below
            unset($recordsInfo['tt_content']);
        }
        
        // Process other tables
        foreach ($recordsInfo as $table => $tableInfo) {
            $tableLabel = $table;
            if (!empty($GLOBALS['TCA'][$table]['ctrl']['title'])) {
                $tableLabel = TableAccessService::translateLabel($GLOBALS['TCA'][$table]['ctrl']['title']);
            }
            $totalCount = $tableInfo['total'];
            $records = $tableInfo['records'];
            $displayCount = count($records);
            $result .= "Table: " . $tableLabel . " (" . $table . ") - " . $totalCount . " total records\n";
            
            if ($displayCount > 0) {
                foreach ($records as $record) {
                    $title = RecordFormattingUtility::getRecordTitle($table, $record);
                    $result .= "- [" . $record['uid'] . "] " . $title . $this->formatLanguageLabel($record) . "\n";
                }
                
                if ($displayCount < $totalCount) {
                    $result .= "  (showing " . $displayCount . " of " . $totalCount . " records)\n";
                }
            } else {
                $result .= "  No records found\n";
            }
            
            $result .= "\n";
        }
        
        return $result;
    }
    
    /**
     * Language of the default view and where its translations are, so a client asked
     * for "the English page" finds the record to edit. Empty on single-language sites.
     */
    protected function formatTranslationList(int $pageUid, array $translations): string
    {
        $languages = array_filter(
            $this->languageService->getLanguagesForPage($pageUid),
            static fn(array $language): bool => $language['enabled']
        );
        if (count($languages) < 2) {
            return '';
        }

        $translationsByLanguage = array_column($translations, null, 'languageId');
        $result = '';
        $lines = [];
        foreach ($languages as $language) {
            $isoCode = strtoupper($language['isoCode']);
            if ($language['uid'] === 0) {
                $result .= "Language: " . $isoCode . " (default language)\n";
            } elseif (isset($translationsByLanguage[$language['uid']])) {
                $translation = $translationsByLanguage[$language['uid']];
                $lines[] = "- " . $isoCode . ": page " . $translation['uid'] . ' "' . $translation['title'] . '"'
                    . ' — GetPage {"uid": ' . $translation['uid'] . '} shows it with its ' . $isoCode . " content\n";
            } else {
                $lines[] = "- " . $isoCode . ': not translated — WriteTable action "translate" creates it' . "\n";
            }
        }
        return $result . "Translations:\n" . implode('', $lines);
    }

    /**
     * Language label of a record in a language view, e.g. " (EN, translates 504)"
     */
    protected function formatLanguageLabel(array $record): string
    {
        return isset($record['_languageLabel']) ? ' (' . $record['_languageLabel'] . ')' : '';
    }

    /**
     * Format content elements grouped by column position
     */
    protected function formatContentElements(array $contentInfo, int $pageId): string
    {
        $result = "Content Elements (tt_content)\n";
        $result .= "----------------------------\n";
        $result .= "Total: " . $contentInfo['total'] . " elements\n";
        if (count($contentInfo['records']) < $contentInfo['total']) {
            $result .= "(showing the first " . count($contentInfo['records'])
                . "; use ReadTable with table \"tt_content\" and pid " . $pageId . " for all)\n";
        }
        $result .= "\n";

        // Get column position definitions for this specific page
        $hasCustomLayout = false;
        $colPosDefs = RecordFormattingUtility::getColumnPositionDefinitions($pageId, $hasCustomLayout);
        
        // Determine which columns are actually defined in the backend layout
        $definedColumns = array_keys($colPosDefs);
        
        // Group content elements by column position
        $groupedElements = [];
        foreach ($contentInfo['records'] as $record) {
            $colPos = (int)($record['colPos'] ?? 0);
            if (!isset($groupedElements[$colPos])) {
                $groupedElements[$colPos] = [];
            }
            $groupedElements[$colPos][] = $record;
        }
        
        // Sort by column position
        ksort($groupedElements);
        
        // Output each column with its elements
        foreach ($groupedElements as $colPos => $elements) {
            $colPosName = $colPosDefs[$colPos] ?? 'Column ' . $colPos;
            $result .= "Column: " . $colPosName . " [colPos: " . $colPos . "] - " . count($elements) . " elements\n";
            
            // Check if this column exists in the backend layout (only warn if custom layout is in use)
            if ($hasCustomLayout && !in_array($colPos, $definedColumns)) {
                $result .= "⚠️  Note: This column is not defined in the current backend layout\n";
                $result .= "💡 Tip: Content in this column may not be visible in the frontend\n";
            }
            
            foreach ($elements as $element) {
                $title = RecordFormattingUtility::getRecordTitle('tt_content', $element);
                $cType = $element['CType'] ?? 'unknown';
                $cTypeLabel = RecordFormattingUtility::getContentTypeLabel($cType);
                $result .= "- [" . $element['uid'] . "] " . $title . $this->formatLanguageLabel($element)
                    . " (Type: " . $cTypeLabel . " [" . $cType . "])\n";
                
                // Show important fields based on content type
                switch ($cType) {
                    case 'text':
                    case 'textpic':
                    case 'textmedia':
                        if (!empty($element['bodytext'])) {
                            $bodytext = strip_tags($element['bodytext']);
                            $bodytext = mb_substr($bodytext, 0, 100) . (mb_strlen($bodytext) > 100 ? '...' : '');
                            $result .= "  Text: " . $bodytext . "\n";
                        }
                        break;
                        
                    case 'image':
                    case 'textpic':
                    case 'textmedia':
                        if (!empty($element['assets'])) {
                            $result .= "  Images: " . $element['assets'] . "\n";
                        }
                        break;
                        
                    case 'html':
                        if (!empty($element['bodytext'])) {
                            $result .= "  Contains HTML code\n";
                        }
                        break;

                    case 'list':
                        // TYPO3 13: plugins use the dedicated "list" CType plus a
                        // `list_type` subtype field. (Removed in TYPO3 14.)
                        if (!empty($element['list_type'])) {
                            $this->renderPluginInfo($result, $element, $element['list_type']);
                        }
                        break;

                    default:
                        // TYPO3 14 (and v13 plugins registered directly as CType):
                        // plugins carry their own CType. Surface plugin information
                        // whenever the element looks like a plugin (carries a
                        // FlexForm payload or an unknown CType outside the core set).
                        if (!empty($element['pi_flexform']) || $this->isPluginCType($cType)) {
                            $this->renderPluginInfo($result, $element, $cType);
                        }
                        break;
                }
            }
            
            $result .= "\n";
        }
        
        return $result;
    }
    
    /**
     * Resolve a URL to its default-language page uid and the language of the URL
     *
     * @param string $url The URL to resolve (can be full URL, path, or slug)
     * @return array{0: int, 1: int|null} Page uid and the URL's language id (null when not determined by the URL)
     * @throws \Exception If the URL cannot be resolved
     */
    protected function resolveUrlToPageUid(string $url): array
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        // Every base a site or one of its languages is reachable under; languages may
        // use their own host (e.g. "de.example.com") or scheme
        $bases = [];
        foreach ($siteFinder->getAllSites() as $site) {
            $bases[] = $site->getBase();
            foreach ($site->getAllLanguages() as $siteLanguage) {
                $bases[] = $siteLanguage->getBase();
            }
        }

        // Normalize URLs without a scheme: if the input starts with a known domain,
        // prepend https:// so parse_url extracts the host correctly.
        // This handles cases like "www.example.com" or "www.example.com/about".
        if (!str_contains($url, '://') && !str_starts_with($url, '/')) {
            foreach ($bases as $base) {
                if ($base->getHost() !== '' && str_starts_with(strtolower($url), strtolower($base->getHost()))) {
                    $url = 'https://' . $url;
                    break;
                }
            }
        }

        $parsedUrl = parse_url($url);
        $host = isset($parsedUrl['host']) ? strtolower($parsedUrl['host']) : null;

        // When a scheme+host URL has no path (e.g. "https://example.com"), treat it as home page.
        // Normalize: ensure leading slash, strip trailing slash (slugs in DB have no trailing slash)
        $path = '/' . trim($parsedUrl['path'] ?? '/', '/');

        // Request URIs to try: the URL's host (or each configured host for a bare path)
        // with the scheme and port of a base configured for it
        $candidateUris = [];
        foreach ($bases as $base) {
            $baseHost = strtolower($base->getHost());
            if ($host !== null && $baseHost !== '' && $baseHost !== $host) {
                continue;
            }
            $uri = $base->withHost($host ?? ($baseHost !== '' ? $baseHost : 'localhost'))
                ->withPath($path)
                ->withQuery('')
                ->withFragment('');
            $candidateUris[(string)$uri] ??= $uri;
        }

        // If host was specified and didn't match any site, don't try generic fallback
        if ($host !== null && $candidateUris === []) {
            throw new \RuntimeException('Could not resolve URL "' . $url . '" to a page. The domain does not match any configured site.');
        }

        $siteMatcher = GeneralUtility::makeInstance(SiteMatcher::class);
        foreach ($candidateUris as $uri) {
            try {
                // Resolve like the frontend does: the site matcher finds site and language
                // by their base (host and path prefix such as "/en/"), the site's page
                // router resolves the remaining slug
                $request = $this->createServerRequest($uri);
                $siteRouteResult = $siteMatcher->matchRequest($request);
                $site = $siteRouteResult instanceof SiteRouteResult ? $siteRouteResult->getSite() : null;
                $language = $siteRouteResult instanceof SiteRouteResult ? $siteRouteResult->getLanguage() : null;
                if (!$site instanceof Site || $language === null) {
                    continue;
                }
                // The language root ("/en/") is the root page, translated or not
                if (trim($siteRouteResult->getTail(), '/') === '') {
                    return [$site->getRootPageId(), $language->getLanguageId()];
                }
                $request = $request
                    ->withAttribute('site', $site)
                    ->withAttribute('language', $language)
                    ->withAttribute('routing', $siteRouteResult);
                $pageArguments = $site->getRouter()->matchRequest($request, $siteRouteResult);

                if ($pageArguments instanceof PageArguments) {
                    return [$pageArguments->getPageId(), $language->getLanguageId()];
                }
            } catch (\Throwable $e) {
                // Try the next candidate
                continue;
            }
        }

        // Not routable (e.g. hidden or workspace-only pages): find by slug directly in the database
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        
        // Try exact slug match
        $page = $queryBuilder->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($path))
            )
            ->executeQuery()
            ->fetchAssociative();
        
        if ($page) {
            return [(int)$page['uid'], null];
        }
        
        throw new \RuntimeException('Could not resolve URL "' . $url . '" to a page. The path does not match any page.');
    }

    /**
     * Create a server request for URL resolution
     */
    protected function createServerRequest(UriInterface $uri): \Psr\Http\Message\ServerRequestInterface
    {
        $serverParams = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri->getPath(),
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => $uri->getHost(),
            'HTTPS' => $uri->getScheme() === 'https' ? 'on' : 'off',
            'SERVER_PORT' => $uri->getPort() ?: ($uri->getScheme() === 'https' ? 443 : 80),
        ];
        
        $request = new \TYPO3\CMS\Core\Http\ServerRequest($uri, 'GET', 'php://input', [], $serverParams);
        
        // Add normalizedParams which might be needed by the router
        $normalizedParams = \TYPO3\CMS\Core\Http\NormalizedParams::createFromServerParams($serverParams);
        return $request->withAttribute('normalizedParams', $normalizedParams);
    }
    
    /**
     * Append plugin info (label, data table, FlexForm presence) to the output
     * for a given plugin identifier. The identifier is the plugin's `list_type`
     * value on TYPO3 13 (CType=list) and the plugin's own CType on v14.
     */
    protected function renderPluginInfo(string &$result, array $element, string $pluginIdentifier): void
    {
        $pluginName = $this->getPluginLabel($pluginIdentifier);
        $result .= "  Plugin: " . $pluginName . " [" . $pluginIdentifier . "]\n";

        $pluginTable = $this->getPluginDataTable($pluginIdentifier);
        if ($pluginTable) {
            $isWorkspaceCapable = $this->isTableWorkspaceCapable($pluginTable);
            if (!$isWorkspaceCapable) {
                $result .= "  ⚠️  Note: This plugin's data table (" . $pluginTable . ") is not workspace-capable\n";
                $result .= "  💡 Tip: Look for record storage folders (doktype=254) to find and edit the actual records\n";
            }
        }

        if (!empty($element['pi_flexform'])) {
            $result .= "  Has configuration (FlexForm)\n";
        }
    }

    /**
     * Get a human-readable label for a plugin identifier. Looks up the CType
     * items first; on TYPO3 13 falls back to the `list_type` items because
     * plugins are registered there.
     */
    protected function getPluginLabel(string $pluginIdentifier): string
    {
        $itemSources = [$GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] ?? []];
        if (TableAccessService::hasPluginSubtypes()) {
            $itemSources[] = $GLOBALS['TCA']['tt_content']['columns']['list_type']['config']['items'] ?? [];
        }

        foreach ($itemSources as $items) {
            foreach ($items as $item) {
                if ((isset($item['value']) && $item['value'] === $pluginIdentifier) ||
                    (isset($item[1]) && $item[1] === $pluginIdentifier)) {
                    $label = $item['label'] ?? $item[0] ?? '';
                    if ($label) {
                        return TableAccessService::translateLabel($label);
                    }
                }
            }
        }

        $parts = explode('_', $pluginIdentifier);
        if (count($parts) > 1) {
            if ($parts[0] === 'tx') {
                array_shift($parts);
            }
            return ucfirst(implode(' ', $parts));
        }

        return $pluginIdentifier;
    }

    /**
     * Detect whether a CType refers to a plugin. Driven by TCA structure
     * rather than a hardcoded core-type list so it stays correct as TYPO3
     * grows new built-in CTypes (content_blocks, etc.).
     *
     * A CType is treated as a plugin when one of the following holds:
     *   - it has a FlexForm DataStructure registered via the central
     *     `pi_flexform.config.ds` map (key `<cType>` on v14, `*,<cType>` on
     *     v13 when the plugin was registered directly as a CType), OR
     *   - the CType's sub-schema attaches a DS via `columnsOverrides`
     *     (v14 columnsOverrides path), OR
     *   - the CType item declares `group: 'plugins'` — the convention
     *     used by ExtensionUtility::registerPlugin() in v14.
     */
    protected function isPluginCType(string $cType): bool
    {
        $tcaContent = $GLOBALS['TCA']['tt_content'] ?? [];

        $dsMap = $tcaContent['columns']['pi_flexform']['config']['ds'] ?? [];
        if (is_array($dsMap)) {
            if (isset($dsMap[$cType]) || isset($dsMap['*,' . $cType])) {
                return true;
            }
        }

        if (isset($tcaContent['types'][$cType]['columnsOverrides']['pi_flexform']['config']['ds'])) {
            return true;
        }

        foreach ($tcaContent['columns']['CType']['config']['items'] ?? [] as $item) {
            $value = $item['value'] ?? $item[1] ?? null;
            if ($value !== $cType) {
                continue;
            }
            return ($item['group'] ?? null) === 'plugins';
        }

        return false;
    }
    
    /**
     * Try to determine the main data table for a plugin
     * 
     * @param string $listType
     * @return string|null
     */
    protected function getPluginDataTable(string $listType): ?string
    {
        // Extract extension key from list_type
        // Common patterns: extensionkey_pi1, tx_extensionkey_list
        $extensionKey = null;
        
        if (preg_match('/^tx_([a-z0-9]+)_/', $listType, $matches)) {
            $extensionKey = $matches[1];
        } elseif (preg_match('/^([a-z0-9]+)_pi/', $listType, $matches)) {
            $extensionKey = $matches[1];
        }
        
        if (!$extensionKey) {
            return null;
        }
        
        // Common table naming patterns
        $possibleTables = [
            'tx_' . $extensionKey . '_domain_model_' . rtrim($extensionKey, 's'), // news -> tx_news_domain_model_news
            'tx_' . $extensionKey . '_' . rtrim($extensionKey, 's'), // simpler pattern
            'tx_' . $extensionKey, // fallback
        ];
        
        // Check which tables actually exist
        foreach ($possibleTables as $table) {
            if (isset($GLOBALS['TCA'][$table])) {
                return $table;
            }
        }
        
        return null;
    }
    
    /**
     * Check if a table is workspace capable
     * 
     * @param string $table
     * @return bool
     */
    protected function isTableWorkspaceCapable(string $table): bool
    {
        return isset($GLOBALS['TCA'][$table]['ctrl']['versioningWS']) && 
               $GLOBALS['TCA'][$table]['ctrl']['versioningWS'] === true;
    }
}
