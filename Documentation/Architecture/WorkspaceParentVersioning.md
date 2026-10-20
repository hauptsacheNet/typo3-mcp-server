# Workspace Parent Versioning

## Decision: Version the outermost parent of every child record changed in a workspace

### Context

TYPO3 only versions the record that actually changes. When a content element of a news record is edited in a
workspace, or an image is added to a content element, the parent record stays live-only. The Workspaces module
then lists the children without any context ("Content Element #42" with no hint which news record it belongs to),
and publishing the parent does not include them.

This hits MCP operations harder than regular backend editing: every write tool works in a workspace
(see `WorkspaceTransparency.md`), and an LLM frequently writes deep inside inline structures — a link of a news
record, a file reference of a content element — without ever touching the record a human would recognise.

### Decision

A DataHandler hook versions the outermost parent of every child record changed in a workspace (save, delete,
move, copy, localize). The core then versions all inline children along with that parent, so publishing, stage
changes and discarding always handle the whole family.

The hook is registered globally in `ext_localconf.php`, so it covers MCP writes and regular backend editing
alike. Installing this extension therefore changes how the Workspaces module looks for every editor, not just
for MCP clients. That is intentional: a workspace filled by both an LLM and a human should behave the same way
for both.

### Which relations count

Only owning relations between two workspace-aware tables, read generically from the TCA
(`InlineParentResolver::initializeRelations()`):

- `inline`/`file` with `foreign_field` — the child points to its parent (`tt_content` → news,
  `sys_file_reference` → `tt_content`)
- `inline` without `foreign_field` and without `MM` — the parent stores a comma-separated uid list

Not considered:

- `inline` with `MM`: a child can belong to several parents
- `select`/`group`/`category`: references to independent records; changing the reference changes the parent
  itself anyway
- `pid` and translations (`l10n_parent`): no ownership in the TCA sense

A column can opt out:

```php
$GLOBALS['TCA']['tx_foo']['columns']['bars']['config']['behaviour']['mcp_server']['propagateToParent'] = false;
```

### Implementation

| Class | Role |
| --- | --- |
| `Relation\ParentRelation` | One owning inline/file column of a parent table, with the two ways the relation can be stored (`field` / `list`) |
| `Relation\Ancestor` | A parent record above a child, plus its workspace version if it has one |
| `Relation\InlineParentResolver` | Reads the relations from the TCA and walks from a child up to its outermost parent (max. 10 levels, cycle-safe) |
| `Service\ParentVersioningService` | Versions the outermost ancestor without a workspace version, using a sub DataHandler (`version` / `new`), keeps the reference index up to date and restores the live values the core rewrites while versioning |
| `Hook\PropagateChangesToParentDataHandlerHook` | Collects the records of every DataHandler run and hands them to the service; suspends itself while `version` commands run |
| `EventListener\IgnoreInvisibleVersionDifferencesListener` | Removes version differences that are no change for an editor, so children versioned along with their parent stay "unchanged" |

### Protecting the live children

Versioning a parent makes the core rewrite the relation to its children: `DataHandler::remapListedDBRecords()`
hands the inline relation to `RelationHandler::writeForeignField()`, which renumbers the sorting of the **live**
children (256/512/768 become 1/2/3) and updates their tstamp. This is plain core behaviour — a bare
`version`/`new` command on a news record with live links does it, with no hook involved — but two things make it
unacceptable here:

- A workspace must never change live records (see `WorkspaceTransparency.md`).
- It breaks a sorting staged in the same workspace: reordering the links of a news record through WriteTable
  and then publishing the workspace would end up with the old order, because the staged move pointers and the
  renumbered live rows no longer live on the same scale.

`ParentVersioningService` therefore reads the fields of the live children that the core may rewrite — the
sorting field of the relation, the pointer back to the parent and tstamp — before versioning, walking down all
owning relations of the family, and writes those values back where versioning changed them. Live keeps its own
sorting scale, the versions keep theirs, and a reorder staged in the workspace survives publishing.

Why the suspension in the hook matters: publishing, stage changes, discarding and the core's own auto-versioning
all run as sub DataHandler instances with a `version` command, and those run the datamap/cmdmap hooks again.
Without the suspension, publishing a child would version its parent once more. The counter is static (shared by
all DataHandler instances of a request) and every outermost DataHandler run resets it, since a stale suspension
would otherwise survive a command that threw.

The reference index needs explicit care: a sub DataHandler is not the outermost instance and never flushes its
reference index updates itself, so `ParentVersioningService` passes it a `ReferenceIndexUpdater` of its own.
Without up-to-date `sys_refindex` rows the workspaces CommandMap does not find the children when the parent is
published.

### Invisible differences

Versions of untouched children would show up as "modified" instead of "unchanged"
(`GridDataService::versionIsModified()` uses the same differences), so the listener removes two kinds of them
via `ModifyVersionDifferencesEvent`:

- The timestamps (`ctrl.crdate` and `ctrl.tstamp`): every new version gets the current time for both, which
  shows for tables that have them as TCA columns (e.g. news, or `tt_content` through the columns
  georgringer/news adds). `crdate` never reaches live — `DataHandlerHook::version_swap()` keeps the live value
  on purpose — while `tstamp` is swapped along with the record. Both are filtered because neither says anything
  about the content. `tstamp` differs at all only because of the live protection above: without it the core
  overwrites the live `tstamp` of the children with the same new time, so live and version match; with it an
  untouched child versioned along with its parent differs in `tstamp` alone and the module would call it
  "modified".
- Differences without visible change: versioning normalizes some raw values (e.g. NULL becomes 0 in fields
  without `nullable`). Both values look the same, and the diff marks neither an insertion nor a deletion.
  Publishing still writes the normalized value to live; set `nullable` for fields of your own to keep NULL.
  Rich text fields (`enableRichtext`) are kept: the diff strips HTML tags, so a markup-only change looks the
  same there too, but is a real change.

### Side effects

- The parent shows up as changed without field changes ("unchanged" version), and its untouched children get
  unchanged versions too.
- Discarding only the child leaves the parent version behind; discard the parent to discard the whole family.
- After publishing, the untouched children carry the `tstamp` of their version, so "last modified" moves for
  records whose content did not change. Without the live protection the same thing happens at versioning time
  instead; the core's own behaviour does not keep `tstamp` either way.
- Errors of the versioning sub DataHandler (e.g. missing permission to edit the parent) are appended to the
  errorLog of the calling DataHandler. The change of the child itself is kept in that case.

### Origin

Ported from the `hn_workspace_relations` extension of the ad-alliance project, where the same hook runs against
TYPO3 13.4. The hook is unchanged; added here are the Schema API instead of the deprecated
`BackendUtility::isTableWorkspaceEnabled()`, an `inSet()` that also works on SQLite, and the protection of the
live children described above.
