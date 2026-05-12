..  include:: /Includes.rst.txt

..  _testing_smoke_test:

=========================
Post-install smoke test
=========================

A six-step manual walk-through that takes about five minutes. Run it
after the initial install, after any update of this extension, and after
significant TCA changes to your site.

Prerequisites
=============

*   The extension is installed and the backend module is visible under
    your username dropdown.
*   You have a backend account with write access to at least one page
    tree branch.
*   You have an MCP client (Claude Desktop is the easiest). The
    in-terminal :bash:`vendor/bin/typo3 mcp:test` command works as a
    fallback if you don't.

Step 1 — confirm tool registration
==================================

..  code-block:: bash

    vendor/bin/typo3 mcp:test ListTables

Expected: a list of accessible tables grouped by extension. ``pages``
and ``tt_content`` must be there. If you have ``georgringer/news``
installed, ``tx_news_domain_model_news`` must be listed under "news".

If the command errors with "no such command", the extension is not
activated. Run :bash:`vendor/bin/typo3 extension:setup`.

If ``ListTables`` returns no tables, your backend user context isn't
being established correctly. Re-check the OAuth or stdio setup
(:doc:`../ForIntegrators/Authentication`).

Step 2 — read a known page
==========================

..  code-block:: bash

    vendor/bin/typo3 mcp:test GetPage --uid=1

Expected: a JSON blob with the root page's metadata and content
elements. The response should include a workspace hint banner if you're
in a workspace.

Step 3 — connect a real MCP client
==================================

Follow :doc:`../QuickStart/ConnectingClients` to wire up Claude Desktop
(or your client of choice) to TYPO3.

Ask the LLM:

    *"Show me the page tree from the root, three levels deep."*

Expected: the LLM calls ``GetPageTree`` and reads the result back to
you. If you see "tool not available" or a connection error, the
client-side configuration is the problem; the previous two steps confirm
the server works.

Step 4 — a reversible write
===========================

Pick a page where a small change is reversible. Ask the LLM:

    *"On page /<your-test-page>, change the heading of the first content
    element to 'MCP smoke test'."*

Expected: the LLM walks the page, finds the content element, and
updates it. The chat narrates the steps; the actual edit lives in your
workspace.

Step 5 — verify the workspace draft
===================================

Open :guilabel:`Workspaces` in the backend. You should see exactly one
draft: a modified ``tt_content`` row on your test page. Open it via
:guilabel:`Show` and confirm the heading change.

If you see no drafts, the write didn't land — check the raw tool
response in the client. If you see drafts but the change is wrong,
re-read the prompt; the LLM may have edited a different element.

Step 6 — discard
================

Discard the draft from the Workspaces module. The live page is
untouched. The smoke test ends with your site exactly as it was before.

What you've proved
==================

A successful run confirms:

*   The extension is installed and the tools are registered.
*   The backend user context propagates through the transport.
*   Workspace switching works.
*   ``GetPage``, ``GetPageTree``, ``ReadTable`` and ``WriteTable`` all
    function end-to-end against your TCA.
*   The DataHandler write path produces a valid workspace draft.

What it does **not** prove
==========================

It does not exercise:

*   Translations (run a "translate this page to <language>" prompt for
    that).
*   Third-party extension tables (see
    :doc:`ExtensionCompatibilityChecklist`).
*   Custom event listeners you've added (see
    :doc:`WritingFunctionalTests`).
*   Search behaviour on a large dataset.

For deeper acceptance, continue with the next chapter.
