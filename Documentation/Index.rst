..  include:: /Includes.rst.txt

..  _start:

================
TYPO3 MCP Server
================

:Extension key:
    |ext-key|

:Package name:
    |composer-name|

:Version:
    |release-version|

:Language:
    en

:Author:
    Marco Pfeiffer and contributors

:License:
    This document is published under the
    `Creative Commons BY 4.0 <https://creativecommons.org/licenses/by/4.0/>`__
    license.

:Rendered:
    |today|

----

The :doc:`Model Context Protocol <Introduction/Index>` (MCP) server for TYPO3
lets AI assistants like Claude or ChatGPT read and edit content in a TYPO3
backend through a workspace-safe interface. Editors describe what they want in
plain language, the LLM calls the MCP tools, and every change lands as a draft
in TYPO3 Workspaces — nothing reaches the live site until a human publishes it.

----

**Pick your track**

Two audiences. Read one or the other; the contents pages link across when it
matters.

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`For editors <editors>`

        You write the prompts. Learn what the LLM can do, what it can't, and
        how to review its changes in the Workspaces module.

    ..  card:: :ref:`For integrators <integrators>`

        You install and configure the extension. Learn the event system, the
        ``ext_conf_template`` settings, and how to make your TCA easy for an
        LLM to work with.

----

**Table of contents**

..  toctree::
    :maxdepth: 2
    :titlesonly:

    Introduction/Index
    Installation/Index
    QuickStart/Index
    ForEditors/Index
    ForIntegrators/Index
    Testing/Index
    Reference/Index
    KnownLimitations/Index

..  Meta Menu

..  toctree::
    :hidden:

    Sitemap
    genindex
