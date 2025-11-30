<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for generating context-aware input examples for MCP tools.
 * Examples are dynamically generated based on actually installed extensions
 * and available tables in the TYPO3 instance.
 */
class InputExamplesService implements SingletonInterface
{
    protected TableAccessService $tableAccessService;
    protected LanguageService $languageService;

    public function __construct()
    {
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
        $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
    }

    /**
     * Get input examples for ReadTableTool
     */
    public function getReadTableExamples(): array
    {
        $examples = [];

        // Always include tt_content example (core table)
        $examples[] = [
            'table' => 'tt_content',
            'pid' => 1,
            'limit' => 10,
        ];

        // Add pages example
        $examples[] = [
            'table' => 'pages',
            'pid' => 0,
            'where' => 'doktype = 1',
        ];

        // Check for news extension
        if ($this->isTableAvailable('tx_news_domain_model_news')) {
            $examples[] = [
                'table' => 'tx_news_domain_model_news',
                'limit' => 5,
            ];
        }

        // Check for tt_address
        if ($this->isTableAvailable('tt_address')) {
            $examples[] = [
                'table' => 'tt_address',
                'limit' => 10,
            ];
        }

        // Check for events2
        if ($this->isTableAvailable('tx_events2_domain_model_event')) {
            $examples[] = [
                'table' => 'tx_events2_domain_model_event',
                'limit' => 5,
            ];
        }

        // Check for blog extension
        if ($this->isTableAvailable('tx_blog_domain_model_post')) {
            $examples[] = [
                'table' => 'tx_blog_domain_model_post',
                'limit' => 5,
            ];
        }

        return $examples;
    }

    /**
     * Get input examples for WriteTableTool
     */
    public function getWriteTableExamples(): array
    {
        $examples = [];

        // Content element creation example
        $examples[] = [
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'New Content Element',
                'bodytext' => 'Content with <b>HTML</b> formatting',
            ],
        ];

        // Content element update example
        $examples[] = [
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 42,
            'data' => [
                'header' => 'Updated Header',
            ],
        ];

        // Check for news extension
        if ($this->isTableAvailable('tx_news_domain_model_news')) {
            $examples[] = [
                'action' => 'create',
                'table' => 'tx_news_domain_model_news',
                'pid' => 1,
                'data' => [
                    'title' => 'News Article Title',
                    'teaser' => 'Short teaser text',
                    'bodytext' => 'Full article content',
                    'datetime' => date('Y-m-d H:i:s'),
                ],
            ];
        }

        // Add translation example if multiple languages
        $availableLanguages = $this->languageService->getAvailableIsoCodes();
        if (count($availableLanguages) > 1) {
            $targetLang = $availableLanguages[1] ?? 'de';
            $examples[] = [
                'action' => 'translate',
                'table' => 'tt_content',
                'uid' => 1,
                'data' => [
                    'sys_language_uid' => $targetLang,
                    'header' => 'Translated Header',
                ],
            ];
        }

        return $examples;
    }

    /**
     * Get input examples for SearchTool
     */
    public function getSearchExamples(): array
    {
        $examples = [];

        // Basic search
        $examples[] = [
            'query' => 'contact',
        ];

        // Search in specific table
        $examples[] = [
            'query' => 'example search term',
            'tables' => ['tt_content', 'pages'],
        ];

        // Check for news extension
        if ($this->isTableAvailable('tx_news_domain_model_news')) {
            $examples[] = [
                'query' => 'press release',
                'tables' => ['tx_news_domain_model_news'],
            ];
        }

        return $examples;
    }

    /**
     * Get input examples for GetPageTool
     */
    public function getPageExamples(): array
    {
        return [
            ['uid' => 1],
            ['url' => '/contact'],
        ];
    }

    /**
     * Get input examples for GetPageTreeTool
     */
    public function getPageTreeExamples(): array
    {
        return [
            ['pid' => 0, 'depth' => 2],
            ['pid' => 1, 'depth' => 1],
        ];
    }

    /**
     * Get input examples for GetTableSchemaTool
     */
    public function getTableSchemaExamples(): array
    {
        $examples = [
            ['table' => 'tt_content'],
            ['table' => 'tt_content', 'type' => 'text'],
            ['table' => 'pages'],
        ];

        if ($this->isTableAvailable('tx_news_domain_model_news')) {
            $examples[] = ['table' => 'tx_news_domain_model_news'];
        }

        return $examples;
    }

    /**
     * Get input examples for ListTablesTool
     */
    public function getListTablesExamples(): array
    {
        return [
            [],
            ['filter' => 'content'],
        ];
    }

    /**
     * Get input examples for GetFlexFormSchemaTool
     */
    public function getFlexFormSchemaExamples(): array
    {
        $examples = [];

        // Check for news extension
        if ($this->isTableAvailable('tx_news_domain_model_news')) {
            $examples[] = [
                'table' => 'tt_content',
                'type' => 'list',
                'list_type' => 'news_pi1',
            ];
        }

        // Generic plugin example
        $examples[] = [
            'table' => 'tt_content',
            'type' => 'list',
            'list_type' => 'example_plugin',
        ];

        return $examples;
    }

    /**
     * Check if a table is available and accessible
     */
    protected function isTableAvailable(string $table): bool
    {
        return isset($GLOBALS['TCA'][$table]) && $this->tableAccessService->canReadTable($table);
    }
}
