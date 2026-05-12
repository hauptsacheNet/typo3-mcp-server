..  include:: /Includes.rst.txt

..  _integrators_architecture_overview:

========
Overview
========

The MCP server has four moving parts. From the outside in:

..  code-block:: text

    ┌──────────────┐    OAuth / HTTP    ┌───────────────────────────┐
    │  MCP client  │ ◄─────────────────►│         MCP server        │
    │ (Claude,     │    or stdio        │   (this TYPO3 extension)  │
    │  ChatGPT, …) │                    └─────────────┬─────────────┘
    └──────────────┘                                  │
                                                       ▼
                                          ┌───────────────────────────┐
                                          │       TYPO3 core          │
                                          │   DataHandler · TCA       │
                                          │   Workspaces · BE user    │
                                          └───────────────────────────┘

1.  The **MCP client** speaks the Model Context Protocol over either HTTP
    (with OAuth) or stdio.
2.  The **MCP server** runs inside the TYPO3 extension. It owns the tool
    registry (``ToolRegistry``), authentication (``OAuthService``), and the
    backend user context.
3.  The **tools** (``GetPage``, ``ReadTable``, ``WriteTable``, …) translate
    MCP tool calls into TCA-aware operations on the database, all routed
    through ``TableAccessService`` for the same permission and visibility
    decisions.
4.  Writes funnel through TYPO3's **DataHandler**, which enforces TCA, runs
    every registered DataHandler hook, manages workspace versioning, writes
    history entries, and updates inline relations.

..  _integrators_architecture_workspace_contract:

Workspace behaviour, in one paragraph
=====================================

Every MCP operation runs in the user's first writable workspace. If the user
has no workspace, a new one (titled "MCP Workspace") is created — provided
the user is allowed to create workspaces. The MCP client never sees workspace
IDs or version states: reads, searches and writes all use live UIDs.
Deletions made in the workspace are immediately invisible to subsequent reads
in the same workspace (no delete-placeholder leakage). Published content is
visible as normal; unpublished drafts override live content within the
workspace view.

This is the only piece of workspace behaviour an integrator needs to know
to use the extension. The implementation details (delete-placeholder
restriction, UID translation, search de-duplication) are internal — they live
in ``WorkspaceContextService``, ``WorkspaceDeletePlaceholderRestriction``,
``ReadTableTool`` and ``SearchTool``.

Request flow for a read
=======================

A ``ReadTable`` call goes through these stages:

1.  ``AbstractRecordTool::initialize()`` calls
    ``WorkspaceContextService::switchToOptimalWorkspace()``.
2.  ``TableAccessService::validateTableAccess()`` decides whether the table
    is accessible to this user (TCA, workspace capability, per-table read /
    write flags, exclude fields).
3.  The tool builds a query. Restrictions applied: ``DeletedRestriction``,
    ``WorkspaceRestriction``, ``WorkspaceDeletePlaceholderRestriction``.
4.  :ref:`BeforeRecordReadEvent <integrators_events_before_read>` is
    dispatched. Listeners attach extra ``WHERE`` constraints.
5.  Query executes.
6.  ``AfterSchemaLoadEvent`` has already shaped the field set;
    :ref:`AfterRecordReadEvent <integrators_events_after_read>` runs over
    the batch of rows. Listeners enrich or redact.
7.  Result returned to the client.

Request flow for a write
========================

A ``WriteTable`` call:

1.  Workspace switch (as for read).
2.  ``TableAccessService::validateTableAccess()`` with operation ``write``
    (or ``delete``).
3.  Parameter validation. ISO language code conversion. Inline-relation
    extraction.
4.  :ref:`BeforeRecordWriteEvent <integrators_events_before_write>` is
    dispatched. Listeners can mutate data or call ``veto()``.
5.  ``validateRecordData()`` runs the table-specific validation rules.
6.  **DataHandler** :php:`process_datamap()` (and :php:`process_cmdmap()` for
    moves/deletes) does the actual write — including all DataHandler hooks,
    inline handling, workspace versioning and history.
7.  :ref:`AfterRecordWriteEvent <integrators_events_after_write>` is
    dispatched on success.

The DataHandler step in 6 is what makes the MCP compatible with arbitrary
TYPO3 extensions: anything that hooks into DataHandler keeps working. See
:doc:`DataHandlerIntegration` for the consequences of that design.

Where the code lives
====================

..  list-table::
    :header-rows: 1

    *   - Concern
        - Files
    *   - Transport / OAuth
        - :file:`Classes/Service/OAuthService.php`,
          :file:`Classes/Command/McpServerCommand.php`
    *   - Tool registration
        - :file:`Classes/MCP/ToolRegistry.php`,
          :file:`Configuration/Services.yaml`
    *   - Tools
        - :file:`Classes/MCP/Tool/*.php`,
          :file:`Classes/MCP/Tool/Record/*.php`
    *   - Workspace handling
        - :file:`Classes/Service/WorkspaceContextService.php`
    *   - Table / field access
        - :file:`Classes/Service/TableAccessService.php`
    *   - Language overlays
        - :file:`Classes/Service/LanguageService.php`
    *   - Events
        - :file:`Classes/Event/*.php`
    *   - Built-in listeners
        - :file:`Classes/EventListener/*.php`
