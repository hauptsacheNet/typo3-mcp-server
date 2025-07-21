# Inline Relations in TYPO3 MCP Tools

## Overview

TYPO3 inline relations (IRRE - Inline Relational Record Editing) allow records to be related to each other in parent-child relationships. The MCP tools support both reading and writing inline relations with some limitations.

## Types of Inline Relations

### 1. Independent Inline Relations
- Tables that exist independently (e.g., `tt_content`)
- Have `hideTable = false` in TCA
- Displayed as UIDs in read results
- Can be managed through foreign field updates

Example:
```php
// News with content elements
$result = $readTool->execute([
    'table' => 'tx_news_domain_model_news',
    'uid' => 123
]);
// Returns: 
// ['content_elements' => [456, 789, 1011]]  // Array of UIDs
```

### 2. Embedded/Dependent Inline Relations
- Tables that only exist as children (e.g., `sys_file_reference`, `tx_news_domain_model_link`)
- Have `hideTable = true` in TCA
- Displayed as full embedded records in read results
- Should be created together with parent record

Example:
```php
// Page with file references
$result = $readTool->execute([
    'table' => 'pages',
    'uid' => 123
]);
// Returns:
// ['media' => [
//     ['uid' => 1, 'title' => 'Image 1', 'uid_local' => 5, ...],
//     ['uid' => 2, 'title' => 'Image 2', 'uid_local' => 6, ...]
// ]]  // Full record data
```

## Writing Inline Relations

### Method 1: Foreign Field Update (Currently Working)
For independent relations, update the foreign field on child records:

```php
// Create content element related to news
$writeTool->execute([
    'table' => 'tt_content',
    'action' => 'create',
    'pid' => 1,
    'data' => [
        'header' => 'Related content',
        'CType' => 'text',
        'tx_news_related_news' => 789  // Foreign field
    ]
]);
```

### Method 2: Parent Record Update with UIDs (Implemented)
For independent relations, pass array of UIDs when updating parent:

```php
// Update news with content elements
$writeTool->execute([
    'table' => 'tx_news_domain_model_news',
    'action' => 'update',
    'uid' => 123,
    'data' => [
        'content_elements' => [456, 789, 1011]  // Array of UIDs
    ]
]);
```

### Method 3: Embedded Record Creation (Partially Working)
For dependent relations, pass record data when creating parent:

```php
// Create news with embedded links
$writeTool->execute([
    'table' => 'tx_news_domain_model_news',
    'action' => 'create',
    'pid' => 1,
    'data' => [
        'title' => 'News with links',
        'related_links' => [
            ['title' => 'Link 1', 'uri' => 'https://example.com'],
            ['title' => 'Link 2', 'uri' => 't3://page?uid=42']
        ]
    ]
]);
```

## Current Implementation

### 1. Embedded Relations with Direct Database Updates
- Creating embedded relations through parent record works with a two-step process:
  1. DataHandler creates the child records with placeholder IDs
  2. Direct database UPDATE sets the foreign field after creation
- The foreign field (e.g., `parent` in `tx_news_domain_model_link`) is NOT defined in the child table's TCA columns by design
- DataHandler only processes fields that are defined in TCA columns, so it ignores the foreign field
- **Solution**: After DataHandler creates child records, we directly update the foreign field in the database
- **Working Example**: Creating news with embedded links now works correctly

### 2. Restricted Tables
- `sys_file_reference` is intentionally restricted in MCP tools because file references don't properly support workspaces
- This is a deliberate limitation for now to ensure data integrity in workspace contexts
- File uploads and media management require special handling outside of MCP tools

### 3. Automatic Workspace Handling
- Both ReadTableTool and WriteTableTool automatically initialize workspace context via `WorkspaceContextService`
- The service finds the first writable workspace or creates a new "MCP Workspace" if needed
- All operations (read/write) happen in the same workspace automatically
- No manual workspace management is needed in tests or usage
- Workspace transparency is maintained - tools work consistently across workspaces

### 4. Sorting and Positioning
- Sorting is handled through `sorting` or `sorting_foreign` fields
- Position management (before/after) not fully implemented for inline relations

## Best Practices

1. **Use Foreign Field Method**: Most reliable method for creating inline relations
2. **Check Table Configuration**: Verify if table has `hideTable` to determine relation type
3. **Validate Data**: Ensure proper validation for inline relation data
4. **Handle Errors**: Check DataHandler error log for detailed error messages
5. **Test Thoroughly**: Inline relations can behave differently in various contexts

## Technical Details

### TCA Configuration
Inline relations are defined in TCA with type 'inline':

```php
'content_elements' => [
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tt_content',
        'foreign_field' => 'tx_news_related_news',
        'foreign_sortby' => 'sorting',
    ]
]
```

### DataHandler Multi-Table Format
DataHandler supports multi-table operations with placeholders:

```php
$dataMap = [
    'parent_table' => [
        'NEW123' => ['field' => 'value']
    ],
    'child_table' => [
        'NEW456' => ['other_field' => 'value']  // Note: foreign field NOT included
    ]
];
// After DataHandler processing, foreign fields are updated directly:
$connection->update('child_table', ['parent_field' => $parentUid], ['uid' => $childUid]);
```

## Future Improvements

1. **File Reference Support**: Add special handling for sys_file_reference when workspace support improves
2. **Batch Operations**: Support for bulk inline relation updates
3. **Position Management**: Full support for positioning inline records (before/after specific records)
4. **Validation Enhancement**: More comprehensive validation for embedded record data
5. **Performance Optimization**: Batch foreign field updates instead of individual UPDATE queries