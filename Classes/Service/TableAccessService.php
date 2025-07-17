<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
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
    protected TcaSchemaFactory $tcaSchemaFactory;
    
    public function __construct()
    {
        $this->workspaceContextService = GeneralUtility::makeInstance(WorkspaceContextService::class);
        $this->tcaSchemaFactory = GeneralUtility::makeInstance(TcaSchemaFactory::class);
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
        
        // Check if schema exists for this table
        if (!$this->tcaSchemaFactory->has($table)) {
            return [];
        }
        
        $schema = $this->tcaSchemaFactory->get($table);
        $fields = [];
        
        // If a specific type is provided and the schema supports sub-schemas
        if (!empty($type) && $schema->hasSubSchema($type)) {
            $subSchema = $schema->getSubSchema($type);
            
            // Handle subtypes (old plugin system) - this will be deprecated
            if ($subSchema->getSubTypeDivisorField() !== null) {
                // For subtypes, we need the record data to determine the correct sub-schema
                // Since we don't have record data here, use the main sub-schema
                // The caller should handle subtype resolution if needed
            }
            
            // Get fields from the sub-schema
            foreach ($subSchema->getFields() as $field) {
                $fieldName = $field->getName();
                $fields[$fieldName] = $field->getConfiguration();
            }
        } else {
            // No specific type or type doesn't exist - use main schema
            // Try to fall back to a reasonable default type
            if (empty($type) && $schema->supportsSubSchema()) {
                // Get the default type from TCA configuration
                $tca = $GLOBALS['TCA'][$table] ?? [];
                $defaultType = $tca['columns'][$schema->getSubSchemaTypeInformation()->getFieldName()]['config']['default'] ?? '';
                
                if (!empty($defaultType) && $schema->hasSubSchema($defaultType)) {
                    $subSchema = $schema->getSubSchema($defaultType);
                    foreach ($subSchema->getFields() as $field) {
                        $fieldName = $field->getName();
                        $fields[$fieldName] = $field->getConfiguration();
                    }
                } else {
                    // No reasonable default found, use all main schema fields
                    foreach ($schema->getFields() as $field) {
                        $fieldName = $field->getName();
                        $fields[$fieldName] = $field->getConfiguration();
                    }
                }
            } else {
                // Use main schema fields
                foreach ($schema->getFields() as $field) {
                    $fieldName = $field->getName();
                    $fields[$fieldName] = $field->getConfiguration();
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
    
    
    // =============================================================================
    // UTILITY METHODS FOR COMMON TCA OPERATIONS
    // =============================================================================
    
    /**
     * Get the table title (label) for a table
     */
    public function getTableTitle(string $table): string
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        return $ctrl['title'] ?? $table;
    }
    
    /**
     * Get the type field name for a table
     */
    public function getTypeFieldName(string $table): ?string
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        return $ctrl['type'] ?? null;
    }
    
    /**
     * Get the label field name for a table
     */
    public function getLabelFieldName(string $table): ?string
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        return $ctrl['label'] ?? null;
    }
    
    /**
     * Get the sorting field name for a table
     */
    public function getSortingFieldName(string $table): ?string
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        return $ctrl['sortby'] ?? null;
    }
    
    /**
     * Get the default sorting configuration for a table
     */
    public function getDefaultSorting(string $table): ?string
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        return $ctrl['default_sortby'] ?? null;
    }
    
    /**
     * Get the timestamp field name for a table
     */
    public function getTimestampFieldName(string $table): ?string
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        return $ctrl['tstamp'] ?? null;
    }
    
    /**
     * Get the creation date field name for a table
     */
    public function getCreationDateFieldName(string $table): ?string
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        return $ctrl['crdate'] ?? null;
    }
    
    /**
     * Get the language field name for a table
     */
    public function getLanguageFieldName(string $table): ?string
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        return $ctrl['languageField'] ?? null;
    }
    
    /**
     * Get the hidden field name for a table
     */
    public function getHiddenFieldName(string $table): ?string
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        return $ctrl['enablecolumns']['disabled'] ?? null;
    }
    
    /**
     * Get the search fields for a table
     */
    public function getSearchFields(string $table): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $searchFields = $ctrl['searchFields'] ?? '';
        
        if (empty($searchFields)) {
            return [];
        }
        
        return GeneralUtility::trimExplode(',', $searchFields, true);
    }
    
    /**
     * Get essential fields for a table (fields that should always be included)
     */
    public function getEssentialFields(string $table): array
    {
        $essentialFields = ['uid', 'pid'];
        
        // Add type field if it exists
        if ($typeField = $this->getTypeFieldName($table)) {
            $essentialFields[] = $typeField;
        }
        
        // Add label field if it exists
        if ($labelField = $this->getLabelFieldName($table)) {
            $essentialFields[] = $labelField;
        }

        // Add language field if it exists
        if ($languageField = $this->getLanguageFieldName($table)) {
            $essentialFields[] = $languageField;
        }

        // Add timestamp fields if they exist
        if ($tstampField = $this->getTimestampFieldName($table)) {
            $essentialFields[] = $tstampField;
        }

        if ($crdateField = $this->getCreationDateFieldName($table)) {
            $essentialFields[] = $crdateField;
        }

        // Add hidden field if it exists
        if ($hiddenField = $this->getHiddenFieldName($table)) {
            $essentialFields[] = $hiddenField;
        }

        // Add sorting field if it exists
        if ($sortingField = $this->getSortingFieldName($table)) {
            $essentialFields[] = $sortingField;
        }
        
        return array_unique($essentialFields);
    }
    
    /**
     * Get available types for a table
     */
    public function getAvailableTypes(string $table): array
    {
        $typeField = $this->getTypeFieldName($table);
        if (!$typeField) {
            return ['1' => 'Default'];
        }
        
        $typeConfig = $GLOBALS['TCA'][$table]['columns'][$typeField]['config'] ?? [];
        $items = $typeConfig['items'] ?? [];
        
        // Use the shared parseSelectItems method
        $parsed = $this->parseSelectItems($items);
        
        // Convert to the expected format (value => label)
        $types = [];
        foreach ($parsed['values'] as $value) {
            $types[$value] = $parsed['labels'][$value] ?? $value;
        }
        
        return $types;
    }
    
    /**
     * Get the field configuration for a specific field
     */
    public function getFieldConfig(string $table, string $fieldName): ?array
    {
        return $GLOBALS['TCA'][$table]['columns'][$fieldName] ?? null;
    }
    
    /**
     * Check if a field is a date field
     */
    public function isDateField(string $table, string $fieldName): bool
    {
        // Common date fields in TYPO3
        $commonDateFields = ['tstamp', 'crdate', 'starttime', 'endtime', 'lastlogin', 'date'];
        
        if (in_array($fieldName, $commonDateFields)) {
            return true;
        }
        
        // Check TCA eval for date/datetime/time
        $fieldConfig = $this->getFieldConfig($table, $fieldName);
        if (!$fieldConfig) {
            return false;
        }
        
        $config = $fieldConfig['config'] ?? [];
        
        // Check eval rules
        if (!empty($config['eval'])) {
            $evalRules = GeneralUtility::trimExplode(',', $config['eval'], true);
            if (in_array('date', $evalRules) || in_array('datetime', $evalRules) || in_array('time', $evalRules)) {
                return true;
            }
        }
        
        // Check renderType for inputDateTime
        if (($config['renderType'] ?? '') === 'inputDateTime') {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if a field is a FlexForm field
     */
    public function isFlexFormField(string $table, string $fieldName): bool
    {
        $fieldConfig = $this->getFieldConfig($table, $fieldName);
        if (!$fieldConfig) {
            return false;
        }
        
        $config = $fieldConfig['config'] ?? [];
        if (!empty($config['type']) && $config['type'] === 'flex') {
            return true;
        }
        
        // Check common FlexForm field names
        $knownFlexFormFields = [
            'tt_content' => ['pi_flexform'],
            'pages' => ['tx_templavoila_flex'],
        ];
        
        if (isset($knownFlexFormFields[$table]) && in_array($fieldName, $knownFlexFormFields[$table])) {
            return true;
        }
        
        // Check field name pattern
        if (strpos($fieldName, 'flexform') !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Parse default sorting configuration into field/direction pairs
     */
    public function parseDefaultSorting(string $table): array
    {
        $defaultSorting = $this->getDefaultSorting($table);
        if (!$defaultSorting) {
            return [];
        }
        
        $sortParts = GeneralUtility::trimExplode(',', $defaultSorting, true);
        $sorting = [];
        
        foreach ($sortParts as $sortPart) {
            $sortPart = trim($sortPart);
            
            // Extract field and direction
            if (preg_match('/^(.*?)\s+(ASC|DESC)$/i', $sortPart, $matches)) {
                $field = trim($matches[1]);
                $direction = strtoupper($matches[2]);
                $sorting[] = ['field' => $field, 'direction' => $direction];
            } else {
                // Default to ASC if no direction specified
                $sorting[] = ['field' => $sortPart, 'direction' => 'ASC'];
            }
        }
        
        return $sorting;
    }
    
    /**
     * Parse select field items from TCA configuration
     * 
     * @param array $items TCA items array
     * @param bool $skipDividers Whether to skip divider items
     * @return array Array with 'values' and 'labels' keys
     */
    public function parseSelectItems(array $items, bool $skipDividers = true): array
    {
        $result = [
            'values' => [],
            'labels' => []
        ];
        
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            
            $itemValue = '';
            $itemLabel = '';
            
            // Handle both associative and numeric index syntax
            if (isset($item['value']) && isset($item['label'])) {
                // New associative syntax
                $itemValue = $item['value'];
                $itemLabel = $item['label'];
            } elseif (isset($item[0]) && isset($item[1])) {
                // Old numeric index syntax
                $itemValue = $item[1];
                $itemLabel = $item[0];
            } elseif (isset($item['numIndex']) && is_array($item['numIndex'])) {
                // XML converted to array format
                if (isset($item['numIndex']['label']) && isset($item['numIndex']['value'])) {
                    $itemLabel = $item['numIndex']['label'];
                    $itemValue = $item['numIndex']['value'];
                }
            }
            
            // Skip dividers if requested
            if ($skipDividers && $itemValue === '--div--') {
                continue;
            }
            
            if ($itemValue !== '') {
                $result['values'][] = (string)$itemValue;
                $result['labels'][$itemValue] = $itemLabel;
            }
        }
        
        return $result;
    }
    
    /**
     * Get allowed values for a select field
     * 
     * @param string $table Table name
     * @param string $fieldName Field name
     * @return array|null Array of allowed values or null if not a select field
     */
    public function getSelectFieldAllowedValues(string $table, string $fieldName): ?array
    {
        $fieldConfig = $this->getFieldConfig($table, $fieldName);
        if (!$fieldConfig) {
            return null;
        }
        
        $config = $fieldConfig['config'] ?? [];
        
        // Only process select fields
        if (($config['type'] ?? '') !== 'select') {
            return null;
        }
        
        // If it's a foreign table select, we can't validate values here
        if (!empty($config['foreign_table'])) {
            return null;
        }
        
        // Use the shared parseSelectItems method
        if (isset($config['items']) && is_array($config['items'])) {
            $parsed = $this->parseSelectItems($config['items']);
            return empty($parsed['values']) ? null : $parsed['values'];
        }
        
        return null;
    }
    
    /**
     * Validate a field value based on its TCA configuration
     * 
     * @param string $table Table name
     * @param string $fieldName Field name
     * @param mixed $value Field value
     * @return string|null Error message if validation fails, null if valid
     */
    public function validateFieldValue(string $table, string $fieldName, $value): ?string
    {
        $fieldConfig = $this->getFieldConfig($table, $fieldName);
        if (!$fieldConfig) {
            return "Field '{$fieldName}' does not exist in table '{$table}'";
        }
        
        $config = $fieldConfig['config'] ?? [];
        $fieldType = $config['type'] ?? '';
        
        // Check max length for string fields
        if (in_array($fieldType, ['input', 'text', 'email', 'link', 'slug', 'color']) && is_string($value)) {
            $maxLength = $config['max'] ?? 0;
            if ($maxLength > 0 && mb_strlen($value) > $maxLength) {
                return "Field '{$fieldName}' value exceeds maximum length of {$maxLength} characters";
            }
        }
        
        // Validate select fields
        if ($fieldType === 'select' && empty($config['foreign_table'])) {
            $allowedValues = $this->getSelectFieldAllowedValues($table, $fieldName);
            if ($allowedValues !== null) {
                // Handle comma-separated values for multiple select
                $values = is_string($value) ? GeneralUtility::trimExplode(',', $value, true) : [$value];
                
                foreach ($values as $val) {
                    if (!in_array((string)$val, $allowedValues, true)) {
                        $allowedList = implode(', ', array_map(function($v) { return "'{$v}'"; }, $allowedValues));
                        return "Field '{$fieldName}' value '{$val}' must be one of: {$allowedList}";
                    }
                }
            }
        }
        
        // Validate required fields
        if (!empty($config['required']) || !empty($config['eval'])) {
            $evalRules = GeneralUtility::trimExplode(',', $config['eval'] ?? '', true);
            if (!empty($config['required']) || in_array('required', $evalRules)) {
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    return "Field '{$fieldName}' is required";
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get record title/label using TYPO3's BackendUtility
     */
    public function getRecordTitle(string $table, array $record): string
    {
        return BackendUtility::getRecordTitle($table, $record);
    }
    
    /**
     * Translate a TCA label using TYPO3's language system
     */
    public function translateLabel(string $label): string
    {
        if (strpos($label, 'LLL:') === 0) {
            // Check if language service is available
            if (!isset($GLOBALS['LANG'])) {
                // In test context or when LANG is not available, return a fallback
                // Extract the last part of the LLL path as fallback
                if (preg_match('/\.([^:]+):?$/', $label, $matches)) {
                    return ucfirst(str_replace('_', ' ', $matches[1]));
                }
                return $label;
            }
            
            $translated = $GLOBALS['LANG']->sL($label);
            
            // If translation failed, try to extract a meaningful fallback
            if (empty($translated)) {
                // Extract the last part of the LLL path as fallback
                // e.g., "LLL:EXT:news/Resources/Private/Language/locallang_be.xlf:plugin.news_list.title" -> "news_list"
                if (preg_match('/\.([^.]+)\.title$/', $label, $matches)) {
                    return str_replace('_', ' ', ucfirst($matches[1]));
                }
                
                // For other patterns, extract the last meaningful part
                if (preg_match('/[:\.]([^:.]+)$/', $label, $matches)) {
                    return str_replace(['_', '-'], ' ', ucfirst($matches[1]));
                }
                
                // Last resort: return the raw LLL reference (better than empty)
                return $label;
            }
            
            return $translated;
        }
        
        return $label;
    }
}