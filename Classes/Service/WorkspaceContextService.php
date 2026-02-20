<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * Service for managing workspace context in MCP operations
 */
class WorkspaceContextService
{
    /**
     * Switch to the optimal workspace for the current user.
     * Creates a new workspace if none exists and user can create workspaces.
     */
    public function switchToOptimalWorkspace(BackendUserAuthentication $beUser): int
    {
        // If already in a workspace, don't switch
        $currentWorkspace = $beUser->workspace ?? 0;
        if ($currentWorkspace > 0) {
            return $currentWorkspace;
        }
        
        // First check if user already has access to workspaces
        $workspaceId = $this->getFirstWritableWorkspace($beUser);
        
        // If no workspace found and user can create workspaces, create one
        if ($workspaceId === 0 && $this->canUserCreateWorkspaces($beUser)) {
            $workspaceId = $this->createMcpWorkspace($beUser);
        }
        
        // Set the workspace context
        $this->setWorkspaceContext($beUser, $workspaceId);
        
        return $workspaceId;
    }
    
    /**
     * Get the first workspace the user can write to
     */
    protected function getFirstWritableWorkspace(BackendUserAuthentication $beUser): int
    {
        try {
            $workspaceService = GeneralUtility::makeInstance(WorkspaceService::class);
            $availableWorkspaces = $workspaceService->getAvailableWorkspaces();
            
            // Check each workspace (excluding live workspace 0)
            foreach ($availableWorkspaces as $workspaceId => $title) {
                if ($workspaceId > 0) {
                    $workspaceRecord = $beUser->checkWorkspace($workspaceId);
                    if ($workspaceRecord && $this->hasWriteAccess($workspaceRecord)) {
                        return $workspaceId;
                    }
                }
            }
        } catch (\Throwable $e) {
            // If WorkspaceService fails, fall back to database query
            return $this->getWorkspaceFromDatabase($beUser);
        }
        
        return 0; // Fallback to live workspace
    }
    
    /**
     * Check if user has write access to a workspace
     */
    protected function hasWriteAccess(array $workspaceRecord): bool
    {
        // Admin users always have write access
        if (!empty($workspaceRecord['_ACCESS']) && $workspaceRecord['_ACCESS'] === 'admin') {
            return true;
        }
        
        // Owner has write access
        if (!empty($workspaceRecord['_ACCESS']) && $workspaceRecord['_ACCESS'] === 'owner') {
            return true;
        }
        
        // Members have write access
        if (!empty($workspaceRecord['_ACCESS']) && $workspaceRecord['_ACCESS'] === 'member') {
            return true;
        }
        
        return false;
    }
    
