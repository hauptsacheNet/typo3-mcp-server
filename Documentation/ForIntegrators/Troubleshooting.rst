..  include:: /Includes.rst.txt

..  _integrators_troubleshooting:

================
Troubleshooting
================

Common problems and how to debug them.

..  contents::
    :local:
    :depth: 1

"Table not accessible"
======================

Symptom
    ``ListTables`` doesn't show the table you expect, or
    ``ReadTable``/``WriteTable`` returns "table not accessible".

Most likely causes:

*   **Table is not workspace-capable.** TCA needs
    :php:`['ctrl']['versioningWS'] = true` for write access. Without
    workspace support the MCP refuses to write to the table.
*   **You need to add it to** ``additionalReadOnlyTables`` **or**
    ``additionalStandaloneTables``. Common for third-party lookup tables;
    see :doc:`ExtensionConfiguration`.
*   **TCA exclude fields hide everything** for the current user group.
    Check :guilabel:`Group → Allowed excludefields` in BE user management.
*   **Page permissions deny access.** Check the storage folder permissions
    for the user.

Diagnostic command:

..  code-block:: bash

    vendor/bin/typo3 mcp:test ListTables --user=<be-username>

"Permission denied" on write
============================

Symptom
    ``WriteTable`` returns a permission error even though the table is
    accessible.

Most likely causes:

*   The user has read but not write access to the storage folder.
*   The user doesn't own the workspace and isn't a member.
*   A ``BeforeRecordWriteEvent`` listener is vetoing — check TYPO3 logs
    for events matching the listener's identifier.

If you have a custom listener vetoing, write more informative reasons:
the LLM uses the reason text to recover. See
:doc:`Customization/WriteRulesAndVeto`.

Workspace shows nothing after a write
=====================================

Symptom
    LLM says "I created the record"; Workspaces module shows no draft.

Possible causes:

*   **The write failed silently and the LLM is hallucinating success.**
    Check the actual MCP response in the client (Claude Desktop has a
    "Show raw tool output" toggle). DataHandler errors come back in the
    tool response.
*   **You're looking at a different workspace.** The MCP picks the user's
    first writable workspace; if multiple workspaces exist, that may not
    be the one you're viewing. Check :php:`$GLOBALS['BE_USER']->workspace`
    or open the workspace dropdown.
*   **The record was created in the live workspace** because the user
    cannot access any other workspace. Check the user's workspace
    assignments.

A computed field always returns ``null``
========================================

Symptom
    A field added via ``AfterSchemaLoadEvent`` shows ``null`` in
    ``ReadTable`` responses.

Most likely causes:

*   ``AfterRecordReadEvent`` listener is not registered for the table.
*   The :php:`shouldEnrich()` short-circuit is firing. By default
    enrichment only runs when the caller asks for the field or omits the
    ``fields`` parameter. If you want enrichment regardless, skip the
    ``shouldEnrich()`` check — but expect a performance hit on bulk reads.
*   The listener is throwing silently. Wrap expensive code in try/catch
    and log; the MCP swallows listener exceptions to avoid breaking
    reads, but a logged stack trace makes the problem obvious.

OAuth tokens disappear after a short time
=========================================

Symptom
    The MCP client repeatedly asks the user to reauthorise.

Causes:

*   The user is revoking via the backend module (intentional or
    accidental).
*   ``tx_mcpserver_oauth_codes`` is being cleaned by a cron job. The
    default token lifetime is 30 days; tokens older than that are
    rejected.
*   The TYPO3 instance is behind a load balancer with different timezones.
    Token expiry uses the TYPO3 server time; mismatched clocks cause
    tokens to look expired earlier than expected.

The LLM keeps confusing two records
===================================

Symptom
    LLM updates the wrong record, or edits content that "looks similar"
    to what you asked.

Causes:

*   Labels are too generic. If multiple pages have the title "Contact",
    the LLM has to guess. Use URLs or UIDs in your prompt to remove the
    ambiguity.
*   TCA labels are unclear. See :doc:`OptimizingTcaForLlms`.
*   Multiple tables hold "similar" data. Be specific about which table:
    *"Update the news article (tx_news_domain_model_news) with UID 42"*
    works better than *"update the news from yesterday"*.

The LLM hits the wrong storage folder
=====================================

Symptom
    Records are being created in a confusing location.

Causes:

*   The site has multiple storage folders and the LLM picks the first
    workspace-capable one it finds.
*   A page TSconfig restriction routes the record elsewhere.

Solutions:

*   Tell the LLM the storage folder URL or UID in the prompt.
*   Add a :ref:`BeforeRecordWriteEvent listener
    <integrators_customization_write_rules>` that reroutes by
    overwriting ``data['pid']``.

Reading the raw MCP response
============================

The biggest debugging speedup, especially when the LLM's narrative
diverges from reality, is reading the raw tool responses your client
exchanges with the MCP server.

In Claude Desktop: :guilabel:`Settings → Developer → Show tool output`.
The MCP error responses contain the actual DataHandler message, the
veto reason, or the access-denied detail.

When all else fails
===================

The ``mcp:test`` console command runs a tool with the same logic as the
HTTP server, but in your terminal where you can see logs and stack
traces clearly:

..  code-block:: bash

    vendor/bin/typo3 mcp:test ReadTable --table=pages --uid=1
    vendor/bin/typo3 mcp:test WriteTable --table=tt_content --action=update --uid=42 --data='{"header":"Test"}'

The output is the same JSON the LLM would see. If the tool works here but
not in your client, the problem is in the client, the transport, or the
prompt — not in TYPO3.
