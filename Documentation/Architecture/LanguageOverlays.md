# Language Overlay Architecture

## Decision: Use TYPO3 PageRepository for Language Overlays

### Context

The TYPO3 MCP Server needs to handle both workspace overlays and language overlays while maintaining workspace transparency for MCP clients. Initially, both types of overlays were implemented manually with custom database queries.

### Decision

We use TYPO3's built-in `PageRepository` API for language overlays while maintaining custom implementation for workspace overlays.

### Rationale

1. **Language overlays are different from workspace overlays**
   - Language overlays are content variations, not versioning
   - They should be transparent to MCP clients (clients request content in a specific language)
   - TYPO3's PageRepository handles language fallbacks and overlay modes correctly

2. **Workspace transparency requirement**
   - MCP clients should not be aware of workspace operations
   - The MCP server handles workspace switching internally
   - Live IDs are always exposed to clients, workspace IDs are hidden

3. **PageRepository can be used safely for languages**
   - When created with proper Context API, PageRepository respects current workspace
   - Language overlays don't conflict with workspace transparency
   - We get TYPO3's battle-tested language handling for free

### Implementation

`AbstractRecordTool::createLanguageOverlayRepository()` builds the PageRepository used by
`GetPage` and `GetPageTree`:

```php
// Cloned so the global context is not changed
$context = clone GeneralUtility::makeInstance(Context::class);
$context->setAspect('language', new LanguageAspect(
    $languageId,
    $languageId,
    LanguageAspect::OVERLAYS_MIXED,
    [$languageId]
));
// Hidden and scheduled translations are translations too (like in the backend)
$context->setAspect('visibility', new VisibilityAspect(true, true, false, true));
$pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);

// Pages: the overlay keeps the default-language uid in 'uid'
$page = $pageRepository->getPageOverlay($page, $languageId);
// Records: one call per (workspace-overlaid) default-language row
$row = $pageRepository->getLanguageOverlay($table, $row);
```

The uid of the translation a row was overlaid with is in `_LOCALIZED_UID` (TYPO3 13+;
`_PAGES_OVERLAY_UID` no longer exists). The overlay also copies the translation's
`t3ver_oid`, which maps a workspace version of the translation back to its live uid.

### Language view: which uid is shown

Clients edit what they see, so the language view names the translation record, not the
default-language record it was overlaid onto:

- **GetPage header**: `UID:` is the translation's live uid,
  `Translated: Yes (EN translation of page 111)` names the original. Without a translation:
  the default-language uid and a hint to use WriteTable `translate`.
- **GetPage record lists** (every language-aware table): a translated record is listed once,
  under the translation's uid, labeled `(EN, translates 504)`; untranslated records keep their
  uid, labeled `(DE original, not translated)`; records only existing in the language (free
  mode, no translation pointer) are labeled `(EN)`. Other translations are covered by their
  original or are orphans and are not listed.
- **GetPageTree**: the default-language uid stays primary, `[TRANSLATED: 205]` names the
  translation.
- **GetPage with a translation uid**: shows the default-language page in that translation's
  language, so uids taken from a language view work as input.

### Decision: hardcoded OVERLAYS_MIXED

The language view always uses `OVERLAYS_MIXED`, regardless of the site language's
`fallbackType`. It answers "what is translated, what is not" for editing, not "what does the
frontend render". On `strict` or `free` sites the view therefore also lists default-language
fallbacks the frontend would hide; they are labeled `not translated`, so this is visible.

### Benefits

1. **Reduced code complexity**: No need to manually implement language overlay logic
2. **TYPO3 compatibility**: Uses standard TYPO3 APIs, ensuring compatibility with future versions
3. **Correct behavior**: Handles edge cases like language fallbacks, overlay modes, etc.
4. **Maintainability**: Less custom code to maintain

### Trade-offs

- The language view does not mirror the frontend of `strict`/`free` sites (see the
  OVERLAYS_MIXED decision): fallback entries appear that the frontend would hide.
- PageRepository uses frontend restrictions. Hidden and scheduled records are included via
  the VisibilityAspect, but frontend group restrictions still apply; GetPage falls back to
  translations it loaded itself so restricted translations are not lost.
- Record lists are limited per table and cover all languages before the overlay, so on pages
  with many records the totals reflect the shown records only.

### Related Files

- `Classes/MCP/Tool/GetPageTool.php` - Uses PageRepository for page data with language overlays
- `Classes/MCP/Tool/GetPageTreeTool.php` - Uses PageRepository for page tree with language overlays
- `Classes/MCP/Tool/Record/AbstractRecordTool.php` - Base class ensures workspace context initialization, builds the language overlay PageRepository

### Future Considerations

If TYPO3 changes its language overlay implementation, we only need to update our Context/PageRepository usage rather than rewriting custom overlay logic.

A possible follow-up is deriving the aspect from the site configuration with
`LanguageAspectFactory::createFromSiteLanguage()`, so the view matches the frontend's
fallback behavior.