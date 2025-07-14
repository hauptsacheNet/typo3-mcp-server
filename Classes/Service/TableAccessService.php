<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Central service for determining table access permissions and capabilities
 * This service acts as the single source of truth for which tables can be accessed
 * through the MCP tools, considering workspace capability, user permissions, and other restrictions.
 */
class TableAccessService implements SingletonInterface
{
    protected ?BackendUserAuthentication $backendUser = null;
    protected WorkspaceContextService $workspaceContextService;
    
    public function __construct()
    {
        $this->workspaceContextService = GeneralUtility::makeInstance(WorkspaceContextService::class);
    }
    
    /**
     * Get the current backend user, ensuring it's properly initialized
     */
    protected function getBackendUser(): BackendUserAuthentication
    {
        if ($this->backendUser === null) {
            if (!isset($GLOBALS['BE_USER']) || !$GLOBALS['BE_USER'] instanceof BackendUserAuthentication) {
                throw new \RuntimeException('Backend user context not properly initialized. Make sure authentication is set up.');
            }
            $this->backendUser = $GLOBALS['BE_USER'];
        }
        
        return $this->backendUser;
    }
    
    /**
     * Get all tables that are accessible to the current user
     * 
     * @param bool $includeReadOnly Include read-only tables
     * @return array Array of table names with access information
     */
    public function getAccessibleTables(bool $includeReadOnly = false): array
    {
        $accessibleTables = [];
        
        foreach (array_keys($GLOBALS['TCA']) as $table) {
            $accessInfo = $this->getTableAccessInfo($table);
            
            if ($accessInfo['accessible']) {
                // Skip read-only tables if not requested
                if (!$includeReadOnly && $accessInfo['read_only']) {
                    continue;
                }
                
                $accessibleTables[$table] = $accessInfo;
            }
        }
        
        return $accessibleTables;
    }
    
    /**
     * Get all tables that are readable (less restrictive - no workspace capability required)
     * 
     * @return array Array of table names with access information
     */
    public function getReadableTables(): array
    {
        $readableTables = [];
        
        foreach (array_keys($GLOBALS['TCA']) as $table) {
            $accessInfo = $this->getTableAccessInfo($table, false); // Don't require workspace capability
            
            if ($accessInfo['accessible']) {
                $readableTables[$table] = $accessInfo;
            }
        }
        
        return $readableTables;
    }
    
    /**
     * Check if a table can be accessed by the current user
     * 
     * @param string $table Table name
     * @return bool
     */
    public function canAccessTable(string $table): bool
    {
        $accessInfo = $this->getTableAccessInfo($table);
        return $accessInfo['accessible'];
    }
    
    /**
     * Check if a table can be accessed for read operations (less restrictive)
     * 
     * @param string $table Table name
     * @return bool
     */
    public function canReadTable(string $table): bool
    {
        $accessInfo = $this->getTableAccessInfo($table, false); // Don't require workspace capability
        return $accessInfo['accessible'];
    }
    
    /**
     * Get detailed access information for a table
     * 
     * @param string $table Table name
     * @param bool $requireWorkspaceCapability Whether workspace capability is required (default: true)
     * @return array Detailed access information
     */
    public function getTableAccessInfo(string $table, bool $requireWorkspaceCapability = true): array
    {
        // Start with default values
        $info = [
            'accessible' => false,
            'reasons' => [],
            'restrictions' => [],
            'workspace_capable' => false,
            'read_only' => false,
            'permissions' => [
                'read' => false,
                'write' => false,
                'delete' => false,
            ],
        ];
        
        // Check if table exists in TCA
        if (!isset($GLOBALS['TCA'][$table])) {
            $info['reasons'][] = 'Table does not exist in TCA';
            return $info;
        }
        
        // Check if table is a truly restricted system table
        if ($this->isRestrictedSystemTable($table)) {
            $info['reasons'][] = 'Table is restricted for security or system integrity reasons';
            return $info;
        }
        
        // Check workspace capability (required for write operations)
        $info['workspace_capable'] = BackendUtility::isTableWorkspaceEnabled($table);
        if ($requireWorkspaceCapability && !$info['workspace_capable']) {
            $info['reasons'][] = 'Table is not workspace-capable (required for write operations)';
            return $info;
        }
        
        // Check user permissions
        $permissions = $this->checkUserPermissions($table);
        $info['permissions'] = $permissions;
        
        if (!$permissions['read']) {
            $info['reasons'][] = 'User does not have read permission for this table';
            return $info;
        }
        
        // Check if table is read-only
        $info['read_only'] = $this->isTableReadOnly($table);
        if ($info['read_only']) {
            $info['restrictions'][] = 'Table is read-only';
            $info['permissions']['write'] = false;
            $info['permissions']['delete'] = false;
        }
        
        // Check field restrictions
        $fieldRestrictions = $this->getFieldRestrictions($table);
        if (!empty($fieldRestrictions)) {
            $info['restrictions'] = array_merge($info['restrictions'], $fieldRestrictions);
        }
        
        // If we made it here, the table is accessible
        $info['accessible'] = true;
        
        return $info;
    }
    
