..  include:: /Includes.rst.txt

..  _editors_prompting_tips:

================
Prompting tips
================

A few habits that make the LLM behave more reliably on a TYPO3 site.

Use real URLs
=============

The LLM is good at resolving full URLs and paths to pages:

*   ``https://example.com/about-us``
*   ``/about-us``
*   ``about-us``

All three work. Giving the URL removes a step of guessing — the LLM does
not have to "figure out which page you meant" before doing the work. For
long-running sessions, pasting a list of URLs at the start saves a lot of
back-and-forth.

Be specific about scope
=======================

The LLM treats vague scope literally. "Update all the pages" really does
mean *all* the pages — which is fine if that's what you want and the site
is small, but usually you want a tighter boundary.

Tighter:

*   "Pages under ``/products``"
*   "News articles from the last 30 days"
*   "Content elements of type ``textmedia`` on page 42"

Even tighter:

*   "These five URLs: ..."
*   "Records with these UIDs: 12, 34, 56"

Set the tone or constraint upfront
==================================

Especially for editorial work, briefing the LLM at the start of a chat
pays off:

    *"We are a law firm. Keep the tone professional, no exclamation
    marks. British English. When you don't know what to write, leave the
    field empty and tell me — don't invent facts."*

Once stated, that context carries through the whole session.

Ask for a preview before bulk work
==================================

For anything that touches many records, ask for one or two examples first:

    *"Pick two product pages and show me what you would do."*

Look at the output, refine if needed, then say *"do it for all of them."*

Work incrementally
==================

Complex jobs are more reliable as a sequence of small steps than as one
big request:

1.  "Find the candidate pages and list them."
2.  "For the pages in that list, read the headlines and current meta
    descriptions."
3.  "Now write a new meta description for each one. Don't write yet —
    show me first."
4.  "Looks good, apply them."

You can run multiple chats in parallel for different parts of a project.
Each chat has its own context and won't interfere with the others.

Look at the workspaces module periodically
==========================================

If a request feels like it should be done by now, switch to Workspaces and
see what's already there. Often the LLM is taking longer to *explain*
than it took to *do*, and the drafts are already waiting.

Things that confuse the LLM
===========================

*   **Implicit context after many turns.** After 20+ messages the LLM may
    forget what you set at the start. Restate the constraint when needed.
*   **Ambiguous record references.** "The contact page" is fine if there is
    one; if there are six pages with "contact" in the title, the LLM will
    have to ask or guess.
*   **Combining instructions with corrections in the same message.** If you
    say "actually, do X instead — and also Y", the LLM may apply both
    changes and confuse them. One thought per message works better.
