<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Fixtures\Builders;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Builder for creating page records in tests
 */
class PageBuilder
{
    private ConnectionPool $connectionPool;
    
    private array $data = [
        'pid' => 0,
        'title' => 'Test Page',
        'deleted' => 0,
        'hidden' => 0,
        'doktype' => 1, // Standard page
        'slug' => '',
        'nav_title' => '',
        'subtitle' => '',
        'abstract' => '',
        'keywords' => '',
        'description' => '',
        'author' => '',
        'author_email' => '',
        'lastUpdated' => 0,
        'layout' => 0,
        'newUntil' => 0,
        'backend_layout' => '',
        'backend_layout_next_level' => '',
        'content_from_pid' => 0,
        'target' => '',
        'cache_timeout' => 0,
        'cache_tags' => '',
        'is_siteroot' => 0,
        'no_search' => 0,
        'php_tree_stop' => 0,
        'module' => '',
        'media' => 0,
        'tsconfig_includes' => '',
        'l10n_parent' => 0,
        'l10n_source' => 0,
        'l10n_state' => null,
        'l10n_diffsource' => '',
        'sys_language_uid' => 0,
        'extendToSubpages' => 0,
        'fe_group' => '',
        'editlock' => 0,
        'categories' => 0,
        'rowDescription' => null,
    ];
    
    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }
    
    /**
     * Set the page title
     */
    public function withTitle(string $title): self
    {
        $this->data['title'] = $title;
        return $this;
    }
    
    /**
     * Set the parent page ID
     */
    public function withParent(int $pid): self
    {
        $this->data['pid'] = $pid;
        return $this;
    }
    
    /**
     * Set the page as hidden
     */
    public function hidden(): self
    {
        $this->data['hidden'] = 1;
        return $this;
    }
    
    /**
     * Set the page as visible
     */
    public function visible(): self
    {
        $this->data['hidden'] = 0;
        return $this;
    }
    
    /**
     * Set the page doktype
     */
    public function withDoktype(int $doktype): self
    {
        $this->data['doktype'] = $doktype;
        return $this;
    }
    
    /**
     * Set as site root
     */
    public function asSiteRoot(): self
    {
        $this->data['is_siteroot'] = 1;
        return $this;
    }
    
    /**
     * Set the URL slug
     */
    public function withSlug(string $slug): self
    {
        $this->data['slug'] = $slug;
        return $this;
    }
    
    /**
     * Set navigation title
     */
    public function withNavTitle(string $navTitle): self
    {
        $this->data['nav_title'] = $navTitle;
        return $this;
    }
    
    /**
     * Set backend layout
     */
    public function withBackendLayout(string $layout): self
    {
        $this->data['backend_layout'] = $layout;
        return $this;
    }
    
    /**
     * Set backend layout for subpages
     */
    public function withBackendLayoutNextLevel(string $layout): self
    {
        $this->data['backend_layout_next_level'] = $layout;
        return $this;
    }
    
    /**
     * Set language UID
     */
    public function withLanguage(int $languageUid): self
    {
        $this->data['sys_language_uid'] = $languageUid;
        return $this;
    }
    
    /**
     * Set l10n parent for translations
     */
    public function withL10nParent(int $parentUid): self
    {
        $this->data['l10n_parent'] = $parentUid;
        $this->data['l10n_source'] = $parentUid;
        return $this;
    }
    
    /**
     * Set page as deleted
     */
    public function deleted(): self
    {
        $this->data['deleted'] = 1;
        return $this;
    }
    
    /**
     * Set custom data field
     */
    public function with(string $field, $value): self
    {
        $this->data[$field] = $value;
        return $this;
    }
    
    /**
     * Create the page record and return its UID
     */
    public function create(): int
    {
        // Auto-generate slug if not set
        if (empty($this->data['slug'])) {
            $this->data['slug'] = '/' . strtolower(str_replace(' ', '-', $this->data['title']));
        }
        
        // Set timestamps
        $this->data['tstamp'] = time();
        $this->data['crdate'] = time();
        
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $connection->insert('pages', $this->data);
        
        return (int)$connection->lastInsertId();
    }
    
    /**
     * Create multiple pages with incremented titles
     * 
     * @param int $count Number of pages to create
     * @return array Array of created UIDs
     */
    public function createMultiple(int $count): array
    {
        $uids = [];
        $baseTitle = $this->data['title'];
        
        for ($i = 1; $i <= $count; $i++) {
            $this->data['title'] = $baseTitle . ' ' . $i;
            $uids[] = $this->create();
        }
        
        // Reset title
        $this->data['title'] = $baseTitle;
        
        return $uids;
    }
}