    /**
     * Validate that a table can be accessed, throwing an exception if not
     * 
     * @param string $table Table name
     * @param string $operation Optional operation being attempted (read, write, delete)
     * @throws \InvalidArgumentException If table cannot be accessed
     */
    public function validateTableAccess(string $table, string $operation = 'read'): void
    {
        $accessInfo = $this->getTableAccessInfo($table);
        
        if (!$accessInfo['accessible']) {
            $reasons = implode(', ', $accessInfo['reasons']);
            throw new \InvalidArgumentException(
                "Cannot access table '{$table}': {$reasons}"
            );
        }
        
        // Check specific operation permission
        if ($operation !== 'read' && !$accessInfo['permissions'][$operation]) {
            throw new \InvalidArgumentException(
                "Operation '{$operation}' not permitted on table '{$table}'"
            );
        }
    }
    
    /**
     * Get the schema for an accessible table
     * 
     * @param string $table Table name
     * @param string $type Record type (optional)
     * @return array Schema information
     * @throws \InvalidArgumentException If table is not accessible
     */
    public function getTableSchema(string $table, string $type = ''): array
    {
        $this->validateTableAccess($table);
        
        $schema = [
            'table' => $table,
            'type' => $type,
            'fields' => $this->getAvailableFields($table, $type),
            'ctrl' => $this->getTableControlInfo($table),
        ];
        
        return $schema;
    }
    
    /**
     * Get available fields for a table and type
     * 
     * @param string $table Table name
     * @param string $type Record type (optional)
     * @return array Field configuration
     */
    public function getAvailableFields(string $table, string $type = ''): array
    {
        $this->validateTableAccess($table);
        
        $fields = [];
        $tca = $GLOBALS['TCA'][$table] ?? [];
        
        // Get type-specific configuration
        $typeConfig = [];
        if (!empty($type) && isset($tca['types'][$type])) {
            $typeConfig = $tca['types'][$type];
        } elseif (isset($tca['types']['1'])) {
            // Default to type '1' if no specific type provided
            $typeConfig = $tca['types']['1'];
        } elseif (isset($tca['types']['0'])) {
            // Some tables use '0' as default
            $typeConfig = $tca['types']['0'];
        }
        
        // Parse showitem to get visible fields
        if (!empty($typeConfig['showitem'])) {
            $showItems = GeneralUtility::trimExplode(',', $typeConfig['showitem'], true);
            
            foreach ($showItems as $item) {
                $itemParts = GeneralUtility::trimExplode(';', $item, true);
                $fieldName = $itemParts[0];
                
                // Handle palettes
                if (strpos($fieldName, '--palette--') === 0) {
                    $paletteName = $itemParts[2] ?? '';
                    if (!empty($paletteName) && isset($tca['palettes'][$paletteName])) {
                        $paletteFields = $this->getPaletteFields($table, $paletteName);
                        $fields = array_merge($fields, $paletteFields);
                    }
                } 
                // Skip dividers
                elseif (strpos($fieldName, '--div--') !== 0) {
                    // Regular field
                    if (isset($tca['columns'][$fieldName])) {
                        $fields[$fieldName] = $tca['columns'][$fieldName];
                    }
                }
            }
        }
        
        // Apply field-level access restrictions
        foreach ($fields as $fieldName => $fieldConfig) {
            if (!$this->canAccessField($table, $fieldName)) {
                unset($fields[$fieldName]);
            }
        }
        
        return $fields;
    }
    
