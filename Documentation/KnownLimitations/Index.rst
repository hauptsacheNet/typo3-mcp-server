..  include:: /Includes.rst.txt

..  _known_limitations:

==================
Known limitations
==================

The MCP server is in **beta**. The core read/write/search loop is stable
and covered by tests, but the surface around it has gaps. This page lists
the known ones so an integrator can plan around them.

File uploads
============

The MCP can **read** ``sys_file`` records (filename, identifier, MIME
type, size, computed ``public_url``) and **reference** existing files
through ``sys_file_reference`` inline children. It cannot **upload** new
files or modify the file binary in fileadmin.

Workaround: upload files via the TYPO3 backend or other means, then ask
the LLM to reference them by UID.

Workspace selection
===================

The MCP picks the user's first writable workspace and switches into it
automatically. If the user has access to multiple workspaces, there is
currently no way for a client to choose which one to operate in.

Workaround: assign editors to a single workspace, or use an admin
account that has access to a specific named workspace.

Workspace operations
====================

The MCP cannot:

*   Create workspaces other than the auto-generated "MCP Workspace".
*   Publish workspace changes — that step is intentionally manual.
*   Discard workspace changes in bulk from the MCP side.

These belong in the TYPO3 backend's Workspaces module.

Bulk operations at scale
========================

Large batch operations (hundreds or thousands of records updated in a
single prompt) are slower than the equivalent done by hand. The MCP
performs each write as an individual DataHandler call — there's no
batching layer.

Workaround: break large jobs into chunks (per folder, per content type,
per N records). Ask the LLM to "do the first 20 and report what you did"
before continuing.

Context window vs. site size
============================

The LLM's context window caps what it can hold in memory. Translating
a 30-page document inline often exceeds available context; the LLM may
silently drop content or lose track of what it's translated.

Workaround: process in sections. Ask the LLM for one chapter/page at a
time. Re-prompt with the URL when context runs short.

Search is SQL LIKE, not full-text
=================================

The ``Search`` tool uses pattern matching on TCA-declared searchable
fields, not a full-text index. Misspellings, stemming, and word breaks
do not behave the way a Solr or Elasticsearch user might expect.

Workaround: pass multiple terms with ``termLogic: OR``, or run the search
with a few morphological variants.

Non-TCA configuration
=====================

The MCP works exclusively with TCA-defined records. TypoScript, site
configuration YAML, page TSconfig, language packs, extension settings —
all outside scope. The LLM can read about them if they appear in pages
it visits, but it cannot edit them.

Workaround: human in the loop. The MCP is for content, not site
plumbing.

Frontend rendering
==================

The MCP works with structured TCA data, not the rendered HTML output.
"Read what the site visitor sees" is approximated through ``GetPage``,
but the actual rendered output (FluidStyledContent, custom plugin output,
Fluid templates) is not exposed.

For SEO and content audits this is usually fine; for layout or
rendering issues, an editor still needs to look at the frontend
themselves.

Things being considered for future versions
===========================================

These are areas of active interest but not yet implemented. They may or
may not land; treat as roadmap signals, not commitments.

*   Direct file upload through the MCP.
*   Manual workspace selection from the client.
*   Optimised bulk operations.
*   Batched search across federated indexes.

Bug reports, feature requests and real-world stories are valuable —
see the
`GitHub issues <https://github.com/hauptsacheNet/typo3-mcp-server/issues>`__
and the typo3-core-ai Slack channel.
