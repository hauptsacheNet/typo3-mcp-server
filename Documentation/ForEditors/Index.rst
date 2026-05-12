..  include:: /Includes.rst.txt

..  _editors:

===========
For editors
===========

This track is for the people who write the prompts: editors, content
managers, and the colleagues who will work with the LLM day-to-day. It
explains what the MCP-equipped LLM can actually do on your TYPO3 site, how to
review what it did, and how to prompt it so it gets things right.

If you have not yet connected your MCP client to TYPO3, start with
:doc:`../QuickStart/Index`.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    WhatToExpect
    ReviewingChanges
    PromptingTips

The mental model
================

There are three ideas that make using the MCP much smoother once you have
them:

You are still in TYPO3
    The LLM sees what you would see in the page tree and list module. It does
    not see hidden tables or fields you would not see, and it cannot do
    things your TYPO3 account is not allowed to do. Asking it to "delete the
    homepage" will fail with a permission error if your account cannot delete
    pages.

Everything is a draft
    Every change the LLM makes lands in TYPO3 Workspaces as a draft version.
    Your live site is not affected until you publish in the Workspaces
    module. This is the safety net — you can always discard or roll back.

Be specific about scope
    "Update all product pages" is enormous. "Update the meta description on
    pages under /products" is achievable. The more concretely you describe
    what you want, the less likely the LLM is to wander.

What it can do well
===================

In practice, these are the requests editors most often get good results
from.

*   :ref:`Translating pages and content elements <editors_what_to_expect>`
    into other configured languages.
*   :ref:`Filling in missing SEO metadata
    <editors_what_to_expect>` (meta description, OG tags) based on the actual
    page content.
*   :ref:`Tone audits and rewrites <editors_what_to_expect>` — "make this
    less formal", "match the tone of /about-us".
*   :ref:`Finding content that needs updating <editors_what_to_expect>` —
    references to old product names, expired dates, broken phrasing.
*   :ref:`Creating long-form content from a brief <editors_what_to_expect>`
    — pasting in a Word document or talking points and getting a structured
    set of content elements back.

What it cannot do yet
=====================

Things the LLM will refuse, fail at, or do badly. Knowing these saves
frustration.

*   **Upload files.** It can reference files that already exist in the
    fileadmin (and read their metadata), but it cannot upload an image you
    paste into the chat. Upload via the backend first, then ask the LLM to
    reference the file.
*   **Publish workspace changes.** Drafts have to be published from the
    Workspaces module. The LLM cannot click that button for you. That's
    by design.
*   **Operate at huge scale in one prompt.** "Update every page on the
    site" works for tens of pages but not thousands. Break large jobs into
    chunks (per folder, per content type).
*   **Edit anything not in TCA.** TypoScript, site configuration YAML,
    extension code — outside the scope of MCP. Those still need a developer.

For the full list of capabilities and limits see :doc:`WhatToExpect`. For
help making prompts more reliable see :doc:`PromptingTips`. For the review
workflow see :doc:`ReviewingChanges`.
