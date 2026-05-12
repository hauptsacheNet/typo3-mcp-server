..  include:: /Includes.rst.txt

..  _editors_reviewing_changes:

==================
Reviewing changes
==================

Every change the LLM makes is a workspace draft. Nothing is live until you
review and publish it. This page covers the small set of skills around
that.

Where the changes are
=====================

Open :guilabel:`Workspaces` in the TYPO3 main menu (left sidebar, under
"Web" or wherever your installation places it). You will see the workspace
the MCP put the changes into — usually named *MCP Workspace* unless your
admin set it up differently — with each new, modified or deleted record
listed.

Each row tells you:

*   Which table and which record changed
*   What kind of change (new, modified, deleted)
*   Who made it (the backend user the MCP authenticated as — that's you)
*   When

Use the row actions to:

*   **Show** — see the original and modified version side by side
*   **Publish** — send the change live (single record or selection)
*   **Discard** — throw the draft away; the live record is untouched

A practical workflow
====================

After the LLM tells you it's done, do this:

1.  Open Workspaces.
2.  Glance at the list. Is the count of changes roughly what you expected?
    If the LLM said "I updated three pages" and Workspaces shows 47
    changes, something went wider than intended. Discard everything and
    re-prompt with a tighter scope.
3.  Open the first change with :guilabel:`Show`. Read the diff. Does the
    edit do what you asked?
4.  If yes, spot-check a couple more. With dozens of similar edits, a
    sample is usually enough.
5.  Publish what you trust. Discard the rest.

Discarding everything
=====================

If you want to throw away **all** the LLM's drafts at once, select all rows
in Workspaces and use the bulk discard action. The live site is untouched.

You can also discard a single change while keeping the rest. The Workspaces
module behaves the same way for MCP-generated drafts as it does for drafts
created by humans editing in the backend.

If something went live by mistake
=================================

Publishing is reversible only by editing the live record back to its prior
state. TYPO3 keeps history entries for every edit (under the **Record
History** action on a record), which makes "undo by hand" easier than
starting from scratch. The LLM can also help: ask it to revert a specific
record to a previous value if you remember what it was.

Telling the LLM what is published vs. draft
===========================================

The MCP server keeps a single, consistent view of the content: it always
reads from the workspace if one exists, so subsequent prompts in the same
session see the LLM's own earlier edits. You do not need to "save and
reload" to get a coherent state. When you publish or discard manually, the
next read after that reflects the published version.
