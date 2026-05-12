..  include:: /Includes.rst.txt

..  _editors_what_to_expect:

================
What to expect
================

This page lists the kinds of tasks that work well with the MCP today, with
example prompts. They are grouped by the kind of work — pick one that
matches what you want to do.

..  contents::
    :local:
    :depth: 1

Translating pages
=================

Example prompt
    *"Translate the /about-us page to German."*

What happens
    The LLM fetches the page, reads its content elements, translates them and
    creates German versions. Translations are linked to the original via
    TYPO3's standard translation parent so they show up correctly in the
    backend list module.

Caveats
    Long pages can hit the LLM's context window — the longer the page, the
    more likely the model is to lose track of what it has and hasn't
    translated. For long pages, ask for one section at a time.

    Make sure the target language is configured in your site configuration.
    The LLM will tell you if it isn't.

Filling in SEO content
======================

Example prompts
    *"Find all pages without a meta description under /products and write
    one based on the page content."*

    *"Make sure every news article from this year has an OG image set."*

What happens
    The LLM searches for the candidate pages, reads their content for
    context, generates the missing metadata, and writes it back. You see
    every change as a draft in Workspaces.

Caveats
    Ask for a small batch first. If you like the output, run the same prompt
    on a wider scope.

Tone and style edits
====================

Example prompts
    *"Read the tone of /about-us and make the product pages under
    /products match it. Keep the meaning, change the voice."*

    *"Soften the tone on the contact page. Less formal, no jargon."*

What happens
    The LLM reads both the source and the target pages, drafts edits in the
    workspace, and lists what it changed. You review and decide.

Caveats
    Tone is subjective. Skim the diff in Workspaces — if the rewrites push
    too far in one direction, ask for "a smaller change" or "keep the
    section about pricing untouched".

Finding content to update
=========================

Example prompts
    *"Find every page that still mentions our old company name 'AcmeCo'."*

    *"List news articles where the date is set in the future."*

What happens
    The LLM uses the ``Search`` tool with your terms, optionally narrowed to
    a single table. It comes back with a list of matching records.

Caveats
    Search is SQL ``LIKE`` matching, not full-text — so misspellings or
    variations may not turn up. Try a few related search terms when the
    first attempt finds nothing.

Creating content from a brief
=============================

Example prompt
    *"Create a news article from this draft."* (with the text pasted into
    the chat or attached as a document)

What happens
    The LLM finds the right storage folder for news (or asks where it
    should put it), inspects the news table schema, and creates a record
    with the right fields filled in. Inline elements like links or images
    can be created alongside the parent record.

Caveats
    The first time you do this on a site, the LLM may need to be told where
    news lives. Once you have done it once, future prompts can rely on
    "create a news article" without further direction in the same chat.

Bulk maintenance
================

Example prompts
    *"Copy all news from 2023 into the /archive/2023 folder."*

    *"For every page under /products, set the navigation title to match the
    page title."*

What happens
    The LLM iterates through matching records and edits each one. Every
    edit lands as a workspace draft, so a large bulk task may produce a
    large workspace.

Caveats
    For very large jobs (hundreds of records), break the work down. Ask the
    LLM to do "the first batch" and report what it did; review the first
    batch, then continue.

Things it does not (yet) do
===========================

*   **File uploads.** Files in fileadmin can be referenced and described,
    but new files have to be uploaded via the backend.
*   **Publishing workspace changes.** By design — you do that step yourself
    after reviewing.
*   **Creating workspaces or assigning users to workspaces.**
*   **Editing TypoScript, YAML site configuration, or extension code.** Those
    are outside the scope of the MCP.
*   **Reading the rendered frontend.** It works from the structured TCA
    data, not from the rendered HTML.
