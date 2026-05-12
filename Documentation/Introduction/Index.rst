..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

What this extension is
======================

The TYPO3 MCP Server publishes a `Model Context Protocol
<https://modelcontextprotocol.io>`__ server inside a TYPO3 13 or 14 backend.
MCP-aware clients — Claude Desktop, the Claude API, ChatGPT desktop, custom
agents — connect to it and gain a set of tools for navigating the page tree,
reading records, searching content, and writing back changes.

Every write goes through `TYPO3 Workspaces
<https://docs.typo3.org/c/typo3/cms-workspaces/main/en-us/Index.html>`__. The
LLM creates drafts in the user's workspace; an editor reviews and publishes
them in the Workspaces module before they go live. The MCP client never sees
workspace IDs or version states — it works exclusively with live UIDs and a
single, consistent view of the content.

The interface is intentionally close to TYPO3's own backend: page tree, list
module, record edit. Anything an editor can edit through TCA forms, the LLM can
also edit through MCP — including content from third-party extensions, as long
as their TCA declares workspace support.

..  _introduction_principles:

Core principles
===============

These shape every tool and every default in the extension.

Workspaces, always
    All modifications happen in a workspace. There is no path that writes to
    live data directly. The MCP server picks the user's first writable
    workspace, or creates a fresh "MCP Workspace" if the user can create one.

TCA-first
    Tables, fields, validation, relations and labels are read from TCA. Any
    extension whose TCA is well-described in human terms is automatically
    well-described to the LLM. Improving TCA labels for editors improves the
    LLM's accuracy at the same time. See
    :ref:`integrators_optimizing_tca`.

Live UIDs only
    Search, read, write — clients exchange live UIDs throughout. The MCP server
    translates between live UIDs and workspace versions internally.

User context, user permissions
    The MCP server runs as the authenticated backend user. Page permissions,
    table permissions, exclude fields, workspace ACLs, file mounts — all apply
    unchanged.

Safe by default
    Writes pass through TYPO3's :php:`DataHandler`. Validation, references,
    inline relations, history entries and workspace versioning behave exactly
    as they do in the backend. The MCP layer adds events around DataHandler
    but never replaces it. See :ref:`integrators_architecture_datahandler`.

..  _introduction_status:

Status
======

The extension is in **beta**. The core read/write/search loop is stable and
covered by functional and LLM tests, but TYPO3 installations vary widely and
LLM behaviour varies even more. Try it on a non-critical site first.

..  list-table::
    :header-rows: 1

    *   - Feature
        - Status
        - Notes
    *   - MCP connection
        - Ready
        - HTTP+OAuth and stdio transports.
    *   - Authentication
        - Ready
        - OAuth code/token flow for backend users; stdio uses
          :bash:`vendor/bin/typo3 mcp:server`.
    *   - Page tree navigation
        - Ready
        - Similar to the TYPO3 backend page tree.
    *   - Page content discovery
        - Ready
        - Backend-layout aware.
    *   - Record reading and writing
        - Ready
        - Any workspace-capable table, core or extension.
    *   - Content translation
        - Experimental
        - Implemented, needs real-world testing.
    *   - File uploads
        - Missing
        - Only existing files can be referenced.
    *   - Manual workspace selection
        - Missing
        - The first writable workspace is auto-selected.

----

Where to go next
================

If you have just installed the extension and want the LLM talking to your
site as quickly as possible, jump straight to :doc:`../QuickStart/Index`.

If you want to understand what the LLM can and cannot do before connecting it
to your editors, read :doc:`../ForEditors/Index`.

If you maintain the TYPO3 instance and need to think about events,
configuration, troubleshooting or tests, the main place to read is
:doc:`../ForIntegrators/Index`.
