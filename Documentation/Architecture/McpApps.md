# MCP Apps: Reviewing Changes in the Chat

## Decision: Show every write as a change of its outermost inline parent, with preview, publish and discard

### Context

Every `WriteTable` call ends up in a workspace. To see or approve the result, the editor
had to open the link the assistant offered, or the workspace module in the TYPO3 backend.
The workspace module also shows inline children poorly: a content element inside a news
record, or an image of a content element, appears as a record of its own instead of as
part of what the editor actually changed.

[MCP Apps](https://github.com/modelcontextprotocol/ext-apps) (extension
`io.modelcontextprotocol/ui`, stable revision 2026-01-26) lets a server attach an HTML view
to a tool. Hosts that support it (e.g. Claude, ChatGPT, VS Code) render the view in a
sandboxed iframe next to the tool call and pass it the tool result.

### Decision

- `WriteTable` declares the view `ui://mcp_server/change-review.html` in
  `_meta.ui.resourceUri`. Hosts without MCP Apps ignore it, the tool result is unchanged.
- The view (`Resources/Private/McpApps/ChangeReview.html`, plain JS, no external
  resources, so the host's default CSP applies) reads `table`/`uid` from the tool result
  and calls the tool `ReviewChange`. It shows the change as a word diff per field and
  offers:
  - **Preview**: opens the record's `preview_url` (or `frontend_url`) through the host
    (`ui/open-link`).
  - **Publish** and **Discard**: each needs a second click, then calls `ReviewChange`.
    Afterwards the view tells the model what happened (`ui/update-model-context`), so the
    assistant does not keep talking about a draft that is live or gone.
- `ReviewChange` is app-only (`_meta.ui.visibility: ["app"]`): hosts must not offer it to
  the model. Hosts without MCP Apps don't know that flag, so the server lists and executes
  tools implementing `AppOnlyToolInterface` only when the client declared the extension
  with the `text/html;profile=mcp-app` MIME type in its capabilities.

### The change of a record is the change of its outermost inline parent

`WorkspaceChangeService` treats inline children as part of their parent, as the backend
form does: a file reference belongs to its content element, a content element inside a
news record belongs to the news. The root is found by walking up
`InlineParentService::findParents()`, the same resolution that stages inline parents on
every write (see [Inline Relations](InlineRelations.md)).

The view therefore shows the news with the content element below it, and below that the
changed file reference. Only records with an actual change are listed: `InlineParentService`
stages unchanged copies of the parent and its other children, those are left out. Each
record has a state (`new`, `changed`, `moved`, `deleted`, `unchanged` for a parent that
is only versioned because a child changed) and its changed fields with TCA labels and
display values (`BackendUtility::getProcessedValue()`; rich text as plain text, FlexForms
as `setting: value` lines). Only fields the user may access are shown. Bookkeeping fields
(`tstamp`, `crdate`, sorting, …) and slugs that DataHandler generates for a live record with
an empty slug when versioning it are no change of the editor and left out.

Publish and discard act on the whole root: the root version and every version below it,
including the unchanged staged copies, go into one DataHandler command map
(`version` / `publish` resp. `clearWSID`). The workspaces extension resolves further
dependencies on its own (`CommandMap`), e.g. file references in FlexForm fields, which the
view does not list.

### Publishing from the chat

This is the one place where the extension makes something live, contrary to "live data is
never edited directly". It is acceptable because:

- the user triggers it with two clicks in the view, the model can't call the tool,
- it uses the workspace publish process of TYPO3 (DataHandler `version` command), with
  its permission checks: workspace publish access (`WorkspacePublishGate`), page
  permissions, and workspaces that only allow publishing from the publish stage. The view
  hides the button where the user may not publish,
- it publishes exactly the change shown, not the whole workspace.

### Limits

- A host that renders the view but doesn't declare the extension in its capabilities
  can't call `ReviewChange`; the view then says so and points to the workspace module.
- Relations without `foreign_field` (uid lists on the parent) can't be resolved from the
  child, such children are shown as their own change.
- Inline children in FlexForm fields are published and discarded along with their
  parent, but not listed in the view.
