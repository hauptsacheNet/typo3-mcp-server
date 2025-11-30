<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Exception\DatabaseException;
use Hn\McpServer\Exception\ValidationException;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Hn\McpServer\Utility\RecordFormattingUtility;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Database\Query\Restriction\WorkspaceDeletePlaceholderRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use Hn\McpServer\Service\LanguageService;

/**
 * Tool for searching records across TYPO3 tables using TCA-based searchable fields
 */
class SearchTool extends AbstractRecordTool
{
    protected LanguageService $languageService;

    public function __construct()
    {
        parent::__construct();
        $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
    }

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        $schema = [
            'description' => "Search for records across workspace-capable TYPO3 tables using TCA-based searchable fields. " .
                "Uses SQL LIKE queries for pattern matching. Useful when you need to find pages or content that might not be visible in the page tree, " .
                "or for thorough duplicate checking after initial exploration.",
            'inputSchema' => [
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
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of records to return per table (default: 50)',
                    ],
                ],
                'required' => ['terms'],
            ],
        ];

        // Only add language parameter if multiple languages are configured
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        if (count($availableLanguages) > 1) {
            $schema['inputSchema']['properties']['language'] = [
                'type' => 'string',
                'description' => 'Language ISO code to filter search results (e.g., "de", "fr"). When specified, searches only in content for that language.',
                'enum' => $availableLanguages,
            ];
        }

        // Add annotations
        $schema['annotations'] = [
            'readOnlyHint' => true,
            'idempotentHint' => true,
            'allowedCallers' => ['code_execution_20250825'],
            'inputExamples' => [
                ['terms' => ['contact']],
                ['terms' => ['example'], 'table' => 'tt_content'],
            ],
        ];

        return $schema;
    }

    /**
     * Old examples section - removed as not part of MCP spec
     * These examples are preserved here for documentation purposes:
     * 
     * Search for single term: ['terms' => ['welcome']]
     * Search with OR logic: ['terms' => ['news', 'article', 'blog'], 'termLogic' => 'OR']
     * Search with AND logic: ['terms' => ['TYPO3', 'extension'], 'termLogic' => 'AND']
     * Search in specific table: ['terms' => ['contact'], 'table' => 'tt_content']
     * Search on specific page: ['terms' => ['contact', 'form'], 'pageId' => 123]
     */
    private function removedExamples(): array
    {
        return [
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
            // Log the error but return a helpful message
            $this->logException($e, 'language detection');
            return "LANGUAGES:\n• Could not detect language configuration\n• Try search terms in your site's primary language\n\n";
        }
    }

    /**
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {
        // Validate all parameters first
        $this->validateParameters($params);
        
        // Extract parameters
        $terms = $params['terms'] ?? [];
        $termLogic = strtoupper($params['termLogic'] ?? 'OR');
        $table = trim($params['table'] ?? '');
        $pageId = isset($params['pageId']) ? (int)$params['pageId'] : null;
        $limit = 50;
        
        // Handle language parameter
        $languageId = null;
        if (isset($params['language'])) {
            $languageId = $this->languageService->getUidFromIsoCode($params['language']);
            if ($languageId === null) {
                throw new ValidationException(['Unknown language code: ' . $params['language']]);
            }
        }
        
        // Get normalized search terms
        $searchTerms = $this->validateAndNormalizeSearchTerms($terms);
        
        // Get search results
        $searchResults = $this->performSearch($searchTerms, $termLogic, $table, $pageId, $limit, $languageId);
        
        // Format results
        $formattedResults = $this->formatSearchResults($searchResults, $searchTerms, $termLogic, $languageId);
        
        return $this->createSuccessResult($formattedResults);
    }
    
    /**
     * Validate all parameters
     */
    protected function validateParameters(array $params): void
    {
        $errors = [];
        
        // Validate terms
        if (!isset($params['terms']) || !is_array($params['terms'])) {
            $errors[] = 'Parameter "terms" must be an array of strings';
        } elseif (empty($params['terms'])) {
            $errors[] = 'At least one search term is required in the "terms" array';
        }
        
        // Validate term logic
        if (isset($params['termLogic'])) {
            $termLogic = strtoupper($params['termLogic']);
            if (!in_array($termLogic, ['AND', 'OR'])) {
                $errors[] = 'termLogic must be either "AND" or "OR"';
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Validate and normalize search terms
     */
    protected function validateAndNormalizeSearchTerms(array $terms): array
    {
        $searchTerms = [];
        $errors = [];
        
        foreach ($terms as $term) {
            if (!is_string($term)) {
                $errors[] = 'All terms must be strings';
                continue;
            }
            $trimmedTerm = trim($term);
            if (!empty($trimmedTerm)) {
                $searchTerms[] = $trimmedTerm;
            }
        }
        
        // Validate we have at least one term
        if (empty($searchTerms)) {
            $errors[] = 'At least one non-empty search term is required';
        }
        
        // Validate term lengths
        foreach ($searchTerms as $term) {
            if (strlen($term) < 2) {
                $errors[] = 'All search terms must be at least 2 characters long. Term "' . $term . '" is too short';
            }
            if (strlen($term) > 100) {
                $errors[] = 'Search terms cannot exceed 100 characters. Term "' . substr($term, 0, 20) . '..." is too long';
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
        
        return $searchTerms;
    }

    /**
     * Perform search across tables (including inline relations)
     */
    protected function performSearch(array $searchTerms, string $termLogic, string $table, ?int $pageId, int $limit, ?int $languageId = null): array
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
            
            $results = $this->searchInTable($tableName, $searchTerms, $termLogic, $searchableFields, $pageId, $limit, $languageId);
            
            if (!empty($results) && !empty($results['records'])) {
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
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));
        
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
            // Log the error but continue without parent records
            $this->logException($e, 'finding parent records');
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
                throw new ValidationException(['Cannot search table "' . $specificTable . '": ' . $e->getMessage()]);
            }
            
            return [$specificTable];
        }

        // Get all readable tables (includes non-workspace-capable tables for read operations)
        $accessibleTables = $this->tableAccessService->getReadableTables();
        
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
                    
                    // Skip self-referential relations (like localization fields)
                    if (!empty($foreignTable) && $foreignTable !== $primaryTable && isset($GLOBALS['TCA'][$foreignTable])) {
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
        return $this->tableAccessService->getSearchFields($table);
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
            // Log validation error but continue with original fields
            $this->logException($e, 'validating searchable fields');
            return $searchableFields;
        }
    }

    /**
     * Search in a specific table with multiple terms and AND/OR logic
     */
    protected function searchInTable(string $table, array $searchTerms, string $termLogic, array $searchableFields, ?int $pageId, int $limit, ?int $languageId = null): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);

        // Apply restrictions
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0))
            ->add(GeneralUtility::makeInstance(WorkspaceDeletePlaceholderRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));

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

        // Filter by language if specified and table has language support
        if ($languageId !== null && $this->tableHasLanguageSupport($table)) {
            if ($languageId === 0) {
                // Default language: only show records with sys_language_uid = 0 or -1 (all languages)
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(-1, ParameterType::INTEGER))
                    )
                );
            } else {
                // Specific language: show records in that language, default language, or all languages
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                        $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(-1, ParameterType::INTEGER))
                    )
                );
            }
        }

        // Apply default sorting
        RecordFormattingUtility::applyDefaultSorting($queryBuilder, $table);

        // Apply limit
        $queryBuilder->setMaxResults($limit);

        // Execute query with error handling
        try {
            $records = $queryBuilder->executeQuery()->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception $e) {
            throw new DatabaseException('search', $table, $e);
        }

        // Return records in expected structure format
        return [
            'records' => $this->enhanceRecordsWithPageInfo($records, $table, $languageId),
            'total' => count($records),
            'search_terms' => $searchTerms,
            'term_logic' => $termLogic,
        ];
    }

    /**
     * Check if table has language support
     */
    protected function tableHasLanguageSupport(string $table): bool
    {
        return isset($GLOBALS['TCA'][$table]['ctrl']['languageField']);
    }

    /**
     * Enhance records with page information
     */
    protected function enhanceRecordsWithPageInfo(array $records, string $table, ?int $languageId = null): array
    {
        if (empty($records)) {
            return $records;
        }

        // Process records for workspace transparency
        $processedRecords = [];
        $seenUids = [];
        
        foreach ($records as $record) {
            // For workspace transparency, replace workspace UID with live UID
            if (isset($record['t3ver_oid']) && $record['t3ver_oid'] > 0) {
                // This is a workspace version - use the live UID instead
                $record['uid'] = $record['t3ver_oid'];
            } elseif (isset($record['t3ver_state']) && $record['t3ver_state'] == 1) {
                // This is a new placeholder record - its UID is already the "live" UID
                // No change needed
            }
            
            // De-duplicate records based on UID after processing
            $uid = $record['uid'] ?? 0;
            if (!isset($seenUids[$uid])) {
                $processedRecords[] = $record;
                $seenUids[$uid] = true;
            }
        }
        
        $records = $processedRecords;

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
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $GLOBALS['BE_USER']->workspace ?? 0));

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
    protected function formatSearchResults(array $searchResults, array $searchTerms, string $termLogic, ?int $languageId = null): string
    {

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

        if ($languageId !== null) {
            $isoCode = $this->languageService->getIsoCodeFromUid($languageId) ?? 'unknown';
            $result .= "Language Filter: " . strtoupper($isoCode) . " (ID: $languageId)\n";
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

        // If no results, show no results message
        if ($totalResults === 0) {
            $termsDisplay = count($searchTerms) === 1 ? '"' . $searchTerms[0] . '"' : '[' . implode(', ', array_map(fn($t) => '"'.$t.'"', $searchTerms)) . ']';
            $result .= "No results found for search terms: $termsDisplay\n";
        } else {
            // Format results by table
            foreach ($searchResults as $table => $records) {
                $result .= $this->formatTableResults($table, $records, $searchTerms, $languageId);
            }
        }

        return $result;
    }

    /**
     * Format results for a specific table
     */
    protected function formatTableResults(string $table, array $tableData, array $searchTerms, ?int $languageId = null): string
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
            $result .= $this->formatRecord($table, $record, $searchTerms, $languageId);
        }

        $result .= "\n";
        return $result;
    }

    /**
     * Format a single record
     */
    protected function formatRecord(string $table, array $record, array $searchTerms, ?int $languageId = null): string
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

        // Add language information if table has language support
        if ($this->tableHasLanguageSupport($table) && isset($record['sys_language_uid'])) {
            $recordLangId = (int)$record['sys_language_uid'];
            if ($recordLangId > 0) {
                $langCode = $this->languageService->getIsoCodeFromUid($recordLangId) ?? 'unknown';
                $result .= "  🌐 Language: " . strtoupper($langCode) . "\n";
            } elseif ($recordLangId === -1) {
                $result .= "  🌐 Language: All\n";
            }
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