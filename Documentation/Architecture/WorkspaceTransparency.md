# Workspace Transparency Implementation

## Overview

This document describes how the MCP server implements workspace transparency for TYPO3 content management. Our goal is to provide LLM clients with a simplified view of content that abstracts away the complexity of TYPO3's workspace system.

## Background: TYPO3's Workspace System

TYPO3's workspace system allows editors to make changes in isolated environments before publishing to the live site. The system uses:

- **Live records**: The published content visible on the frontend (workspace ID = 0)
- **Workspace versions**: Modified copies of live records in a specific workspace
- **Version states**: Including new records, modified records, and delete placeholders
- **Overlay mechanism**: Post-query processing to apply workspace changes to live data

### Standard TYPO3 Approach

TYPO3 provides the `WorkspaceRestriction` class which:
1. In live workspace: Fetches only records with `t3ver_wsid = 0`
2. In other workspaces: Fetches both live records AND workspace versions
3. Expects consuming code to apply overlays using `BackendUtility::workspaceOL()`

This approach works well for the TYPO3 backend where:
- Editors understand workspace concepts
- The UI shows version states explicitly
- Code can handle the complexity of overlays

## Our Requirements

For an LLM-focused API, we needed:
- **Transparent operations**: Workspace complexity hidden from the client
- **Consistent UIDs**: The same logical record always has the same ID
- **Automatic handling**: No need for clients to understand workspace states
- **True deletions**: Deleted records should not appear in results

## Implementation Details

### 1. Custom Delete Placeholder Restriction

We created `WorkspaceDeletePlaceholderRestriction` to handle a specific gap in TYPO3's standard restrictions:

```php
// Adds SQL constraint to exclude live records that have delete placeholders
$constraints[] = $expressionBuilder->notIn(
    $tableAlias . '.uid',
    $subQuery->getSQL()  // Finds records with t3ver_state = 2 in current workspace
);
```

This ensures that when a record is deleted in a workspace (creating a delete placeholder), the live version is automatically excluded from query results.

### 2. UID Resolution System

We implemented two key methods for UID transparency:

**`getLiveUid()`**: Converts workspace UIDs back to live UIDs
- For new records (t3ver_state = 1), returns the workspace UID as the "live" UID
- For modified records, returns the original live UID (t3ver_oid)
- Ensures clients always see consistent IDs

**`resolveToWorkspaceUid()`**: Finds the workspace version of a live record
- Used when updating/deleting records by their live UID
- Automatically finds the workspace version if it exists
- Falls back to creating a new workspace version if needed

### 3. Query-Time Workspace Handling

In `ReadTableTool`, when querying by UID in a workspace:

```php
// Query for both the live UID and records where t3ver_oid matches
$queryBuilder->andWhere(
    $queryBuilder->expr()->or(
        $queryBuilder->expr()->eq('uid', $uid),
        $queryBuilder->expr()->eq('t3ver_oid', $uid)
    )
);
```

This ensures that:
- Queries by live UID find the workspace version
- New workspace records are found by their workspace UID
- Delete placeholders are automatically excluded

### 4. Search Result Processing

The `SearchTool` applies additional processing to ensure consistency:
- Filters out records with delete placeholders
- Returns live UIDs even when workspace versions are found
- De-duplicates results when both live and workspace versions match

## Design Rationale

### Why Not Use Standard Overlays?

TYPO3's overlay approach (`workspaceOL()`) works by:
1. Fetching records from the database
2. Post-processing each record to apply workspace changes
3. Modifying or removing records based on workspace state

This didn't meet our needs because:
- **Timing**: Overlays happen after queries execute, so deleted records are still fetched
- **Complexity**: Each tool would need to implement overlay logic
- **Transparency**: Clients would see workspace artifacts during processing

### Benefits of Our Approach

1. **True Transparency**: Workspace logic is handled at the query level
2. **Consistent Behavior**: All tools behave the same way automatically
3. **No Post-Processing**: Results are correct without additional steps
4. **Performance**: Fewer records fetched when deletions are involved

## Limitations and Considerations

1. **TYPO3 Compatibility**: Our approach diverges from standard TYPO3 patterns, which may affect integration with other extensions

2. **Complexity**: We handle more logic at the query level, making queries more complex

3. **Edge Cases**: Some workspace scenarios (like move operations) still require special handling

## Testing

Our implementation is extensively tested in:
- `WorkspaceEdgeCaseTest`: Tests delete placeholders, new records, and complex scenarios
- `WriteTableToolTest`: Verifies UID resolution and workspace creation
- `SearchToolTest`: Ensures search results respect workspace state

## Conclusion

Our workspace transparency implementation provides a practical solution for exposing TYPO3 content to LLMs. By handling workspace complexity at the query level rather than through post-processing, we achieve true transparency while maintaining data integrity. This approach may differ from standard TYPO3 patterns, but it effectively meets the specific requirements of an LLM-focused API.