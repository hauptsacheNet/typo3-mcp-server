# Inline Relations in TYPO3 MCP Tools

## Overview

TYPO3 inline relations (IRRE - Inline Relational Record Editing) allow records to be related to each other in parent-child relationships. The MCP tools support both reading and writing inline relations using DataHandler's native inline handling.

## Types of Inline Relations

### 1. Independent Inline Relations
- Tables that exist independently (e.g., `tt_content`)
- Have `hideTable = false` in TCA
- Displayed as UIDs in read results
- Written by passing an array of UIDs

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
- Written by passing record data arrays

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

### Method 1: Foreign Field Update
For independent relations, update the foreign field on child records directly:

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

### Method 2: Parent Record with UIDs
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
// Children not in the list are automatically unlinked (foreign_field set to 0)
```

### Method 3: Embedded Record Creation
For dependent relations, pass record data arrays:

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
// On update, children not in the list are automatically deleted
```

### File References
File fields accept sys_file UIDs or record data objects:

```php
$writeTool->execute([
    'table' => 'tt_content',
    'action' => 'create',
    'pid' => 1,
    'data' => [
        'header' => 'With images',
        'CType' => 'textmedia',
        'assets' => [3, 4]  // sys_file UIDs (shorthand)
    ]
]);
// Context fields (tablenames, fieldname, table_local) are set server-side
```

## Implementation

### Single DataHandler Call (Unified dataMap)

All inline relations — parent record and children — are processed in a **single DataHandler call** using a unified `$dataMap`. DataHandler natively resolves NEW key references, sets foreign_field values, and handles workspace versioning atomically.

```php
$dataMap = [
    'tx_news_domain_model_news' => [
        'NEWparent' => [
            'title' => 'News with links',
            'pid' => 1,
            'related_links' => 'NEWchild1,NEWchild2',  // CSV of child keys
        ],
    ],
    'tx_news_domain_model_link' => [
        'NEWchild1' => ['title' => 'Link 1', 'uri' => 'https://example.com', 'pid' => 1],
        'NEWchild2' => ['title' => 'Link 2', 'uri' => 't3://page?uid=42', 'pid' => 1],
    ],
];
$dataHandler->start($dataMap, []);
$dataHandler->process_datamap();
```

Key methods:
- **`extractInlineRelations()`** — Separates inline/file fields from parent data
- **`buildInlineDataMap()`** — Builds the unified dataMap with NEW keys and CSV references, handles nested inline relations recursively
- **`syncInlineRelations()`** — On updates only: deletes/unlinks children absent from the new list (DataHandler's raw dataMap does not handle relation sync — that's FormEngine's job)

### Relation Sync on Updates

DataHandler's raw `process_datamap()` does **not** automatically remove children that are no longer listed. The `syncInlineRelations()` method handles this explicitly:

- **Embedded tables** (`hideTable = true`): Absent children are deleted via DataHandler `cmdMap`
- **Independent tables** (`hideTable = false`): Absent children have their `foreign_field` cleared via DataHandler `dataMap`

Both paths go through DataHandler, ensuring proper workspace versioning.

### File Reference Security

For `sys_file_reference`, context fields are **always set server-side**:
- `tablenames` → parent table name
- `fieldname` → parent field name
- `table_local` → `sys_file`

Client-supplied values for these fields are overwritten to prevent cross-table reference manipulation.

### Automatic Workspace Handling
- Both ReadTableTool and WriteTableTool automatically initialize workspace context via `WorkspaceContextService`
- The service finds the first writable workspace or creates a new "MCP Workspace" if needed
- All operations (read/write) happen in the same workspace automatically
- Workspace transparency is maintained — tools expose live UIDs only

## Best Practices

1. **Use Embedded Data for Hidden Tables**: Pass record data arrays for `hideTable` relations, UIDs for independent tables
2. **Check Table Configuration**: Verify `hideTable` in TCA to determine the relation type
3. **Update Replaces Relations**: On updates, the provided list is the complete new state — absent children are removed
4. **Nested Relations**: Supported recursively (e.g., file references on inline children)
5. **Test Thoroughly**: Check all MCP tool calls for failure: `$this->assertFalse($result->isError, ...)`

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

### Read vs Write Behavior

| Aspect | Read (ReadTableTool) | Write (WriteTableTool) |
|--------|---------------------|----------------------|
| **Detection** | `hideTable` TCA flag | Per-item: arrays = embedded, integers = UIDs |
| **Hidden tables** | Full embedded records | Accepts record data arrays |
| **Visible tables** | UIDs only | Accepts UID arrays |
| **Sorting** | `foreign_sortby` or default | CSV position determines order |

## Future Improvements

1. **Batch Operations**: Optimized bulk inline relation updates
2. **Position Management**: Full support for positioning inline records (before/after specific records)
3. **Recursion Limits**: Configurable depth limit for nested inline relations
4. **Field Allowlisting**: Restrict which fields can be set on inline children
