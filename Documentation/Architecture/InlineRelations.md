# Inline Relations

How `ReadTable` and `WriteTable` handle TCA fields of type `inline` and `file`, and
why writing them takes more than a single DataHandler run.

## Two kinds of child tables

Every inline field points at a child table (`foreign_table`). The MCP treats the
children in one of two ways, decided by `TableAccessService::isEmbeddedChildTable()`:

| | Embedded children | Independent children |
|---|---|---|
| Child table | `hideTable = true` in TCA | everything else |
| Examples | `sys_file_reference`, `tx_news_domain_model_link`, Content Blocks collections | `tt_content` below a news record |
| Read result | full child records inside the parent | list of child uids |
| Write input | list of child records | list of child uids |
| Own CRUD | no, only through the parent | yes, like any table |

```php
// Read: embedded file references, independent content elements
['media' => [['uid' => 1, 'uid_local' => 5, 'alternative' => '…'], …],
 'content_elements' => [456, 789]]
```

### Standalone `hideTable` tables

Some `hideTable` tables are too complex to edit through their parent, e.g. because
they carry their own translations. The extension setting `additionalStandaloneTables`
lists tables that are treated as independent tables despite `hideTable`: `ListTables`
shows them, `ReadTable`/`WriteTable` allow direct CRUD, and the parent's field becomes
a uid list. The TCA is not changed, so they stay hidden in the backend list module.

`sys_file_metadata` is on the list by default: it has its own `languageField`, while
its parent `sys_file` is read-only and not language-aware. Embedding it made
translations invisible and edits through `sys_file` impossible. Mount restrictions of
`sys_file` apply to it through `SysFileMetadataRestrictionListener`, which also hides
metadata of files that no longer exist.

## Writing embedded children

The value is the complete list of children; the array order is the display order.

```php
'related_links' => [
    ['uid' => 12, 'title' => 'Changed title'],   // existing child: patched
    ['title' => 'New link', 'uri' => 'https://example.com'], // no uid: created
]
// Existing children missing from the list are deleted.
```

- A `uid` must belong to the current parent. Children cannot be moved between parents;
  a foreign uid is rejected instead of silently deleting the real children.
- When a workspace is active, an existing child is written on its workspace version, never on the live row.
- Children can have embedded children themselves (a file inside a collection item).
  Nested relations to independent tables are rejected: those records need their own
  write so the client gets their uids back.

### Why it takes several steps

DataHandler only writes columns that are defined in the child table's TCA. The
columns that tie a child to its parent usually are not:

- the foreign field (`foreign_field`, e.g. `parent`),
- `foreign_table_field` (e.g. `tablenames`) and the `foreign_match_fields`
  (e.g. `fieldname`) of tables that don't declare them as columns.

DataHandler drops these values from the datamap, and TYPO3 can't find the child
again: the backend form, the page module and the Record API show an empty relation.
`WriteTable` therefore writes the parent and children in one DataHandler run and then
finishes the job itself (`finalizeEmbeddedInlineRelations()`):

1. **Link new children**: one direct `UPDATE` per new child sets the foreign field
   (the parent's live uid), `foreign_table_field` and `foreign_match_fields`. Nested
   children are linked the same way (`writePendingNestedForeignFields()`).
2. **Enforce the order**: DataHandler numbers the sortby field itself (new records at
   the top, so several new children end up reversed), so the array order is applied
   afterwards with a chain of `move after` commands, as the backend does.
3. **Parent bookkeeping**: because the children were linked after the parent was
   written, the parent field's counter column is still 0 and `sys_refindex` has no
   rows for the relation. `syncInlineParentBookkeeping()` sets both, on the parent's
   workspace version only. Without the `sys_refindex` rows the workspace dependency
   resolver doesn't see the children, and publishing the parent would leave them in
   the workspace.

`ReferenceIndex::updateRefIndexTable()` is `@internal`, but it is what the
`referenceindex:update` command uses; core has no public per-record API.

## Writing independent children

The value is a list of uids. On update the foreign field of all current children is
cleared and set on the listed ones, both through DataHandler, so the change is staged
in the workspace like any other edit. A listed uid that belongs to another parent is
taken over from it.

```php
'content_elements' => [456, 789, 1011]
```

A child can also be attached from its own side by writing its foreign field, e.g.
`tt_content.tx_news_related_news`.

## Workspaces

Like everything else, inline relations are written to the workspace and read back as
if there were none: children carry their live uids, records created in the workspace
their own uid.

### Parents are versioned with their children

In the backend a child is edited through its parent's form, so saving it versions the
parent too and the workspace module lists the parent as modified. `WriteTable` writes
children on their own, so `InlineParentService::markModified()` does the same after
every write in a workspace:

- The written record and every inline ancestor get a workspace version if they don't
  have one (`version` / `new` cmdmap). Records created, versioned, deleted or moved in
  the workspace are left alone.
- Parents are resolved from the child's foreign field on the child's workspace state,
  recursively (`sys_file_reference` → `tt_content` → news). Where a
  `foreign_table_field` exists, it and the foreign field identify the parent;
  `fieldname` is not matched, since a FlexForm file field is no TCA column.
- Former parents count too: deleting a content element, pointing it at another news or
  listing it in another news' `content_elements` marks the old news as well.
- Parents that are not workspace-aware (e.g. `sys_file`) or not modifiable by the user
  are skipped.

The parent version is an unchanged copy, so reads stay transparent. As on a form save,
DataHandler versions the parent's workspace-aware children along with it, so the first
change below a news record stages unchanged versions of its other content elements.

While doing so, core renumbers the children's `sorting` and bumps their `tstamp`
directly on the live rows (`RelationHandler::writeForeignField()`). Live must not
change before publishing, so `InlineParentService` reads the descendants before
versioning and writes back every changed column afterwards.

### Grouping in the workspace module

The workspace module groups a parent with its children by the parent's `sys_refindex`
rows in the workspace. DataHandler only reindexes the record it writes, so
`InlineParentService` also recomputes the rows of the written record and every
ancestor.

## Known gaps

- **Independent children**: `foreign_table_field` is not set and the parent's counter
  column is not updated (#136, #137).
- **Relations without `foreign_field`** (uid list stored on the parent) are not
  written and can't be traced from child to parent.
- **Reassigned children in the workspace module**: core resolves foreign field
  children from the live rows, so a child pointed at another parent in the workspace
  stays grouped below its old parent until it is published. Publishing itself is
  correct.
