<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Fixtures\Builders;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Builder for creating workspace records in tests
 */
class WorkspaceBuilder
{
    private ConnectionPool $connectionPool;
    
    private array $data = [
        'pid' => 0,
        'title' => 'Test Workspace',
        'description' => 'Workspace for testing',
        'adminusers' => '1',
        'members' => '1',
        'db_mountpoints' => '0',
        'file_mountpoints' => '',
        'publish_time' => 0,
        'unpublish_time' => 0,
        'freeze' => 0,
        'live_edit' => 0,
        'swap_modes' => 0,
        'publish_access' => 0,
        'stagechg_notification' => 0,
        'custom_stages' => 0,
        'deleted' => 0,
    ];
    
    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }
    
    /**
     * Set workspace title
     */
    public function withTitle(string $title): self
    {
        $this->data['title'] = $title;
        return $this;
    }
    
    /**
     * Set workspace description
     */
    public function withDescription(string $description): self
    {
        $this->data['description'] = $description;
        return $this;
    }
    
    /**
     * Set admin users (comma-separated UIDs)
     */
    public function withAdminUsers(string $adminUsers): self
    {
        $this->data['adminusers'] = $adminUsers;
        return $this;
    }
    
    /**
     * Set member users (comma-separated UIDs)
     */
    public function withMembers(string $members): self
    {
        $this->data['members'] = $members;
        return $this;
    }
    
    /**
     * Set DB mount points
     */
    public function withDbMountPoints(string $mountPoints): self
    {
        $this->data['db_mountpoints'] = $mountPoints;
        return $this;
    }
    
    /**
     * Set file mount points
     */
    public function withFileMountPoints(string $mountPoints): self
    {
        $this->data['file_mountpoints'] = $mountPoints;
        return $this;
    }
    
    /**
     * Enable live editing
     */
    public function withLiveEdit(): self
    {
        $this->data['live_edit'] = 1;
        return $this;
    }
    
    /**
     * Freeze workspace
     */
    public function frozen(): self
    {
        $this->data['freeze'] = 1;
        return $this;
    }
    
    /**
     * Set publish time
     */
    public function withPublishTime(int $timestamp): self
    {
        $this->data['publish_time'] = $timestamp;
        return $this;
    }
    
    /**
     * Set unpublish time
     */
    public function withUnpublishTime(int $timestamp): self
    {
        $this->data['unpublish_time'] = $timestamp;
        return $this;
    }
    
    /**
     * Set swap mode
     */
    public function withSwapMode(int $mode): self
    {
        $this->data['swap_modes'] = $mode;
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
     * Create the workspace record and return its UID
     */
    public function create(): int
    {
        // Set timestamps
        $this->data['tstamp'] = time();
        $this->data['crdate'] = time();
        
        $connection = $this->connectionPool->getConnectionForTable('sys_workspace');
        $connection->insert('sys_workspace', $this->data);
        
        return (int)$connection->lastInsertId();
    }
}