    /**
     * Get field names for a table and type (without full configuration)
     * 
     * @param string $table Table name
     * @param string $type Record type (optional)
     * @return array List of field names
     */
    public function getFieldNamesForType(string $table, string $type = ''): array
    {
        $fields = $this->getAvailableFields($table, $type);
        return array_keys($fields);
    }
    
    /**
     * Get restrictions for a table
     * 
     * @param string $table Table name
     * @return array List of restrictions
     */
    public function getTableRestrictions(string $table): array
    {
        $restrictions = [];
        
        // Check if entire table is read-only
        if ($this->isTableReadOnly($table)) {
            $restrictions[] = 'Table is read-only';
        }
        
        // Get field-level restrictions
        $fieldRestrictions = $this->getFieldRestrictions($table);
        $restrictions = array_merge($restrictions, $fieldRestrictions);
        
        return $restrictions;
    }
    
    
    /**
     * Check if a table is truly restricted and should not be accessible via MCP
     */
    protected function isRestrictedSystemTable(string $table): bool
    {
        // Admin-only tables (only restrict if user is not admin)
        if (!empty($GLOBALS['TCA'][$table]['ctrl']['adminOnly']) && !$this->getBackendUser()->isAdmin()) {
            return true;
        }
        
        // Root-level tables that are dangerous to modify
        if (!empty($GLOBALS['TCA'][$table]['ctrl']['rootLevel'])) {
            // Allow some safe root-level tables
            $allowedRootTables = [
                'sys_file_storage', // File storage configuration
                'sys_domain', // Domain configuration
                'sys_category', // Category system - safe for read operations
            ];
            
            if (!in_array($table, $allowedRootTables)) {
                return true;
            }
        }
        
        // Specific dangerous system tables that should never be accessed via MCP
        $restrictedTables = [
            'sys_log', // System log - read-only, managed by system
            'sys_history', // Change history - read-only, managed by system
            'sys_refindex', // Reference index - managed by system
            'sys_registry', // System registry - internal configuration
            'sys_lockedrecords', // Lock management - managed by system
            'be_sessions', // Backend sessions - security risk
            'fe_sessions', // Frontend sessions - security risk
            'cache_treelist', // Cache tables - managed by system
            'cache_pages', // Cache tables - managed by system
            'cache_pagesection', // Cache tables - managed by system
            'cache_hash', // Cache tables - managed by system
            'sys_be_shortcuts', // User shortcuts - user-specific
            'sys_news', // System news - admin-only
        ];
        
        if (in_array($table, $restrictedTables)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if a table is read-only
     */
    protected function isTableReadOnly(string $table): bool
    {
        // Check TCA configuration
        if (!empty($GLOBALS['TCA'][$table]['ctrl']['readOnly'])) {
            return true;
        }
        
        // Specific read-only tables that can be read but shouldn't be modified via MCP
        $readOnlyTables = [
            'sys_file', // Files are managed through file system, not direct DB edits
            'sys_file_processedfile', // Processed files are generated automatically
            'sys_file_storage', // Storage configuration - sensitive
            'sys_file_metadata', // File metadata - usually auto-generated
        ];
        
        if (in_array($table, $readOnlyTables)) {
            return true;
        }
        
        // Tables without essential fields are typically read-only
        $tca = $GLOBALS['TCA'][$table] ?? [];
        $ctrl = $tca['ctrl'] ?? [];
        
        // If table has no label field and no type field, it's likely a pure relation table
        // But relation tables like sys_file_reference should still be writable
        $hasLabel = !empty($ctrl['label']);
        $hasType = !empty($ctrl['type']);
        $isRelationTable = strpos($table, '_mm') !== false || 
                          strpos($table, 'sys_file_reference') !== false ||
                          strpos($table, 'sys_category_record_mm') !== false;
        
        // If it's a relation table, it should be writable regardless of label field
        if ($isRelationTable) {
            return false;
        }
        
        // Non-relation tables without label field are typically read-only
        if (!$hasLabel && !$hasType) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check user permissions for a table
     */
    protected function checkUserPermissions(string $table): array
    {
        $permissions = [
            'read' => false,
            'write' => false,
            'delete' => false,
        ];
        
        $backendUser = $this->getBackendUser();
        
        // Admin users have all permissions
        if ($backendUser->isAdmin()) {
            return [
                'read' => true,
                'write' => true,
                'delete' => true,
            ];
        }
        
        // Check if user has access to the table
        if ($backendUser->check('tables_select', $table)) {
            $permissions['read'] = true;
        }
        
        if ($backendUser->check('tables_modify', $table)) {
            $permissions['write'] = true;
            $permissions['delete'] = true;
        }
        
        // For pages table, check page permissions
        if ($table === 'pages') {
            // This is simplified - in real scenarios, page permissions are more complex
            $permissions['read'] = true;
            $permissions['write'] = $backendUser->check('tables_modify', 'pages');
            $permissions['delete'] = $permissions['write'];
        }
        
        return $permissions;
    }
    
    /**
     * Get field restrictions for a table
     */
    protected function getFieldRestrictions(string $table): array
    {
        $restrictions = [];
        $tca = $GLOBALS['TCA'][$table] ?? [];
        
        if (empty($tca['columns'])) {
            return $restrictions;
        }
        
        foreach ($tca['columns'] as $fieldName => $fieldConfig) {
            // Check exclude fields
            if (!empty($fieldConfig['exclude']) && !$this->getBackendUser()->check('non_exclude_fields', $table . ':' . $fieldName)) {
                $restrictions[] = "Field '{$fieldName}' is excluded for current user";
            }
            
            // Check displayCond
            if (!empty($fieldConfig['displayCond'])) {
                // This is simplified - displayCond evaluation is complex
                $restrictions[] = "Field '{$fieldName}' has display conditions";
            }
        }
        
        return $restrictions;
    }
    
    /**
     * Check if a specific field can be accessed
     */
    protected function canAccessField(string $table, string $fieldName): bool
    {
        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$fieldName] ?? [];
        
        // Check exclude fields
        if (!empty($fieldConfig['exclude'])) {
            $backendUser = $this->getBackendUser();
            if (!$backendUser->isAdmin() && !$backendUser->check('non_exclude_fields', $table . ':' . $fieldName)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get palette fields
     */
    protected function getPaletteFields(string $table, string $paletteName): array
    {
        $fields = [];
        $tca = $GLOBALS['TCA'][$table] ?? [];
        
        if (!isset($tca['palettes'][$paletteName])) {
            return $fields;
        }
        
        $paletteConfig = $tca['palettes'][$paletteName];
        
        if (!empty($paletteConfig['showitem'])) {
            $showItems = GeneralUtility::trimExplode(',', $paletteConfig['showitem'], true);
            
            foreach ($showItems as $item) {
                $itemParts = GeneralUtility::trimExplode(';', $item, true);
                $fieldName = $itemParts[0];
                
                // Skip special items
                if (strpos($fieldName, '--') === 0) {
                    continue;
                }
                
                if (isset($tca['columns'][$fieldName])) {
                    $fields[$fieldName] = $tca['columns'][$fieldName];
                }
            }
        }
        
        return $fields;
    }
    
    /**
     * Get control information for a table
     */
    protected function getTableControlInfo(string $table): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        
        // Extract only relevant control fields
        $relevantFields = [
            'title', 'label', 'label_alt', 'label_alt_force',
            'descriptionColumn', 'type', 'languageField',
            'transOrigPointerField', 'delete', 'enablecolumns',
            'sortby', 'default_sortby', 'tstamp', 'crdate',
            'versioningWS', 'origUid', 'searchFields',
        ];
        
        $controlInfo = [];
        foreach ($relevantFields as $field) {
            if (isset($ctrl[$field])) {
                $controlInfo[$field] = $ctrl[$field];
            }
        }
        
        return $controlInfo;
    }
}