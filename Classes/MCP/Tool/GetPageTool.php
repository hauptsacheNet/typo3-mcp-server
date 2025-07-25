<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\LanguageService as McpLanguageService;
use Hn\McpServer\Utility\RecordFormattingUtility;
use Hn\McpServer\Service\TableAccessService;

/**
 * Tool for retrieving detailed information about a TYPO3 page
 */
class GetPageTool extends AbstractRecordTool
{
    protected SiteInformationService $siteInformationService;
    protected McpLanguageService $languageService;
    
    public function __construct(
        SiteInformationService $siteInformationService,
        McpLanguageService $languageService
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
        // Get available domains text dynamically
        $domainsText = $this->siteInformationService->getAvailableDomainsText();
        
        $schema = [
            'description' => 'Get detailed information about a TYPO3 page including its records. Can fetch by page ID or URL. Shows content in the specified language when available.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The page ID to retrieve information for',
                    ],
                    'url' => [
                        'type' => 'string',
                        'description' => 'The URL of the page to retrieve (alternative to uid). Can be full URL, path, or slug. ' . $domainsText,
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
                'description' => 'Language ISO code to show page and content in specific language (e.g., "de", "fr"). Shows translated content and metadata when available.',
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
     * Execute the tool
     */
    public function execute(array $params): CallToolResult
    {
        // Initialize workspace context
        $this->initializeWorkspaceContext();
        
        // Handle language parameter
        $languageId = 0;
        if (isset($params['language'])) {
            // Convert ISO code to language UID
            $languageId = $this->languageService->getUidFromIsoCode($params['language']);
            if ($languageId === null) {
                return new CallToolResult(
                    [new TextContent('Unknown language code: ' . $params['language'])],
                    true // isError
                );
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
                $uid = $this->resolveUrlToPageUid($params['url'], $languageId);
            } catch (\Throwable $e) {
                return new CallToolResult(
                    [new TextContent('Could not resolve URL to page: ' . $e->getMessage())],
                    true // isError
                );
            }
        }

        if ($uid <= 0) {
            return new CallToolResult(
                [new TextContent('Invalid page UID or URL. Please provide a valid page ID or URL.')],
                true // isError
            );
        }

        try {
            // Get page data (with language overlay if applicable)
            $pageData = $this->getPageData($uid, $languageId);
            
            // Get page URL using SiteInformationService
            $pageUrl = $this->siteInformationService->generatePageUrl((int)$pageData['uid'], $languageId);
            
            // Get records on this page (filtered by language if specified)
            $recordsInfo = $this->getPageRecords($uid, $languageId);
            
            // Get available translations for this page
            $translations = $this->getPageTranslations($uid);
            
            // Build a text representation of the page information
            $result = $this->formatPageInfo($pageData, $recordsInfo, $pageUrl, $languageId, $translations);
            
            return new CallToolResult([new TextContent($result)]);
        } catch (\Throwable $e) {
            return new CallToolResult(
                [new TextContent('Error retrieving page information: ' . $e->getMessage())],
                true // isError
            );
        }
    }

    /**
     * Get basic page data with language overlay if applicable
     */
    protected function getPageData(int $uid, int $languageId = 0): array
    {
        // Create a context with the specified language
        $context = GeneralUtility::makeInstance(Context::class);
        
        // Set up language aspect if language is specified
        if ($languageId > 0) {
            $languageAspect = new LanguageAspect(
                $languageId,
                $languageId,
                LanguageAspect::OVERLAYS_MIXED,
                [$languageId]
            );
            $context->setAspect('language', $languageAspect);
        }
        
        // Create PageRepository with context
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);
        
        // Get the page - PageRepository handles workspace and deleted restrictions
        $page = $pageRepository->getPage($uid);
        
        if (!$page) {
            throw new \RuntimeException('Page not found: ' . $uid);
        }
        
        // Apply language overlay if language is specified
        if ($languageId > 0) {
            $overlaidPage = $pageRepository->getPageOverlay($page, $languageId);
            
            // Add our custom metadata
            if ($overlaidPage !== $page) {
                // Page was overlaid
                $overlaidPage['_translated'] = true;
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
        
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        
        $translations = $queryBuilder->select('sys_language_uid', 'title')
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
                $result[] = [
                    'languageId' => $languageId,
                    'isoCode' => $isoCode,
                    'title' => $translation['title'],
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
        
        $recordsInfo = [];
        
        foreach ($tables as $table) {
            $tableInfo = $this->getTableRecordsInfo($table, $pageId);
            
            if (!empty($tableInfo['records'])) {
                // Filter records by language for tables that have language support
                if ($this->tableHasLanguageSupport($table)) {
                    $tableInfo = $this->filterRecordsByLanguage($tableInfo, $languageId);
                }
                if (!empty($tableInfo['records'])) {
                    $recordsInfo[$table] = $tableInfo;
                }
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
    protected function getTableRecordsInfo(string $table, int $pageId): array
    {
        // Skip if table is not accessible
        if (!$this->tableAccessService->canAccessTable($table)) {
            return [
                'total' => 0,
                'records' => []
            ];
        }
        
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        
        // Always include hidden records (like the TYPO3 backend does)

        // First, get the total count of records
        $countQueryBuilder = clone $queryBuilder;
        $totalCount = $countQueryBuilder->count('*')
            ->from($table)
            ->where(
                $countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pageId, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();
            
        // Now get the limited records
        $query = $queryBuilder->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER))
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

        return [
            'total' => (int)$totalCount,
            'records' => $query->executeQuery()->fetchAllAssociative()
        ];
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
     * Filter records by language
     */
    protected function filterRecordsByLanguage(array $tableInfo, int $languageId): array
    {
        $filteredRecords = [];
        
        foreach ($tableInfo['records'] as $record) {
            $recordLang = (int)($record['sys_language_uid'] ?? 0);
            
            if ($languageId === 0) {
                // Default language: only show records with sys_language_uid = 0
                if ($recordLang === 0 || $recordLang === -1) {
                    $filteredRecords[] = $record;
                }
            } else {
                // Specific language: show records in that language or default language
                if ($recordLang === $languageId || $recordLang === 0 || $recordLang === -1) {
                    $filteredRecords[] = $record;
                }
            }
        }
        
        return [
            'total' => count($filteredRecords),
            'records' => $filteredRecords,
        ];
    }
    
    /**
     * Format page information as readable text
     */
    protected function formatPageInfo(array $pageData, array $recordsInfo, ?string $pageUrl = null, int $languageId = 0, array $translations = []): string
    {
        $result = "PAGE INFORMATION\n";
        $result .= "================\n\n";
        
        // Basic page info
        $result .= "UID: " . $pageData['uid'] . "\n";
        $result .= "Title: " . $pageData['title'] . "\n";
        
        if ($pageUrl !== null) {
            $result .= "URL: " . $pageUrl . "\n";
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
            $isoCode = $this->languageService->getIsoCodeFromUid($languageId) ?? 'unknown';
            $result .= "Language: " . strtoupper($isoCode) . " (ID: $languageId)\n";
            $result .= "Translated: " . (($pageData['_translated'] ?? false) ? 'Yes' : 'No') . "\n";
        }
        
        // Show available translations
        if (!empty($translations)) {
            $result .= "Available Translations: ";
            $translationList = [];
            foreach ($translations as $translation) {
                $translationList[] = strtoupper($translation['isoCode']);
            }
            $result .= implode(', ', $translationList) . "\n";
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
                    $result .= "- [" . $record['uid'] . "] " . $title . "\n";
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
     * Format content elements grouped by column position
     */
    protected function formatContentElements(array $contentInfo, int $pageId): string
    {
        $result = "Content Elements (tt_content)\n";
        $result .= "----------------------------\n";
        $result .= "Total: " . $contentInfo['total'] . " elements\n\n";
        
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
                $result .= "- [" . $element['uid'] . "] " . $title . " (Type: " . $cTypeLabel . " [" . $cType . "])\n";
                
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
                        // Show plugin information
                        if (!empty($element['list_type'])) {
                            $pluginName = $this->getPluginLabel($element['list_type']);
                            $result .= "  Plugin: " . $pluginName . " [" . $element['list_type'] . "]\n";
                            
                            // Check if the plugin's table is workspace capable
                            $pluginTable = $this->getPluginDataTable($element['list_type']);
                            if ($pluginTable) {
                                $isWorkspaceCapable = $this->isTableWorkspaceCapable($pluginTable);
                                if (!$isWorkspaceCapable) {
                                    $result .= "  ⚠️  Note: This plugin's data table (" . $pluginTable . ") is not workspace-capable\n";
                                    $result .= "  💡 Tip: Look for record storage folders (doktype=254) to find and edit the actual records\n";
                                }
                            }
                            
                            // Show flexform config if available
                            if (!empty($element['pi_flexform'])) {
                                $result .= "  Has configuration (FlexForm)\n";
                            }
                        }
                        break;
                }
            }
            
            $result .= "\n";
        }
        
        return $result;
    }
    
    /**
     * Resolve a URL to a page UID
     * 
     * @param string $url The URL to resolve (can be full URL, path, or slug)
     * @param int $languageId The language ID to use for resolution
     * @return int The resolved page UID
     * @throws \Exception If the URL cannot be resolved
     */
    protected function resolveUrlToPageUid(string $url, int $languageId = 0): int
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        
        // Try to parse as full URL first
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? $url;
        
        // Normalize the path - ensure it starts with /
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        
        // Special handling for home page
        if ($path === '/') {
            // Try to find the root page from any site
            foreach ($siteFinder->getAllSites() as $site) {
                // Check if this URL belongs to this site (if host is specified)
                if (isset($parsedUrl['host'])) {
                    $siteHost = $site->getBase()->getHost();
                    // If site has no host (base is just "/"), skip host check
                    if (!empty($siteHost) && $siteHost !== $parsedUrl['host']) {
                        continue;
                    }
                }
                return $site->getRootPageId();
            }
        }
        
        // Try each site to find a match using the router
        $allSites = $siteFinder->getAllSites();
        $matchedAnySite = false;
        
        foreach ($allSites as $site) {
            try {
                // Check if this URL belongs to this site (if host is specified)
                if (isset($parsedUrl['host'])) {
                    $siteHost = $site->getBase()->getHost();
                    // If site has no host (base is just "/"), skip host check
                    if (!empty($siteHost) && $siteHost !== $parsedUrl['host']) {
                        continue;
                    }
                    $matchedAnySite = true;
                }
                
                // Try to resolve the path/slug using the site's router
                $router = $site->getRouter();
                $request = $this->createServerRequest($site, $path, $languageId);
                $pageArguments = $router->matchRequest($request);
                
                if ($pageArguments instanceof PageArguments) {
                    return $pageArguments->getPageId();
                }
            } catch (\Throwable $e) {
                // Continue to next site
                continue;
            }
        }
        
        // If host was specified and didn't match any site, don't try generic fallback
        if (isset($parsedUrl['host']) && !$matchedAnySite) {
            throw new \RuntimeException('Could not resolve URL "' . $url . '" to a page. The domain does not match any configured site.');
        }
        
        // If no match found via router AND no host was specified, try to find by slug directly in the database
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
            return (int)$page['uid'];
        }
        
        throw new \RuntimeException('Could not resolve URL "' . $url . '" to a page. The path does not match any page.');
    }

    /**
     * Create a server request for URL resolution
     */
    protected function createServerRequest(Site $site, string $path, int $languageId): \Psr\Http\Message\ServerRequestInterface
    {
        // Ensure path starts with /
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        
        // Create URI - don't double the slash
        $baseUri = $site->getBase();
        $uri = $baseUri->withPath($path);
        
        // Create request with proper server variables
        $serverParams = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => $baseUri->getHost() ?: 'localhost',
            'HTTPS' => $baseUri->getScheme() === 'https' ? 'on' : 'off',
            'SERVER_PORT' => $baseUri->getPort() ?: ($baseUri->getScheme() === 'https' ? 443 : 80),
        ];
        
        $request = new \TYPO3\CMS\Core\Http\ServerRequest($uri, 'GET', 'php://input', [], $serverParams);
        $request = $request->withAttribute('site', $site);
        
        // Set language attribute
        try {
            $language = $languageId > 0 ? $site->getLanguageById($languageId) : $site->getDefaultLanguage();
            $request = $request->withAttribute('language', $language);
        } catch (\Throwable $e) {
            // If language not found, use default
            $request = $request->withAttribute('language', $site->getDefaultLanguage());
        }
        
        // Add normalizedParams which might be needed by the router
        $normalizedParams = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Http\NormalizedParams::class,
            $serverParams
        );
        $request = $request->withAttribute('normalizedParams', $normalizedParams);
        
        return $request;
    }
    
    /**
     * Get a human-readable label for a plugin list_type
     * 
     * @param string $listType
     * @return string
     */
    protected function getPluginLabel(string $listType): string
    {
        // Check TCA for plugin label
        if (isset($GLOBALS['TCA']['tt_content']['columns']['list_type']['config']['items'])) {
            foreach ($GLOBALS['TCA']['tt_content']['columns']['list_type']['config']['items'] as $item) {
                // Handle both old and new TCA formats
                if ((isset($item['value']) && $item['value'] === $listType) ||
                    (isset($item[1]) && $item[1] === $listType)) {
                    $label = $item['label'] ?? $item[0] ?? '';
                    if ($label) {
                        return TableAccessService::translateLabel($label);
                    }
                }
            }
        }
        
        // Fallback: humanize the list_type
        $parts = explode('_', $listType);
        if (count($parts) > 1) {
            // Remove common prefixes like 'tx_'
            if ($parts[0] === 'tx') {
                array_shift($parts);
            }
            return ucfirst(implode(' ', $parts));
        }
        
        return $listType;
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
