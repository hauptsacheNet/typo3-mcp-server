<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\Entity\Site;
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
        $schema = [
            'description' => 'Get the TYPO3 page tree structure as a readable text tree.Essential for understanding page hierarchy before creating new pages, finding pages by their position, and verifying parent-child relationships.',
            'parameters' => [
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
            $schema['parameters']['properties']['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code to show translated page titles (e.g., "de", "fr"). Shows translation status for each page.',
                'enum' => $availableLanguages,
            ];
        }

        return $schema;
    }

    /**
     * Execute the tool
     */
    public function execute(array $params): CallToolResult
    {
        // Initialize workspace context
        $this->initializeWorkspaceContext();
        
        $startPage = (int)($params['startPage'] ?? 0);
        $depth = (int)($params['depth'] ?? 3);
        $languageUid = null;

        // Handle language parameter if provided
        if (isset($params['language'])) {
            $languageUid = $this->languageService->getUidFromIsoCode($params['language']);
            if ($languageUid === null) {
                return new CallToolResult(
                    [new TextContent('Unknown language code: ' . $params['language'])],
                    true // isError
                );
            }
        }

        // Get page tree with the specified parameters
        $pageTree = $this->getPageTree($startPage, $depth, $languageUid);
        
        // Convert the page tree to a text-based tree with indentation
        $textTree = $this->renderTextTree($pageTree, 0, $languageUid);
        
        return new CallToolResult([new TextContent($textTree)]);
    }

    /**
     * Get the page tree
     */
    protected function getPageTree(int $startPage, int $depth, ?int $languageUid = null): array
    {
        // Get database connection for pages table
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        // Only apply the DeletedRestriction to filter out deleted pages
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        // Build the query
        $query = $queryBuilder->select('*')
            ->from('pages');

        // Filter by pid (parent ID) for the starting page
        if ($startPage === 0) {
            // Root level pages have pid=0
            $query->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            );
        } else {
            // Get subpages of the specified page
            $query->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($startPage, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            );
        }

        // Order by sorting
        $query->orderBy('sorting');

        // Execute the query
        $pages = $query->executeQuery()->fetchAllAssociative();

        // Set up context for language and visibility
        $context = GeneralUtility::makeInstance(Context::class);

        // Set up language aspect if needed
        if ($languageUid !== null && $languageUid > 0) {
            $languageAspect = new LanguageAspect(
                $languageUid,
                $languageUid,
                LanguageAspect::OVERLAYS_MIXED,
                [$languageUid]
            );
            $context->setAspect('language', $languageAspect);
        }

        // Create PageRepository with context
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);

        // Process the result
        $pageTree = [];
        foreach ($pages as $page) {
            $pageData = [
                'uid' => (int)$page['uid'],
                'pid' => (int)$page['pid'],
                'title' => $page['title'],
                'nav_title' => $page['nav_title'],
                'hidden' => (bool)$page['hidden'],
                'doktype' => (int)$page['doktype'],
                'subpageCount' => 0,
                'url' => $this->siteInformationService->generatePageUrl((int)$page['uid']),
            ];

            // Get language overlay if language specified
            if ($languageUid !== null && $languageUid > 0) {
                $overlaidPage = $pageRepository->getPageOverlay($page, $languageUid);

                if ($overlaidPage !== $page) {
                    // Apply overlay data
                    $pageData['title'] = $overlaidPage['title'] ?: $pageData['title'];
                    $pageData['nav_title'] = $overlaidPage['nav_title'] ?: $pageData['nav_title'];
                    $pageData['hidden'] = (bool)$overlaidPage['hidden'];
                    $pageData['_translated'] = true;
                } else {
                    $pageData['_translated'] = false;
                }
            }

            // Check if there are subpages if depth > 1
            if ($depth > 1) {
                $subpages = $this->getPageTree((int)$page['uid'], $depth - 1, $languageUid);
                $pageData['subpages'] = $subpages;
                $pageData['subpageCount'] = count($subpages);
            } else {
                // We're at max depth, count the number of subpages
                $pageData['subpageCount'] = $this->countSubpages((int)$page['uid']);
            }

            $pageTree[] = $pageData;
        }

        return $pageTree;
    }
    
    /**
     * Count the number of subpages for a page
     */
    protected function countSubpages(int $pageId): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $query = $queryBuilder->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            );

        return (int)$query->executeQuery()->fetchOne();
    }
    

    /**
     * Render the page tree as a text-based tree with indentation
     */
    protected function renderTextTree(array $pageTree, int $level = 0, ?int $languageUid = null): string
    {
        $result = '';
        $indent = str_repeat('  ', $level);
        
        foreach ($pageTree as $page) {
            $title = $page['nav_title'] ?: $page['title'];
            $hiddenMark = $page['hidden'] ? ' [HIDDEN]' : '';
            
            $result .= $indent . '- [' . $page['uid'] . '] ' . $title . $hiddenMark;
            
            // Add translation status if language specified
            if ($languageUid !== null && $languageUid > 0) {
                if (isset($page['_translated'])) {
                    $result .= $page['_translated'] ? ' [TRANSLATED]' : ' [NOT TRANSLATED]';
                }
            }

            // Add URL if available
            if (!empty($page['url'])) {
                $result .= ' - ' . $page['url'];
            }
            
            // If the page has subpages but we've reached max depth, show the count
            if (empty($page['subpages']) && $page['subpageCount'] > 0) {
                $result .= ' (' . $page['subpageCount'] . ' subpages)';
            }
            
            $result .= PHP_EOL;
            
            if (!empty($page['subpages'])) {
                $result .= $this->renderTextTree($page['subpages'], $level + 1, $languageUid);
            }
        }
        
        return $result;
    }

}
