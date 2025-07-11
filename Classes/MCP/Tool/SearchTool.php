<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Hn\McpServer\Utility\RecordFormattingUtility;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Tool for searching records across TYPO3 tables using TCA-based searchable fields
 */
class SearchTool extends AbstractRecordTool
{
    /**
     * Get the tool type
     */
    public function getToolType(): string
    {
        return 'search';
    }

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        //$languageRecommendations = $this->getLanguageRecommendations();
        
        return [
            'description' => "Search for records across workspace-capable TYPO3 tables using TCA-based searchable fields. " .
                "Uses SQL LIKE queries for pattern matching.\n\n",
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'terms' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Search terms to find in record content. Use multiple search terms with synonyms for best results.',
                    ],
                    'termLogic' => [
                        'type' => 'string',
                        'enum' => ['AND', 'OR'],
                        'description' => 'Logic for combining multiple terms: AND (all terms must match) or OR (any term matches). Default: OR',
                        'default' => 'OR',
                    ],
                    'table' => [
                        'type' => 'string',
                        'description' => 'Optional: Limit search to a specific workspace-capable table (e.g., "tt_content", "pages")',
                    ],
                    'pageId' => [
                        'type' => 'integer',
                        'description' => 'Optional: Limit search to records on a specific page',
                    ],
                    'includeHidden' => [
                        'type' => 'boolean',
                        'description' => 'Whether to include hidden records (default: false)',
                    ],
                ],
                'required' => ['terms'],
            ],
            'examples' => [
                [
                    'description' => 'Search for single term across all tables',
                    'parameters' => [
                        'terms' => ['welcome']
                    ]
                ],
                [
                    'description' => 'Search for multiple terms with OR logic',
                    'parameters' => [
                        'terms' => ['news', 'article', 'blog'],
                        'termLogic' => 'OR'
                    ]
                ],
                [
                    'description' => 'Search for multiple terms that must all match',
                    'parameters' => [
                        'terms' => ['TYPO3', 'extension'],
                        'termLogic' => 'AND'
                    ]
                ],
                [
                    'description' => 'Search only in content elements',
                    'parameters' => [
                        'terms' => ['contact'],
                        'table' => 'tt_content'
                    ]
                ],
                [
                    'description' => 'Search on specific page with multiple terms',
                    'parameters' => [
                        'terms' => ['contact', 'form'],
                        'pageId' => 123,
                        'termLogic' => 'AND'
                    ]
                ]
            ]
        ];
    }

    /**
     * Get language recommendations based on site configuration
     */
    protected function getLanguageRecommendations(): string
    {
        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $sites = $siteFinder->getAllSites();
            
            $languages = [];
            foreach ($sites as $site) {
                foreach ($site->getAllLanguages() as $language) {
                    $languages[$language->getLanguageId()] = [
                        'title' => $language->getTitle(),
                        'locale' => $language->getLocale()->getName(),
                        'iso' => $language->getLocale()->getLanguageCode(),
                    ];
                }
            }
            
            if (count($languages) <= 1) {
                return "LANGUAGES:\n• Single language site detected\n\n";
            }
            
            $recommendation = "MULTILINGUAL SEARCH:\n";
            $recommendation .= "• This site has " . count($languages) . " languages configured:\n";
            
            foreach ($languages as $langId => $langInfo) {
                $recommendation .= "  - {$langInfo['title']} ({$langInfo['iso']})";
                if ($langId === 0) {
                    $recommendation .= " [Default]";
                }
                $recommendation .= "\n";
            }
            
            $recommendation .= "• Search terms should match the content language\n";
            $recommendation .= "• Try terms in different languages for broader results\n";
            $recommendation .= "• Language-specific content may be on different pages\n\n";
            
            return $recommendation;
            
        } catch (\Throwable $e) {
            return "LANGUAGES:\n• Could not detect language configuration\n• Try search terms in your site's primary language\n\n";
        }
    }

    /**
     * Execute the tool
     */
    public function execute(array $params): CallToolResult
    {
        $terms = $params['terms'] ?? [];
        $termLogic = strtoupper($params['termLogic'] ?? 'OR');
        $table = trim($params['table'] ?? '');
        $pageId = isset($params['pageId']) ? (int)$params['pageId'] : null;
        $includeHidden = (bool)($params['includeHidden'] ?? false);
        $limit = 50;

        // Validate search parameters
        $searchTerms = $this->validateAndNormalizeSearchTerms($terms);
        if (is_string($searchTerms)) {
            // Error message returned
            return new CallToolResult(
                [new TextContent($searchTerms)],
                true // isError
            );
        }

        // Validate term logic
        if (!in_array($termLogic, ['AND', 'OR'])) {
            return new CallToolResult(
                [new TextContent('termLogic must be either "AND" or "OR".')],
                true // isError
            );
        }

        try {
            // Get search results
            $searchResults = $this->performSearch($searchTerms, $termLogic, $table, $pageId, $includeHidden, $limit);
            
            // Format results
            $formattedResults = $this->formatSearchResults($searchResults, $searchTerms, $termLogic);
            
            return new CallToolResult([new TextContent($formattedResults)]);
        } catch (\Throwable $e) {
            return new CallToolResult(
                [new TextContent('Error performing search: ' . $e->getMessage())],
                true // isError
            );
        }
    }

    /**
     * Validate and normalize search terms
     */
    protected function validateAndNormalizeSearchTerms(array $terms): array|string
    {
        $searchTerms = [];
        
        // Handle terms array parameter
        if (!is_array($terms)) {
            return 'Parameter "terms" must be an array of strings.';
        }
        
        if (empty($terms)) {
            return 'At least one search term is required in the "terms" array.';
        }
        
        foreach ($terms as $term) {
            if (!is_string($term)) {
                return 'All terms must be strings.';
            }
            $trimmedTerm = trim($term);
            if (!empty($trimmedTerm)) {
                $searchTerms[] = $trimmedTerm;
            }
        }
        
        // Validate we have at least one term
        if (empty($searchTerms)) {
            return 'At least one non-empty search term is required.';
        }
        
        // Validate term lengths
        foreach ($searchTerms as $term) {
            if (strlen($term) < 2) {
                return 'All search terms must be at least 2 characters long. Term "' . $term . '" is too short.';
            }
            if (strlen($term) > 100) {
                return 'Search terms cannot exceed 100 characters. Term "' . substr($term, 0, 20) . '..." is too long.';
            }
        }
        
        return $searchTerms;
    }

    /**
     * Perform search across tables (including inline relations)
     */
    protected function performSearch(array $searchTerms, string $termLogic, string $table, ?int $pageId, bool $includeHidden, int $limit): array
    {
        $searchResults = [];
        $inlineTableMetadata = [];
        
        // Get primary tables to search (accessible tables with searchable fields)
        $primaryTables = $this->getTablesToSearch($table);
        
        // Discover related tables referenced by primary tables (inline/select relations)
        $inlineTableInfo = $this->getInlineRelatedHiddenTables($primaryTables);
        
        // Create lookup for inline table metadata
        foreach ($inlineTableInfo as $inlineInfo) {
            $inlineTableMetadata[$inlineInfo['table']] = $inlineInfo;
        }
        
        // Combine primary tables with inline tables for searching
        $allTablesToSearch = array_merge($primaryTables, array_column($inlineTableInfo, 'table'));
        
        foreach ($allTablesToSearch as $tableName) {
            $searchableFields = $this->getSearchableFields($tableName);
            
            if (empty($searchableFields)) {
                continue;
            }
            
            $results = $this->searchInTable($tableName, $searchTerms, $termLogic, $searchableFields, $pageId, $includeHidden, $limit);
            
            if (!empty($results)) {
                // Mark inline table results for attribution
                if (isset($inlineTableMetadata[$tableName])) {
                    $results['_inline_metadata'] = $inlineTableMetadata[$tableName];
                }
                
                $searchResults[$tableName] = $results;
            }
        }
        
        // Attribute inline results to parent records
        $attributedResults = $this->attributeInlineResultsToParents($searchResults, $inlineTableMetadata);
        
        return $attributedResults;
    }

    /**
     * Attribute inline table results to their parent records
     */
    protected function attributeInlineResultsToParents(array $searchResults, array $inlineTableMetadata): array
    {
        $attributedResults = [];
        $parentRecordCache = [];
        
        foreach ($searchResults as $tableName => $tableResults) {
            // Check if this is an inline table result
            if (isset($tableResults['_inline_metadata'])) {
                $inlineMetadata = $tableResults['_inline_metadata'];
                $parentTable = $inlineMetadata['parent_table'];
                $foreignField = $inlineMetadata['foreign_field'];
                $parentField = $inlineMetadata['parent_field'];
                $relationType = $inlineMetadata['relation_type'] ?? 'inline';
                
                // Process each inline record and find its parent(s)
                // Note: tableResults is the structure returned by searchInTable which includes metadata
                $inlineRecords = $tableResults['records'] ?? [];
                foreach ($inlineRecords as $inlineRecord) {
                    
                    $parentRecords = $this->findParentRecordsForInlineRecord(
                        $inlineRecord, 
                        $tableName, 
                        $parentTable, 
                        $foreignField, 
                        $parentField,
                        $relationType
                    );
                    
                    // Add the inline match info to each parent record
                    foreach ($parentRecords as $parentRecord) {
                        $parentUid = $parentRecord['uid'];
                        $parentKey = $parentTable . '_' . $parentUid;
                        
                        // Cache parent record
                        if (!isset($parentRecordCache[$parentKey])) {
                            $parentRecordCache[$parentKey] = $parentRecord;
                            $parentRecordCache[$parentKey]['_inline_matches'] = [];
                        }
                        
                        // Add inline match information
                        $parentRecordCache[$parentKey]['_inline_matches'][] = [
                            'table' => $tableName,
                            'record' => $inlineRecord,
                            'field' => $parentField,
                            'type' => $relationType,
                        ];
                    }
                }
                
                // Don't include the inline table directly in results
                continue;
            }
            
            // Include regular (non-inline) table results as-is
            $attributedResults[$tableName] = $tableResults;
        }
        
        // Add parent records that had inline matches
        foreach ($parentRecordCache as $parentKey => $parentRecord) {
            $parentTable = explode('_', $parentKey)[0];
            
            if (!isset($attributedResults[$parentTable])) {
                $attributedResults[$parentTable] = [];
            }
            
            // Remove the cached key prefix and add to results
            unset($parentRecord['_parent_key']);
            $attributedResults[$parentTable][] = $parentRecord;
        }
        
        return $attributedResults;
    }

    /**
     * Find parent records for an inline record
     */
    protected function findParentRecordsForInlineRecord(
        array $inlineRecord, 
        string $inlineTable, 
        string $parentTable, 
        string $foreignField, 
        string $parentField,
        string $relationType = 'inline'
    ): array {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($parentTable);
        
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        
        $queryBuilder->select('*')->from($parentTable);
        
        if ($relationType === 'inline' && !empty($foreignField)) {
            // For inline relations, use the foreign_field to find parent
            $parentUid = $inlineRecord[$foreignField] ?? null;
            if ($parentUid) {
                $queryBuilder->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($parentUid, ParameterType::INTEGER))
                );
            } else {
                return [];
            }
        } elseif ($relationType === 'select') {
            // For select relations, find records that reference this inline record
            $inlineUid = $inlineRecord['uid'] ?? null;
            if ($inlineUid) {
                $queryBuilder->where(
                    $queryBuilder->expr()->like(
                        $parentField,
                        $queryBuilder->createNamedParameter('%' . $inlineUid . '%')
                    )
                );
            } else {
                return [];
            }
        } else {
            return [];
        }
        
        try {
            $parentRecords = $queryBuilder->executeQuery()->fetchAllAssociative();
            
            // Enhance with page information
            return $this->enhanceRecordsWithPageInfo($parentRecords, $parentTable);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get tables to search (accessible tables with searchable fields)
     */
    protected function getTablesToSearch(string $specificTable = ''): array
    {
        if (!empty($specificTable)) {
            // Validate table access using TableAccessService
            try {
                $this->ensureTableAccess($specificTable, 'read');
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('Cannot search table "' . $specificTable . '": ' . $e->getMessage());
            }
            
            return [$specificTable];
        }

        // Get all accessible tables from TableAccessService
        $accessibleTables = $this->tableAccessService->getAccessibleTables(true); // Include read-only tables for searching
        
        // Filter for tables that have searchable fields
        $searchableTables = [];
        foreach ($accessibleTables as $tableName => $accessInfo) {
            // Only include tables that have searchable fields defined in TCA
            if (!empty($this->getSearchableFields($tableName))) {
                $searchableTables[] = $tableName;
            }
        }
        
        return $searchableTables;
    }

    /**
     * Discover related tables that are referenced by primary tables (including relation tables)
     */
    protected function getInlineRelatedHiddenTables(array $primaryTables): array
    {
        $inlineTables = [];
        
        foreach ($primaryTables as $primaryTable) {
            if (!isset($GLOBALS['TCA'][$primaryTable]['columns'])) {
                continue;
            }
            
            // Look through all columns for relations
            foreach ($GLOBALS['TCA'][$primaryTable]['columns'] as $fieldName => $fieldConfig) {
                $fieldType = $fieldConfig['config']['type'] ?? '';
                
                // Check for inline fields
                if ($fieldType === 'inline') {
                    $foreignTable = $fieldConfig['config']['foreign_table'] ?? '';
                    
                    if (!empty($foreignTable) && isset($GLOBALS['TCA'][$foreignTable])) {
                        // Use TableAccessService to check if table is accessible and has searchable fields
                        if ($this->tableAccessService->canAccessTable($foreignTable) && !empty($this->getSearchableFields($foreignTable))) {
                            $inlineTables[$foreignTable] = [
                                'table' => $foreignTable,
                                'parent_table' => $primaryTable,
                                'parent_field' => $fieldName,
                                'foreign_field' => $fieldConfig['config']['foreign_field'] ?? '',
                            ];
                        }
                    }
                }
                
                // Also check for select fields with foreign_table (like categories)
                if ($fieldType === 'select') {
                    $foreignTable = $fieldConfig['config']['foreign_table'] ?? '';
                    
                    if (!empty($foreignTable) && isset($GLOBALS['TCA'][$foreignTable])) {
                        // Use TableAccessService to check if table is accessible and has searchable fields
                        if ($this->tableAccessService->canAccessTable($foreignTable) && !empty($this->getSearchableFields($foreignTable))) {
                            $inlineTables[$foreignTable] = [
                                'table' => $foreignTable,
                                'parent_table' => $primaryTable,
                                'parent_field' => $fieldName,
                                'foreign_field' => '', // Select relations don't use foreign_field
                                'relation_type' => 'select',
                            ];
                        }
                    }
                }
            }
        }
        
        return array_values($inlineTables);
    }

    /**
     * Get searchable fields for a table from TCA
     */
    protected function getSearchableFields(string $table): array
    {
        if (!isset($GLOBALS['TCA'][$table]['ctrl']['searchFields'])) {
            return [];
        }
        
        $searchFields = $GLOBALS['TCA'][$table]['ctrl']['searchFields'];
        return GeneralUtility::trimExplode(',', $searchFields, true);
    }

    /**
     * Validate that searchable fields actually exist in the database table
     */
    protected function validateSearchableFields(string $table, array $searchableFields): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connection = $connectionPool->getConnectionForTable($table);
        
        try {
            // Get the actual columns from the database table
            $schemaManager = $connection->createSchemaManager();
            $tableColumns = $schemaManager->listTableColumns($table);
            $availableColumns = array_keys($tableColumns);
            
            // Filter searchable fields to only include existing columns
            $validFields = [];
            foreach ($searchableFields as $field) {
                if (in_array($field, $availableColumns)) {
                    $validFields[] = $field;
                }
            }
            
            return $validFields;
        } catch (\Throwable $e) {
            // If we can't validate, return original fields and let the query fail with proper error
            return $searchableFields;
        }
    }

    /**
     * Search in a specific table with multiple terms and AND/OR logic
     */
    protected function searchInTable(string $table, array $searchTerms, string $termLogic, array $searchableFields, ?int $pageId, bool $includeHidden, int $limit): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        if (!$includeHidden && isset($GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'])) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }

        // Select all fields
        $queryBuilder->select('*')->from($table);

        // Validate searchable fields exist in database
        $validSearchFields = $this->validateSearchableFields($table, $searchableFields);
        
        if (empty($validSearchFields)) {
            return [];
        }

        // Build search conditions for multiple terms
        $termConditions = [];
        
        foreach ($searchTerms as $term) {
            // For each term, create conditions across all searchable fields
            $fieldConditions = [];
            foreach ($validSearchFields as $field) {
                $fieldConditions[] = $queryBuilder->expr()->like(
                    $field,
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($term) . '%')
                );
            }
            
            // Combine field conditions with OR (any field can match this term)
            if (!empty($fieldConditions)) {
                $termConditions[] = $queryBuilder->expr()->or(...$fieldConditions);
            }
        }
        
        if (empty($termConditions)) {
            return [];
        }

        // Combine term conditions based on logic (AND/OR)
        if ($termLogic === 'AND') {
            // All terms must match (in any field)
            $queryBuilder->where($queryBuilder->expr()->and(...$termConditions));
        } else {
            // Any term can match (OR logic - default)
            $queryBuilder->where($queryBuilder->expr()->or(...$termConditions));
        }

        // Filter by page ID if specified
        if ($pageId !== null && $this->tableHasPidField($table)) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER))
            );
        }

        // Apply default sorting
        RecordFormattingUtility::applyDefaultSorting($queryBuilder, $table);

        // Apply limit
        $queryBuilder->setMaxResults($limit);

        // Execute query
        $records = $queryBuilder->executeQuery()->fetchAllAssociative();

        // Return records in expected structure format
        return [
            'records' => $this->enhanceRecordsWithPageInfo($records, $table),
            'total' => count($records),
            'search_terms' => $searchTerms,
            'term_logic' => $termLogic,
        ];
    }

    /**
     * Enhance records with page information
     */
    protected function enhanceRecordsWithPageInfo(array $records, string $table): array
    {
        if (empty($records)) {
            return $records;
        }

        // Get unique page IDs
        $pageIds = [];
        foreach ($records as $record) {
            if (isset($record['pid']) && $record['pid'] > 0) {
                $pageIds[] = (int)$record['pid'];
            }
        }

        if (empty($pageIds)) {
            return $records;
        }

        // Get page information
        $pageInfo = $this->getPageInfo(array_unique($pageIds));

        // Enhance records
        foreach ($records as &$record) {
            $pid = (int)($record['pid'] ?? 0);
            if (isset($pageInfo[$pid])) {
                $record['_page'] = $pageInfo[$pid];
            }
        }

        return $records;
    }

    /**
     * Get page information for multiple page IDs
     */
    protected function getPageInfo(array $pageIds): array
    {
        if (empty($pageIds)) {
            return [];
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $pages = $queryBuilder->select('uid', 'title', 'slug', 'nav_title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($pageIds, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Index by UID
        $pageInfo = [];
        foreach ($pages as $page) {
            $pageInfo[(int)$page['uid']] = $page;
        }

        return $pageInfo;
    }

    /**
     * Format search results
     */
    protected function formatSearchResults(array $searchResults, array $searchTerms, string $termLogic): string
    {
        if (empty($searchResults)) {
            $termsDisplay = count($searchTerms) === 1 ? '"' . $searchTerms[0] . '"' : '[' . implode(', ', array_map(fn($t) => '"'.$t.'"', $searchTerms)) . ']';
            return "SEARCH RESULTS\n=============\n\nNo results found for search terms: $termsDisplay\n";
        }

        $result = "SEARCH RESULTS\n";
        $result .= "==============\n";
        
        // Display search terms
        if (count($searchTerms) === 1) {
            $result .= "Query: \"" . $searchTerms[0] . "\"\n";
        } else {
            $result .= "Search Terms: [" . implode(', ', array_map(fn($t) => '"'.$t.'"', $searchTerms)) . "]\n";
            $result .= "Logic: " . $termLogic . " (records must match " . 
                      ($termLogic === 'AND' ? 'ALL terms' : 'ANY term') . ")\n";
        }
        $result .= "\n";

        $totalResults = 0;
        foreach ($searchResults as $tableResults) {
            if (is_array($tableResults) && isset($tableResults['records'])) {
                $totalResults += count($tableResults['records']);
            } else {
                $totalResults += count($tableResults);
            }
        }

        $result .= "Total Results: $totalResults\n";
        $result .= "Tables Searched: " . count($searchResults) . "\n\n";

        // Format results by table
        foreach ($searchResults as $table => $records) {
            $result .= $this->formatTableResults($table, $records, $searchTerms);
        }

        return $result;
    }

    /**
     * Format results for a specific table
     */
    protected function formatTableResults(string $table, array $tableData, array $searchTerms): string
    {
        $tableLabel = RecordFormattingUtility::getTableLabel($table);
        $result = "TABLE: $tableLabel ($table)\n";
        $result .= str_repeat('-', strlen("TABLE: $tableLabel ($table)")) . "\n";
        
        // Handle both searchInTable result structure and attributed results array
        $records = [];
        if (isset($tableData['records'])) {
            // This is a searchInTable result structure
            $records = $tableData['records'];
        } elseif (is_array($tableData) && !empty($tableData)) {
            // This is a direct array of records (from attributed results)
            $records = $tableData;
        }
        
        $result .= "Found " . count($records) . " record(s)\n\n";

        foreach ($records as $record) {
            $result .= $this->formatRecord($table, $record, $searchTerms);
        }

        $result .= "\n";
        return $result;
    }

    /**
     * Format a single record
     */
    protected function formatRecord(string $table, array $record, array $searchTerms): string
    {
        $title = RecordFormattingUtility::getRecordTitle($table, $record);
        $uid = $record['uid'] ?? 'unknown';
        
        $result = "• [UID: $uid] $title\n";

        // Add page information if available
        if (isset($record['_page'])) {
            $pageInfo = $record['_page'];
            $pageTitle = $pageInfo['title'] ?? 'Untitled Page';
            $pageUid = $pageInfo['uid'] ?? 'unknown';
            $result .= "  📍 Page: $pageTitle [UID: $pageUid]\n";
        }

        // Add record type information
        if ($table === 'tt_content' && isset($record['CType'])) {
            $cType = $record['CType'];
            $cTypeLabel = RecordFormattingUtility::getContentTypeLabel($cType);
            $result .= "  🎯 Type: $cTypeLabel ($cType)\n";
        }

        // Show preview of matching content
        $preview = $this->getMatchingContentPreview($table, $record, $searchTerms);
        if (!empty($preview)) {
            $result .= "  💬 Preview: $preview\n";
        }

        // Show inline matches if any
        if (isset($record['_inline_matches'])) {
            foreach ($record['_inline_matches'] as $inlineMatch) {
                $inlineTable = $inlineMatch['table'];
                $inlineRecord = $inlineMatch['record'];
                $inlineField = $inlineMatch['field'];
                $inlineType = $inlineMatch['type'];
                
                $inlineTitle = RecordFormattingUtility::getRecordTitle($inlineTable, $inlineRecord);
                $inlineTableLabel = RecordFormattingUtility::getTableLabel($inlineTable);
                
                // Show different icons based on relation type
                $icon = $inlineType === 'select' ? '🏷️' : '📎';
                
                $result .= "  $icon Contains: $inlineTitle [$inlineTableLabel via $inlineField]\n";
                
                // Show preview of the inline match
                $inlinePreview = $this->getMatchingContentPreview($inlineTable, $inlineRecord, $searchTerms);
                if (!empty($inlinePreview)) {
                    $result .= "    💬 Match: $inlinePreview\n";
                }
            }
        }

        $result .= "\n";
        return $result;
    }

    /**
     * Get preview of content that matches the search query
     */
    protected function getMatchingContentPreview(string $table, array $record, array $searchTerms): string
    {
        $searchableFields = $this->getSearchableFields($table);
        $previews = [];

        foreach ($searchableFields as $field) {
            if (!isset($record[$field]) || empty($record[$field])) {
                continue;
            }

            $content = (string)$record[$field];
            
            // Remove HTML tags for preview
            $content = strip_tags($content);
            
            // Check if this field contains any of the search terms
            foreach ($searchTerms as $term) {
                if (stripos($content, $term) !== false) {
                    // Extract a snippet around the match
                    $snippet = RecordFormattingUtility::extractSnippet($content, $term);
                    if (!empty($snippet)) {
                        $previews[] = $snippet;
                        break; // Found a match in this field, move to next field
                    }
                }
            }
        }

        if (empty($previews)) {
            return '';
        }

        return implode(' ... ', array_slice($previews, 0, 2)); // Limit to 2 snippets
    }

    /**
     * Check if a table has a pid field
     */
    protected function tableHasPidField(string $table): bool
    {
        return RecordFormattingUtility::tableHasPidField($table);
    }
}