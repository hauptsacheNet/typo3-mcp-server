..  include:: /Includes.rst.txt

..  _testing:

========
Testing
========

How to verify that the MCP behaves correctly on **your** TYPO3 install.
There are three levels of confidence you can establish, in increasing
order of effort.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    PostInstallSmokeTest
    ExtensionCompatibilityChecklist
    WritingFunctionalTests
    RunningTests

The three levels
================

1.  :doc:`PostInstallSmokeTest` (5 minutes) — a manual walk-through to
    confirm the install works at all. Do this once per environment after
    every upgrade.

2.  :doc:`ExtensionCompatibilityChecklist` (15-30 minutes) — a
    per-extension audit you run when integrating a new third-party
    extension with the MCP. Catches the common gaps (workspace support,
    TCA labels, ``hideTable`` translations).

3.  :doc:`WritingFunctionalTests` (ongoing) — automated tests for your
    own event listeners and customisations. The MCP exposes patterns for
    in-process tool invocation that mirror what an LLM actually sees.

The extension's own test suite (functional + LLM end-to-end) is documented
in :doc:`RunningTests`.

Why this matters
================

TYPO3 installations vary enormously: TCA conventions, custom extensions,
TSconfig, workspace ACLs, user permission schemes. The MCP works on top
of all of that without changes — but "works" needs to be verified per
environment. Two installations of the same extension can produce very
different LLM behaviour depending on:

*   Whether tables are workspace-capable.
*   Whether TCA descriptions are filled in.
*   Whether file mounts are restrictive.
*   Whether you have custom event listeners filtering reads.

The first two pages of this chapter give you the empirical checks; the
third gives you the test patterns to make those checks repeatable.
