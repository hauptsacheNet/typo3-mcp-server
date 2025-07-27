<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Fixtures;

use Hn\McpServer\Tests\Functional\Fixtures\Builders\ContentBuilder;
use Hn\McpServer\Tests\Functional\Fixtures\Builders\PageBuilder;
use Hn\McpServer\Tests\Functional\Fixtures\Builders\UserBuilder;
use Hn\McpServer\Tests\Functional\Fixtures\Builders\WorkspaceBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Test data builder for creating test fixtures fluently
 * 
 * Provides a fluent interface for creating test data with sensible defaults
 * and reduces boilerplate code in tests.
 */
class TestDataBuilder
{
    private ConnectionPool $connectionPool;
    
    public function __construct()
    {
        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    }
    
    /**
     * Create a page builder
     * 
     * @return PageBuilder
     */
    public function page(): PageBuilder
    {
        return new PageBuilder($this->connectionPool);
    }
    
    /**
     * Create a content element builder
     * 
     * @return ContentBuilder
     */
    public function content(): ContentBuilder
    {
        return new ContentBuilder($this->connectionPool);
    }
    
    /**
     * Create a user builder
     * 
     * @return UserBuilder
     */
    public function user(): UserBuilder
    {
        return new UserBuilder($this->connectionPool);
    }
    
    /**
     * Create a workspace builder
     * 
     * @return WorkspaceBuilder
     */
    public function workspace(): WorkspaceBuilder
    {
        return new WorkspaceBuilder($this->connectionPool);
    }
}