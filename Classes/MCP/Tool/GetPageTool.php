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
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\SiteInformationService;

/**
 * Tool for retrieving detailed information about a TYPO3 page
 */
class GetPageTool extends AbstractRecordTool
{
    protected SiteInformationService $siteInformationService;
    
    public function __construct(
        SiteInformationService $siteInformationService
    ) {
        parent::__construct();
        $this->siteInformationService = $siteInformationService;
    }
    /**
     * Get the tool type
     */
    public function getToolType(): string
    {
        return 'read';
    }

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        // Get available domains text dynamically
        $domainsText = $this->siteInformationService->getAvailableDomainsText();
        
        return [
            'description' => 'Get detailed information about a TYPO3 page including its records. Can fetch by page ID or URL.',
            'parameters' => [
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
                    'languageId' => [
                        'type' => 'integer',
                        'description' => 'Language ID for URL generation (default: 0)',
                    ],
                ],
                'oneOf' => [
                    ['required' => ['uid']],
                    ['required' => ['url']]
                ],
            ],
        ];
    }

    /**
     * Execute the tool
     */
    public function execute(array $params): CallToolResult
    {
        // Initialize workspace context
        $this->initializeWorkspaceContext();
        
        $languageId = (int)($params['languageId'] ?? 0);

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
            // Get page data
            $pageData = $this->getPageData($uid);
            
            // Get page URL using SiteInformationService
            $pageUrl = $this->siteInformationService->generatePageUrl((int)$pageData['uid'], $languageId);
            
            // Get records on this page
            $recordsInfo = $this->getPageRecords($uid);
            
            // Build a text representation of the page information
            $result = $this->formatPageInfo($pageData, $recordsInfo, $pageUrl);
            
            return new CallToolResult([new TextContent($result)]);
        } catch (\Throwable $e) {
            return new CallToolResult(
                [new TextContent('Error retrieving page information: ' . $e->getMessage())],
                true // isError
            );
        }
    }

    /**
     * Get basic page data
     */
    protected function getPageData(int $uid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $page = $queryBuilder->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$page) {
            throw new \RuntimeException('Page not found: ' . $uid);
        }

        // Convert some values to their proper types
        if (is_array($page)) {
            $page['uid'] = (int)$page['uid'];
            $page['pid'] = (int)$page['pid'];
            $page['hidden'] = (bool)$page['hidden'];
            $page['deleted'] = (bool)$page['deleted'];
        }

        return $page;
    }


    /**
     * Get records on the page grouped by table
     */
    protected function getPageRecords(int $pageId): array
    {
        // Get all tables that can be on a page
        $tables = $this->getContentTables();
        
        $recordsInfo = [];
        
        foreach ($tables as $table) {
            $tableInfo = $this->getTableRecordsInfo($table, $pageId);
            
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
     * Format page information as readable text
     */
    protected function formatPageInfo(array $pageData, array $recordsInfo, ?string $pageUrl = null): string
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
        
        $result .= "Parent Page (PID): " . $pageData['pid'] . "\n";
        $result .= "Doktype: " . $pageData['doktype'] . "\n";
        $result .= "Hidden: " . ($pageData['hidden'] ? 'Yes' : 'No') . "\n";
        $result .= "Created: " . date('Y-m-d H:i:s', (int)$pageData['crdate']) . "\n";
        $result .= "Last Modified: " . date('Y-m-d H:i:s', (int)$pageData['tstamp']) . "\n\n";
        
        // Records on the page
        $result .= "RECORDS ON THIS PAGE\n";
        $result .= "===================\n\n";
        
        // Handle tt_content specially - group by column position
        if (isset($recordsInfo['tt_content'])) {
            $result .= $this->formatContentElements($recordsInfo['tt_content']);
            // Remove tt_content from the recordsInfo so we don't process it again below
            unset($recordsInfo['tt_content']);
        }
        
        // Process other tables
        foreach ($recordsInfo as $table => $tableInfo) {
            $tableLabel = $table;
            if (!empty($GLOBALS['TCA'][$table]['ctrl']['title'])) {
                $tableLabel = $this->translateLabel($GLOBALS['TCA'][$table]['ctrl']['title']);
            }
            $totalCount = $tableInfo['total'];
            $records = $tableInfo['records'];
            $displayCount = count($records);
            $result .= "Table: " . $tableLabel . " (" . $table . ") - " . $totalCount . " total records\n";
            
            if ($displayCount > 0) {
                foreach ($records as $record) {
                    $title = $this->getRecordTitle($table, $record);
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
    protected function formatContentElements(array $contentInfo): string
    {
        $result = "Content Elements (tt_content)\n";
        $result .= "----------------------------\n";
        $result .= "Total: " . $contentInfo['total'] . " elements\n\n";
        
        // Get column position definitions
        $colPosDefs = $this->getColumnPositionDefinitions();
        
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
            
            foreach ($elements as $element) {
                $title = $this->getRecordTitle('tt_content', $element);
                $cType = $element['CType'] ?? 'unknown';
                $cTypeLabel = $this->getContentTypeLabel($cType);
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
                }
            }
            
            $result .= "\n";
        }
        
        return $result;
    }
    
    /**
     * Get column position definitions
     */
    protected function getColumnPositionDefinitions(): array
    {
        // Default column positions
        $colPosDefs = [
            0 => 'Main Content',
            1 => 'Left',
            2 => 'Right',
            3 => 'Border',
            4 => 'Footer',
        ];
        
        // Try to get column positions from page TSconfig
        if (isset($GLOBALS['TYPO3_CONF_VARS']['BE']['defaultPageTSconfig'])) {
            $tsconfigString = $GLOBALS['TYPO3_CONF_VARS']['BE']['defaultPageTSconfig'];
            if (preg_match_all('/mod\.wizards\.newContentElement\.wizardItems\..*?\.elements\..*?\.tt_content_defValues\.colPos\s*=\s*(\d+)/', $tsconfigString, $matches)) {
                foreach ($matches[1] as $colPos) {
                    if (!isset($colPosDefs[$colPos])) {
                        // Try to find the label for this column position
                        if (preg_match('/mod\.wizards\.newContentElement\.wizardItems\..*?\.elements\..*?\.title\s*=\s*(.+)/', $tsconfigString, $labelMatches)) {
                            $colPosDefs[$colPos] = $labelMatches[1];
                        } else {
                            $colPosDefs[$colPos] = 'Column ' . $colPos;
                        }
                    }
                }
            }
        }
        
        // Check for backend layouts
        if (isset($GLOBALS['TCA']['backend_layout']['columns']['config']['config']['items'])) {
            $items = $GLOBALS['TCA']['backend_layout']['columns']['config']['config']['items'];
            foreach ($items as $item) {
                if (is_array($item) && isset($item[1]) && preg_match('/colPos=(\d+)/', $item[1], $matches)) {
                    $colPos = (int)$matches[1];
                    if (!isset($colPosDefs[$colPos])) {
                        $colPosDefs[$colPos] = $this->translateLabel($item[0]);
                    }
                }
            }
        }
        
        return $colPosDefs;
    }
    
    /**
     * Get a label for a content type
     */
    protected function getContentTypeLabel(string $cType): string
    {
        if (isset($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'])) {
            $items = $GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'];
            foreach ($items as $item) {
                // Handle both old and new TCA item formats
                if (is_array($item) && isset($item['value']) && $item['value'] === $cType) {
                    return $this->translateLabel($item['label']);
                } elseif (is_array($item) && isset($item[1]) && $item[1] === $cType) {
                    return $this->translateLabel($item[0]);
                }
            }
        }
        
        // Fallback to a humanized version of the CType
        return ucfirst(str_replace('_', ' ', $cType));
    }
    
    /**
     * Get a meaningful title for a record
     */
    protected function getRecordTitle(string $table, array $record): string
    {
        // Try to use BackendUtility to get a proper record title
        try {
            $title = BackendUtility::getRecordTitle($table, $record);
            if (!empty($title)) {
                return $title;
            }
        } catch (\Throwable $e) {
            // Fall back to manual title detection
        }
        
        // Use the TCA label field if defined
        if (isset($GLOBALS['TCA'][$table]['ctrl']['label']) && !empty($record[$GLOBALS['TCA'][$table]['ctrl']['label']])) {
            return $record[$GLOBALS['TCA'][$table]['ctrl']['label']];
        }
        
        // Common title fields in TYPO3
        $titleFields = ['title', 'header', 'name', 'username', 'first_name', 'lastname', 'subject'];
        
        foreach ($titleFields as $field) {
            if (!empty($record[$field])) {
                return $record[$field];
            }
        }
        
        // Last resort, just return the UID
        return 'Record #' . $record['uid'];
    }
    
    /**
     * Translate a label if it's in LLL format
     */
    protected function translateLabel(string $label): string
    {
        // Check if the label is a language reference (LLL:)
        if (strpos($label, 'LLL:') === 0) {
            // Initialize language service if needed
            if (!isset($GLOBALS['LANG']) || !$GLOBALS['LANG'] instanceof LanguageService) {
                $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
                $GLOBALS['LANG'] = $languageServiceFactory->create('default');
            }
            
            // Translate the label
            return $GLOBALS['LANG']->sL($label);
        }
        
        return $label;
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
}
