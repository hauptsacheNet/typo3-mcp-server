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

```php
// Create context with language aspect
$context = GeneralUtility::makeInstance(Context::class);
if ($languageId > 0) {
    $languageAspect = new LanguageAspect(
        $languageId,
        $languageId,
        LanguageAspect::OVERLAYS_MIXED,
        [$languageId]
    );
    $context->setAspect('language', $languageAspect);
}

// Create PageRepository with context
$pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);

// Get page with automatic language overlay
$page = $pageRepository->getPage($uid);
if ($languageId > 0) {
    $page = $pageRepository->getPageOverlay($page, $languageId);
}
```

### Benefits

1. **Reduced code complexity**: No need to manually implement language overlay logic
2. **TYPO3 compatibility**: Uses standard TYPO3 APIs, ensuring compatibility with future versions
3. **Correct behavior**: Handles edge cases like language fallbacks, overlay modes, etc.
4. **Maintainability**: Less custom code to maintain

### Trade-offs

None identified. This approach maintains all requirements while reducing complexity.

### Related Files

- `Classes/MCP/Tool/GetPageTool.php` - Uses PageRepository for page data with language overlays
- `Classes/MCP/Tool/GetPageTreeTool.php` - Uses PageRepository for page tree with language overlays
- `Classes/MCP/Tool/Record/AbstractRecordTool.php` - Base class ensures workspace context initialization

### Future Considerations

If TYPO3 changes its language overlay implementation, we only need to update our Context/PageRepository usage rather than rewriting custom overlay logic.