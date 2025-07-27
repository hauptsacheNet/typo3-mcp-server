<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Fixtures\Builders;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Builder for creating backend user records in tests
 */
class UserBuilder
{
    private ConnectionPool $connectionPool;
    
    private array $data = [
        'pid' => 0,
        'username' => 'testuser',
        'password' => '$2y$10$T5kUhFMgmVrOj8fRWCsM0eEROG9lEMuNf0N9j7N0AG9h1gqE3V8qu', // password: password
        'admin' => 0,
        'usergroup' => '',
        'disable' => 0,
        'starttime' => 0,
        'endtime' => 0,
        'lang' => 'default',
        'email' => 'test@example.com',
        'realName' => 'Test User',
        'workspace_id' => 0,
        'workspace_preview' => 1,
        'userMods' => '',
        'allowed_languages' => '',
        'TSconfig' => '',
        'db_mountpoints' => '',
        'file_mountpoints' => '',
        'options' => 0,
        'deleted' => 0,
        'uc' => '',
        'description' => '',
        'category_perms' => '',
        'lastlogin' => 0,
        'workspace_perms' => 1,
        'file_permissions' => '',
    ];
    
    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }
    
    /**
     * Set username
     */
    public function withUsername(string $username): self
    {
        $this->data['username'] = $username;
        return $this;
    }
    
    /**
     * Set password (will be hashed)
     */
    public function withPassword(string $password): self
    {
        $this->data['password'] = password_hash($password, PASSWORD_BCRYPT);
        return $this;
    }
    
    /**
     * Set as admin user
     */
    public function asAdmin(): self
    {
        $this->data['admin'] = 1;
        return $this;
    }
    
    /**
     * Set as regular user
     */
    public function asRegularUser(): self
    {
        $this->data['admin'] = 0;
        return $this;
    }
    
    /**
     * Set usergroups
     */
    public function withGroups(string $groups): self
    {
        $this->data['usergroup'] = $groups;
        return $this;
    }
    
    /**
     * Set email
     */
    public function withEmail(string $email): self
    {
        $this->data['email'] = $email;
        return $this;
    }
    
    /**
     * Set real name
     */
    public function withRealName(string $realName): self
    {
        $this->data['realName'] = $realName;
        return $this;
    }
    
    /**
     * Set workspace permissions
     */
    public function withWorkspacePerms(int $perms): self
    {
        $this->data['workspace_perms'] = $perms;
        return $this;
    }
    
    /**
     * Set allowed languages
     */
    public function withAllowedLanguages(string $languages): self
    {
        $this->data['allowed_languages'] = $languages;
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
     * Set TSconfig
     */
    public function withTSconfig(string $tsconfig): self
    {
        $this->data['TSconfig'] = $tsconfig;
        return $this;
    }
    
    /**
     * Disable the user
     */
    public function disabled(): self
    {
        $this->data['disable'] = 1;
        return $this;
    }
    
    /**
     * Enable the user
     */
    public function enabled(): self
    {
        $this->data['disable'] = 0;
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
     * Create the user record and return its UID
     */
    public function create(): int
    {
        // Set timestamps
        $this->data['tstamp'] = time();
        $this->data['crdate'] = time();
        
        // Ensure email is unique if not set
        if ($this->data['email'] === 'test@example.com') {
            $this->data['email'] = $this->data['username'] . '@example.com';
        }
        
        $connection = $this->connectionPool->getConnectionForTable('be_users');
        $connection->insert('be_users', $this->data);
        
        return (int)$connection->lastInsertId();
    }
}