    /**
     * Fallback method to get workspace from database
     */
    protected function getWorkspaceFromDatabase(BackendUserAuthentication $beUser): int
    {
        try {
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_workspace');
            
            // Get workspaces where user is owner or member
            $workspaces = $queryBuilder
                ->select('uid', 'title')
                ->from('sys_workspace')
                ->where(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq(
                            'adminusers',
                            $queryBuilder->createNamedParameter($beUser->user['uid'] ?? 0)
                        ),
                        $queryBuilder->expr()->like(
                            'adminusers',
                            $queryBuilder->createNamedParameter('%,' . ($beUser->user['uid'] ?? 0) . ',%')
                        ),
                        $queryBuilder->expr()->eq(
                            'members',
                            $queryBuilder->createNamedParameter($beUser->user['uid'] ?? 0)
                        ),
                        $queryBuilder->expr()->like(
                            'members',
                            $queryBuilder->createNamedParameter('%,' . ($beUser->user['uid'] ?? 0) . ',%')
                        )
                    )
                )
                ->andWhere(
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0))
                )
                ->orderBy('uid', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            
            if ($workspaces) {
                return (int)$workspaces['uid'];
            }
        } catch (\Throwable $e) {
            // Ignore database errors
        }
        
        return 0;
    }
    
    /**
     * Check if user can create workspaces
     */
    protected function canUserCreateWorkspaces(BackendUserAuthentication $beUser): bool
    {
        // Admin users can always create workspaces
        if ($beUser->isAdmin()) {
            return true;
        }
        
        // Check if user has workspace module access
        return $beUser->check('modules', 'web_WorkspacesWorkspaces');
    }
    
    /**
     * Create a new workspace for MCP operations
     */
    protected function createMcpWorkspace(BackendUserAuthentication $beUser): int
    {
        try {
            $realName = $beUser->user['realName'] ?? '';
            $username = $beUser->user['username'] ?? 'unknown_user';
            $workspaceTitle = 'MCP Workspace for ' . ($realName ?: $username);
            $workspaceDescription = 'Automatically created workspace for Model Context Protocol operations';
            
            // Create workspace record data
            $workspaceData = [
                'pid' => 0, // Workspaces are created at root level
                'title' => $workspaceTitle,
                'description' => $workspaceDescription,
                'adminusers' => $beUser->user['uid'] ?? 0,
                'members' => '',
                'db_mountpoints' => '', // Inherit from user
                'file_mountpoints' => '', // Inherit from user
                'publish_access' => 1, // Allow publishing
                'stagechg_notification' => 0, // No email notifications by default
                'live_edit' => 0, // No live edit
                'publish_time' => 0, // No scheduled publishing
            ];

            // 'freeze' column was removed in TYPO3 14 (#107323)
            $typo3Version = GeneralUtility::makeInstance(Typo3Version::class);
            if ($typo3Version->getMajorVersion() < 14) {
                $workspaceData['freeze'] = 0;
            }

            // Use DataHandler to create the workspace
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->bypassAccessCheckForRecords = true;
            
            $newId = 'NEW' . uniqid();
            $dataMap = [
                'sys_workspace' => [
                    $newId => $workspaceData
                ]
            ];
            
            $dataHandler->start($dataMap, []);
            $dataHandler->process_datamap();
            
            // Get the UID of the newly created workspace
            $newUid = $dataHandler->substNEWwithIDs[$newId] ?? null;
            
            if ($newUid && !$dataHandler->errorLog) {
                return (int)$newUid;
            }
        } catch (\Throwable $e) {
            // Workspace creation failed, log the error but don't fail
            error_log('MCP Workspace creation failed: ' . $e->getMessage());
        }
        
        return 0; // Fallback to live workspace
    }
    
    /**
     * Set the workspace context for the current request
     */
    public function setWorkspaceContext(BackendUserAuthentication $beUser, int $workspaceId): void
    {
        // Set workspace on the backend user (temporary, doesn't persist to database)
        $beUser->setTemporaryWorkspace($workspaceId);
        
        // Update the Context API
        $context = GeneralUtility::makeInstance(Context::class);
        $workspaceAspect = GeneralUtility::makeInstance(WorkspaceAspect::class, $workspaceId);
        $context->setAspect('workspace', $workspaceAspect);
    }
    
    /**
     * Get current workspace ID
     */
    public function getCurrentWorkspace(): int
    {
        return $GLOBALS['BE_USER']->workspace ?? 0;
    }
    
    /**
     * Get information about the current workspace
     */
    public function getWorkspaceInfo(): array
    {
        $workspaceId = $this->getCurrentWorkspace();
        
        if ($workspaceId === 0) {
            return [
                'id' => 0,
                'title' => 'Live',
                'description' => 'Live workspace - changes are immediately public',
                'is_live' => true
            ];
        }
        
        try {
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_workspace');
            
            $workspace = $queryBuilder
                ->select('uid', 'title', 'description')
                ->from('sys_workspace')
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($workspaceId))
                )
                ->executeQuery()
                ->fetchAssociative();
            
            if ($workspace) {
                return [
                    'id' => (int)$workspace['uid'],
                    'title' => $workspace['title'],
                    'description' => $workspace['description'],
                    'is_live' => false
                ];
            }
        } catch (\Throwable $e) {
            // Ignore errors
        }
        
        return [
            'id' => $workspaceId,
            'title' => 'Unknown Workspace',
            'description' => 'Workspace information not available',
            'is_live' => false
        ];
    }
}