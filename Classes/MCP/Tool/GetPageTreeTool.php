<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for retrieving the TYPO3 page tree
 */
class GetPageTreeTool extends AbstractTool
{
    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Get the TYPO3 page tree structure as a readable text tree. ',
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
                    'includeHidden' => [
                        'type' => 'boolean',
                        'description' => 'Whether to include hidden pages (default: false)',
                    ],
                ],
                'required' => ['startPage'],
            ],
        ];
    }

    /**
     * Execute the tool
     */
    public function execute(array $params): CallToolResult
    {
        $startPage = (int)($params['startPage'] ?? 0);
        $depth = (int)($params['depth'] ?? 3);
        $includeHidden = (bool)($params['includeHidden'] ?? false);

        // Get page tree with the specified parameters
        $pageTree = $this->getPageTree($startPage, $depth, $includeHidden);
        
        // Convert the page tree to a text-based tree with indentation
        $textTree = $this->renderTextTree($pageTree);
        
        return new CallToolResult([new TextContent($textTree)]);
    }

    /**
     * Get the page tree
     */
    protected function getPageTree(int $startPage, int $depth, bool $includeHidden): array
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
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            );
        } else {
            // Get subpages of the specified page
            $query->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($startPage, ParameterType::INTEGER))
            );
        }

        // Only include non-hidden pages if includeHidden is false
        if (!$includeHidden) {
            $query->andWhere(
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            );
        }

        // Order by sorting
        $query->orderBy('sorting');

        // Execute the query
        $pages = $query->executeQuery()->fetchAllAssociative();

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
            ];

            // Check if there are subpages if depth > 1
            if ($depth > 1) {
                $subpages = $this->getPageTree((int)$page['uid'], $depth - 1, $includeHidden);
                $pageData['subpages'] = $subpages;
                $pageData['subpageCount'] = count($subpages);
            } else {
                // We're at max depth, count the number of subpages
                $pageData['subpageCount'] = $this->countSubpages((int)$page['uid'], $includeHidden);
            }

            $pageTree[] = $pageData;
        }

        return $pageTree;
    }
    
    /**
     * Count the number of subpages for a page
     */
    protected function countSubpages(int $pageId, bool $includeHidden): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $query = $queryBuilder->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT))
            );

        if (!$includeHidden) {
            $query->andWhere(
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            );
        }

        return (int)$query->executeQuery()->fetchOne();
    }
    
    /**
     * Render the page tree as a text-based tree with indentation
     */
    protected function renderTextTree(array $pageTree, int $level = 0): string
    {
        $result = '';
        $indent = str_repeat('  ', $level);
        
        foreach ($pageTree as $page) {
            $title = $page['nav_title'] ?: $page['title'];
            $hiddenMark = $page['hidden'] ? ' [HIDDEN]' : '';
            
            $result .= $indent . '- [' . $page['uid'] . '] ' . $title . $hiddenMark;
            
            // If the page has subpages but we've reached max depth, show the count
            if (empty($page['subpages']) && $page['subpageCount'] > 0) {
                $result .= ' (' . $page['subpageCount'] . ' subpages)';
            }
            
            $result .= PHP_EOL;
            
            if (!empty($page['subpages'])) {
                $result .= $this->renderTextTree($page['subpages'], $level + 1);
            }
        }
        
        return $result;
    }
}
