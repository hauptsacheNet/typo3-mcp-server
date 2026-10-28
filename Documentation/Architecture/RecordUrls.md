# Record URLs and Workspace Preview Links

## Decision: Resolve record URLs via `TCEMAIN.preview`, add preview links only for changed records

### Context

An AI assistant regularly needs the address of what it reads or edits: "send me the link to
that news article", "show me how the page looks now". Pages had a URL, everything else did
not, so the assistant could not link a news record, an event or any other record that a
plugin renders on a detail page.

Edits make it harder: they live in a workspace and are invisible on the website until a
human publishes them. A URL of the live website shows the old state, or nothing at all for
a record that only exists as a draft.

### Decision

`RecordUrlService` resolves two URLs per record:

- **`frontend_url`** — the record on the live website. Missing for records that only exist
  as a draft.
- **`preview_url`** — only present when the draft differs from live. A login-free
  workspace preview link (`ADMCMD_prev`), the same kind the workspace module creates.

They appear in:

| Tool          | Where                                                                                  |
|---------------|----------------------------------------------------------------------------------------|
| `ReadTable`   | Computed fields `frontend_url` / `preview_url` (also listed by `GetTableSchema`)       |
| `WriteTable`  | In the result of `create`, `update` and `translate`, so an edit can be shown right away |
| `GetPage`     | `URL:` and `Preview URL:` lines                                                        |
| `GetPageTree` | After each page, the preview only for pages with unpublished changes                   |

#### Which records have a URL

The rules of the backend "View" button:

- **Pages** link to themselves, **content elements** to their page with a `#c<uid>` anchor.
- **Any other table** needs `TCEMAIN.preview.<table>` in Page TSconfig, the configuration
  the backend and the workspace module already use for the same purpose:

  ```
  TCEMAIN.preview.tx_news_domain_model_news {
      previewPageId = 123
      fieldToParameterMap.uid = tx_news_pi1[news]
      additionalGetParameters.tx_news_pi1.controller = News
      additionalGetParameters.tx_news_pi1.action = detail
  }
  ```

  The site router then turns this into whatever the route enhancers make of it, e.g.
  `https://example.com/news/detail/my-article`.

Deliberate deviation from the backend: the backend falls back to the site root when the
record's page cannot be shown (a sysfolder) and no `previewPageId` is configured. We return
no URL instead, because a link to the home page labelled as "the URL of this record" would
mislead the assistant.

#### When a record counts as changed

After the workspace overlay a record carries `t3ver_wsid > 0` when it has a version in the
current workspace (new, changed, moved). No extra query is needed. Pages additionally count
as changed when one of their translations or content elements has a version (one query per
batch for the page versions, one for `tt_content`). Changes to inline children are covered
because `WriteTable` stages the inline parent as well (see [Inline Relations](InlineRelations.md)).

### Why not the link handler (`config.recordLinks`)?

Integrators also register record link handlers (`TCEMAIN.linkHandler` plus TypoScript
`config.recordLinks`) to make records linkable in the RTE. Resolving those needs the
frontend TypoScript of the target site and a `ContentObjectRenderer` with a frontend
request — bootstrapping a frontend inside every MCP call, which is expensive and fragile
across TYPO3 versions. `TCEMAIN.preview` is Page TSconfig, available in the backend for the
cost of a cached lookup, and installations that care about records in the backend usually
have it configured anyway, because it drives the "View" button.

### Why not TYPO3's `PreviewUriBuilder`?

Both the backend `PreviewUriBuilder::createForRecordPreview()` and the workspace
`PreviewUriBuilder` are marked `@internal`. Per record they also load the preview page and
check page access with uncached queries, and the workspace builder (13.4) writes a new
`sys_preview` row on every call. `RecordUrlService` follows the same `TCEMAIN.preview`
semantics with public APIs (`BackendUtility::getPagesTSconfig()`, the site router,
`PageRepository`) and batch-level memoization.

### Decoupled frontends (EXT:headless)

The router builds URLs on the site `base`. In a headless site that base serves JSON;
visitors use the `frontendBase` configured in the site (site-wide, per language, per base
variant). EXT:headless rewrites the backend's view-button URLs to it, but on paths
`RecordUrlService` does not take: in 5.x (TYPO3 14) through `AfterPageUriGeneratedEvent`,
only during a backend request; in 4.x (TYPO3 13) through `AfterPagePreviewUriGeneratedEvent`,
only in the live workspace, plus an XClass of the workspace `PreviewUriBuilder`. MCP calls
are neither backend requests nor in the live workspace, so the assistant got JSON URLs.

`RecordUrlService` therefore dispatches its own `AfterRecordUrlGeneratedEvent` for every
URL it generates (live and preview, before the token is appended), and
`HeadlessFrontendUrlListener` applies the calls both EXT:headless versions make for the
view button: `HeadlessModeInterface::overrideBackendRequestBySite()` and
`HeadlessFrontendUrlInterface::getFrontendUrlWithSite()`. The site's headless mode decides
like it does for the view button: `headless: 1` rewrites, `headless: 2` (mixed) only with
the site setting `headless.preview.overrideMode: 1`. EXT:headless stays an optional
dependency. Other decoupled setups can listen to the same event.

`GetPage` accepts URLs on the `frontendBase` hosts as well, and lists them among the
available domains, so a link the assistant handed out can be passed back to it.

### Preview tokens

A preview token grants read access to **all** unpublished changes of the workspace, without
login, until it expires. To keep that manageable:

- A token is only resolved when a changed record is part of the output. Reading unchanged
  records never creates one.
- Tokens are reused: any stored token of the workspace with at least half of the configured
  lifetime left is handed out again, so reads do not add a `sys_preview` row each.
  The lifetime follows the workspace module: `sys_workspace.previewlink_lifetime`, then
  user TSconfig `options.workspaces.previewLinkTTLHours`, then 48 hours.
- The extension setting `workspacePreviewLinks` disables preview links entirely.
- Only the opened URL carries the token. TYPO3 keeps the preview in an `ADMCMD_prev` cookie,
  so navigating further on the site needs cookies; a client without cookies sees the live
  site after the first page.

### Slugs in preview links

TYPO3's `PersistedAliasMapper` generates the slug of the **live** record, it applies no
workspace overlay. The frontend preview resolves that slug to the record and renders its
draft, so a preview link of a news record with a changed slug keeps the live slug — exactly
like the links TYPO3 renders inside the preview. Page slugs, resolved by the page router in
the workspace context, do show the draft slug.

### Cost

Measured with 20 records per `ReadTable` call (functional test, SQLite):

| Records                       | Extra queries |
|-------------------------------|---------------|
| 20 content elements           | 1             |
| 20 news, unchanged            | 21 (one slug lookup by the route enhancer per record) |
| 20 news, all changed in draft | 42 (slug lookup for live and preview URL, plus token)  |

TSconfig, rootline, site and page lookups hit TYPO3's runtime caches or a per-batch memo.
`ReadTable` callers that pass a `fields` list without the URL fields skip the resolution
completely.
