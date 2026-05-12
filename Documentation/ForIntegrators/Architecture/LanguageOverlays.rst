..  include:: /Includes.rst.txt

..  _integrators_architecture_language_overlays:

================
Language overlays
================

The MCP server uses TYPO3's built-in ``PageRepository`` API for language
overlays, while keeping its own implementation for workspace overlays.

Why two different approaches
============================

Language overlays and workspace overlays look superficially similar — both
substitute alternative versions of a record at query time — but they need
different behaviour:

*   **Language overlays** are content variations. They should be transparent
    to MCP clients (a client asks for content in a specific language and
    gets it). TYPO3's ``PageRepository`` already handles language fallbacks
    and overlay modes correctly.
*   **Workspace overlays** must hide workspace IDs and version states from
    MCP clients entirely. Standard TYPO3 overlay handling (``workspaceOL()``)
    runs *after* the query, so deleted records are still fetched and only
    filtered out afterwards. That doesn't match the MCP's
    "live UIDs only, no workspace artifacts" contract, so the workspace
    side uses query-time restrictions instead.

Implementation
==============

When a tool needs to fetch records in a specific language, it builds a
``Context`` with a ``LanguageAspect`` and hands it to ``PageRepository``:

..  code-block:: php

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

    $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);

    $page = $pageRepository->getPage($uid);
    if ($languageId > 0) {
        $page = $pageRepository->getPageOverlay($page, $languageId);
    }

Because ``PageRepository`` is created from a ``Context`` that already carries
the workspace aspect, the workspace selection is preserved.

ISO codes vs language UIDs
==========================

The MCP tools accept ISO codes (``de``, ``fr``, ``en``) for the
``language`` parameter and the ``sys_language_uid`` field, and convert them
to numeric language UIDs via
:php:`LanguageService::getUidFromIsoCode()`. The schema advertised to the
LLM also lists the available ISO codes from the site configuration. This
removes a step of guessing for the LLM and lets prompts stay
language-natural.

Where this is used
==================

*   :file:`Classes/MCP/Tool/GetPageTool.php` and
    :file:`Classes/MCP/Tool/GetPageTreeTool.php` use ``PageRepository`` for
    page data with language overlays.
*   :file:`Classes/MCP/Tool/Record/AbstractRecordTool.php` initialises the
    workspace context so the language-aware reads in downstream tools see a
    consistent workspace.

If TYPO3 changes its language overlay implementation, the MCP server will
inherit the change automatically — no custom overlay logic to update.
