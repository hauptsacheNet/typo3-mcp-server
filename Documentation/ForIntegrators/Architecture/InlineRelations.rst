..  include:: /Includes.rst.txt

..  _integrators_architecture_inline_relations:

================
Inline relations
================

TYPO3 inline relations (IRRE) connect records in parent-child relationships.
The MCP tools support both reading and writing them, with a small number of
nuances that are worth understanding when integrating extensions that rely
heavily on inline structures.

Types of inline relations
=========================

Independent inline relations
----------------------------

Tables that exist independently — ``tt_content`` is the canonical example.
TCA marks them with :php:`hideTable = false` (or no ``hideTable`` at all).

Read results expose them as UIDs:

..  code-block:: php

    // News with content elements
    $result = $readTool->execute([
        'table' => 'tx_news_domain_model_news',
        'uid' => 123,
    ]);
    // Returns: ['content_elements' => [456, 789, 1011]]

Embedded inline relations
-------------------------

Tables that only exist as children of another record — ``sys_file_reference``
or ``tx_news_domain_model_link`` for example. They are marked
:php:`hideTable = true` in TCA **and** are not listed in the
``additionalStandaloneTables`` extension setting.

Read results expose them as full embedded records:

..  code-block:: php

    // Page with file references
    $result = $readTool->execute([
        'table' => 'pages',
        'uid' => 123,
    ]);
    // Returns:
    // ['media' => [
    //     ['uid' => 1, 'title' => 'Image 1', 'uid_local' => 5, ...],
    //     ['uid' => 2, 'title' => 'Image 2', 'uid_local' => 6, ...],
    // ]]

Standalone exposure of ``hideTable`` children
---------------------------------------------

Some ``hideTable`` tables have inline structure that's too complex to be
safely edited through the parent — for example translations independent of
the parent's language, or rows whose visibility depends on permissions on a
different table. Embedding them makes the parent's update path either lossy
(drops translations the LLM didn't echo back) or unsafe (replaces rows the
LLM never intended to touch).

The extension setting ``additionalStandaloneTables`` opts a ``hideTable``
table out of embedding. The table stays hidden in the TYPO3 backend list
module (TCA is not modified), but MCP tools treat it as an ordinary
independent table:

*   ``ListTables`` shows it.
*   ``ReadTable`` and ``WriteTable`` allow direct CRUD.
*   The parent's inline field collapses to a list of child UIDs, just like
    for any independent relation.

``sys_file_metadata`` is on this list by default. It has :php:`hideTable = true`
plus its own ``languageField``, while its parent ``sys_file`` is read-only
and not language-aware — embedding made translations invisible and edits
dangerous. Exposing it standalone gives the LLM normal ``update`` and
``translate`` semantics on title, alternative and description, while the
read path on ``sys_file`` still yields ``metadata: [<uid>, ...]`` as a
discovery hint.

Mount restrictions on ``sys_file`` propagate transitively to
``sys_file_metadata`` via a subquery in
:php:`SysFileMetadataRestrictionListener`: non-admin users only see
metadata for files they could read on ``sys_file``. The same subquery
filters orphaned metadata pointing at non-existent files.

Writing inline relations
========================

Three methods, in increasing order of complexity.

Method 1 — foreign field update (independent relations)
-------------------------------------------------------

Update the foreign field on the child record:

..  code-block:: php

    $writeTool->execute([
        'table' => 'tt_content',
        'action' => 'create',
        'data' => [
            'pid' => 1,
            'header' => 'Related content',
            'CType' => 'text',
            'tx_news_related_news' => 789,
        ],
    ]);

Method 2 — parent record update with UIDs
-----------------------------------------

Pass an array of UIDs when updating the parent:

..  code-block:: php

    $writeTool->execute([
        'table' => 'tx_news_domain_model_news',
        'action' => 'update',
        'uid' => 123,
        'data' => [
            'content_elements' => [456, 789, 1011],
        ],
    ]);

Method 3 — embedded record creation
-----------------------------------

For dependent relations, pass record data when creating the parent:

..  code-block:: php

    $writeTool->execute([
        'table' => 'tx_news_domain_model_news',
        'action' => 'create',
        'data' => [
            'pid' => 1,
            'title' => 'News with links',
            'related_links' => [
                ['title' => 'Link 1', 'uri' => 'https://example.com'],
                ['title' => 'Link 2', 'uri' => 't3://page?uid=42'],
            ],
        ],
    ]);

How writes are executed
=======================

Embedded relations with direct database updates
-----------------------------------------------

Creating embedded children through the parent works in two steps:

1.  DataHandler creates the child records with placeholder IDs (NEW123).
2.  After DataHandler completes, the MCP server directly updates the
    foreign field on the new children via a single
    :php:`Connection::update()`.

This is necessary because the foreign-field column (e.g. ``parent`` on
``tx_news_domain_model_link``) is intentionally absent from the child
table's TCA — DataHandler only processes fields it sees in TCA, so it
ignores the foreign field. The post-write update fills it in.

This is the only place in the MCP write path that bypasses DataHandler.

File references
---------------

``sys_file_reference`` is fully supported as an embedded inline relation. It
supports workspaces natively (:php:`versioningWS = true`). TCA ``type=file``
fields are expanded by ``TcaPreparation`` into inline relations with
:php:`foreign_table = sys_file_reference`. The
:php:`foreign_match_fields` (``tablenames``, ``fieldname``) ensure the
reference is scoped to the correct parent field.

File references are enriched with metadata from ``sys_file`` (filename,
identifier, MIME type, public URL) via the
:ref:`FileEnrichmentListener <integrators_customization_builtin_listeners>`.

``sys_file`` itself is read-only — files are managed through the filesystem,
not direct database edits. File uploads are not supported; only references
to existing files via ``uid_local``.

Workspace handling
------------------

Both ``ReadTableTool`` and ``WriteTableTool`` initialise the workspace
context via ``WorkspaceContextService::switchToOptimalWorkspace()``. All
operations happen in the same workspace, transparently. No manual workspace
management is needed in tests or usage.

Position and sorting
====================

Sorting is handled through the ``sorting`` or ``sorting_foreign`` fields,
whichever the TCA declares. ``WriteTable`` supports ``top``, ``bottom``,
``after:UID``, ``before:UID`` positions for top-level records. Full
positioning support for individual inline children of an existing parent is
limited.

Best practices
==============

*   Use **Method 1** when you can — it's the simplest and most explicit.
*   For ``hideTable`` children whose TCA permits embedding cleanly
    (``sys_file_reference`` is the classic example), use **Method 3** and
    let the array fully express the children. Note that the array
    **replaces** the existing list: children present in the previous record
    but missing from the new array are deleted. To keep an existing child,
    include it as ``{"uid": <existing>, ...}``.
*   If your ``hideTable`` child has its own translation chain, file mounts,
    or other cross-cutting concerns, add the table to
    ``additionalStandaloneTables`` and use Method 1 instead.
