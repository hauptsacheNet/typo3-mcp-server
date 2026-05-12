..  include:: /Includes.rst.txt

..  _reference_tools:

=========
MCP tools
=========

The MCP server registers eight tools. Each is implemented as a class
under :file:`Classes/MCP/Tool/` (or :file:`Classes/MCP/Tool/Record/` for
the record-oriented tools) and registered automatically via the
:php:`mcp.tool` autoconfigure tag.

Per-tool input schemas are advertised to the MCP client at connection
time; this page summarises them for human readers. The authoritative
source is each tool's :php:`getSchema()` method.

..  contents::
    :local:
    :depth: 1

GetPageTree
===========

Source:
    :file:`Classes/MCP/Tool/GetPageTreeTool.php`

Purpose:
    Returns the TYPO3 page tree as a readable text tree, starting from a
    page and going N levels deep. Essential for orienting the LLM
    before further work.

Inputs:
    *   ``startPage`` — page UID to start from (``0`` = root).
    *   ``depth`` — levels to descend (default 3).
    *   ``language`` — ISO code, only present when the site has multiple
        languages. Displays translated page titles and translation
        status.

Output:
    Indented text tree. Each line shows the page title and, when
    relevant, plugin storage hints and record counts.

GetPage
=======

Source:
    :file:`Classes/MCP/Tool/GetPageTool.php`

Purpose:
    Detailed information about a single page, including its content
    elements. Accepts either a numeric UID or a URL/path.

Inputs:
    *   ``uid`` — page UID, or
    *   ``url`` — full URL, path, or slug. The LLM is told which domains
        the site responds to.
    *   ``language`` — ISO code (when multilingual).

Output:
    JSON with page metadata and its content elements (or the relevant
    records based on the backend layout).

ListTables
==========

Source:
    :file:`Classes/MCP/Tool/Record/ListTablesTool.php`

Purpose:
    Enumerates accessible tables grouped by extension. Indicates
    workspace capability and read-only status.

Inputs:
    None.

Output:
    Text listing of tables, organized by their source extension, with
    flags for workspace-capable and read-only.

GetTableSchema
==============

Source:
    :file:`Classes/MCP/Tool/Record/GetTableSchemaTool.php`

Purpose:
    Detailed schema for a specific table. The LLM uses it to learn what
    fields exist, what types they have, and what values are valid.

Inputs:
    *   ``table`` — the table name (constrained to accessible tables).
    *   ``type`` — optional record type to filter the schema (e.g.
        ``textmedia`` for ``tt_content``). When omitted, the first
        available type is shown plus the list of types.
    *   ``pid`` — optional page UID to resolve TSconfig against. Different
        pages can have different allowed types or hidden fields.

Output:
    Text schema. Sections: table metadata, regular fields, computed
    (read-only) fields, relations, validation rules.

GetFlexFormSchema
=================

Source:
    :file:`Classes/MCP/Tool/Record/GetFlexFormSchemaTool.php`

Purpose:
    Schema for a FlexForm field (typically plugin configuration on
    ``tt_content``). Required because FlexForm structures are nested
    XML, not flat columns.

Inputs:
    *   ``table`` — default ``tt_content``.
    *   ``field`` — default ``pi_flexform``.
    *   ``identifier`` — FlexForm identifier. On TYPO3 14 this is the
        CType (e.g. ``news_pi1``); on TYPO3 13 it can be a ``list_type``
        value or ``CType``.
    *   ``recordUid`` — accepted but unused.

Output:
    Text schema describing the FlexForm sheets, fields, and types.

ReadTable
=========

Source:
    :file:`Classes/MCP/Tool/Record/ReadTableTool.php`

Purpose:
    Read records from a table. Supports pid filter, UID(s) filter,
    SQL ``WHERE`` snippet, pagination, field selection, language filter,
    and translation-source inclusion.

Inputs:
    *   ``table`` — required.
    *   ``pid`` — filter by page.
    *   ``uid`` — single int or array of ints.
    *   ``where`` — raw ``WHERE`` condition (read-only operations
        whitelist; dangerous keywords like ``DROP``, ``DELETE``,
        ``UPDATE`` are rejected).
    *   ``limit`` (default 20, max 1000), ``offset``.
    *   ``fields`` — optional list of fields to include. ``uid`` is
        always included.
    *   ``language`` — ISO code (multilingual sites only).
    *   ``includeTranslationSource`` — boolean.

Output:
    JSON with ``records``, ``totalCount``, and (when requested)
    ``translationSource``. Inline relations are embedded as full
    sub-records or UIDs depending on the child table's ``hideTable`` and
    standalone configuration.

Search
======

Source:
    :file:`Classes/MCP/Tool/SearchTool.php`

Purpose:
    Full-table search across workspace-capable tables using TCA-declared
    searchable fields. SQL ``LIKE`` patterns; not full-text search.

Inputs:
    *   ``terms`` — array of search terms (required).
    *   ``termLogic`` — ``AND`` or ``OR`` (default ``OR``).
    *   ``table`` — optional restriction.
    *   ``pageId`` — optional page restriction.
    *   ``limit`` — per-table result cap (default 50).
    *   ``language`` — ISO code.

Output:
    JSON with results grouped by table. Workspace de-duplication is
    applied; only live UIDs are returned.

WriteTable
==========

Source:
    :file:`Classes/MCP/Tool/Record/WriteTableTool.php`

Purpose:
    The single write tool. Handles create, update, delete, translate,
    and move operations. Goes through :php:`BeforeRecordWriteEvent` →
    validation → DataHandler → :php:`AfterRecordWriteEvent`.

Inputs:
    *   ``action`` — ``create``, ``update``, ``translate``, ``delete``.
    *   ``table`` — required.
    *   ``uid`` — required for update, delete, translate.
    *   ``data`` — object with field values. On create, must include
        ``pid``. On update, can include a new ``pid`` to move. Inline
        relations supplied as arrays. Text fields accept a list of
        ``{search, replace, replaceAll?}`` operations as an alternative
        to full replacement.
    *   ``position`` — ``top``, ``bottom``, ``before:UID``, ``after:UID``.

Output:
    JSON describing the action's outcome — the affected record(s) with
    their live UID(s).
