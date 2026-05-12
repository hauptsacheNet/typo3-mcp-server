..  include:: /Includes.rst.txt

..  _integrators_extension_configuration:

=======================
Extension configuration
=======================

Two settings under :guilabel:`Settings → Extension Configuration → mcp_server`
control which tables the MCP exposes. Both default to a working file-handling
setup; you only need to change them when integrating extensions that need
extra non-workspace tables or ``hideTable`` children promoted to standalone
records.

The settings live in :file:`ext_conf_template.txt` and are read by
:php:`TableAccessService::getAdditionalReadOnlyTables()` and
:php:`TableAccessService::getAdditionalStandaloneTables()`.

..  _integrators_extconf_additional_read_only_tables:

``additionalReadOnlyTables``
============================

Default:
    ``sys_file``

Type:
    Comma-separated list of table names.

Purpose:
    By default, the MCP only exposes tables that declare workspace support
    (:php:`TCA[$table]['ctrl']['versioningWS'] = true`) — anything writable
    must be revertible via Workspaces. Non-workspace tables are normally
    invisible.

    This setting opts specific non-workspace tables in **as read-only**.
    They show up in ``ListTables`` and ``ReadTable`` but ``WriteTable``
    rejects them.

When to set it:
    *   You use an extension whose lookup tables (authors, categories,
        tags, settings) are not workspace-capable, and you want the LLM to
        be able to read them so it can pick the right values when writing
        related workspace-capable records.
    *   You want the LLM to discover the existence of records in a
        non-editable system table.

Example — exposing news author/tag tables for read::

    additionalReadOnlyTables = sys_file,tx_news_domain_model_tag

Example — exposing blog tag and author tables::

    additionalReadOnlyTables = sys_file,tx_blog_tag,tx_blog_author

..  _integrators_extconf_additional_standalone_tables:

``additionalStandaloneTables``
==============================

Default:
    ``sys_file_metadata``

Type:
    Comma-separated list of table names.

Purpose:
    Tables marked :php:`hideTable = true` in TCA are normally **embedded**
    into their parent's inline relation by ``ReadTable`` — the parent
    record's response includes the children as full records rather than
    UIDs. This is correct for simple inline structures
    (``sys_file_reference``, link records on news), but breaks down when
    the child has its own translation chain, its own permission rules, or
    other cross-cutting concerns the parent can't safely manage.

    This setting opts specific ``hideTable`` tables out of embedding. The
    table stays hidden in the TYPO3 backend list module — TCA is not
    modified — but the MCP treats it as an ordinary independent table:

    *   ``ListTables`` shows it.
    *   ``ReadTable`` and ``WriteTable`` allow direct CRUD.
    *   The parent's inline field collapses to a list of child UIDs.

When to set it:
    *   The ``hideTable`` child has its own language column and you need
        translation support.
    *   Editing through the parent is lossy (children get dropped) or
        unsafe (children get replaced when the LLM didn't intend to).
    *   The child has a different visibility rule than the parent — e.g.
        ``sys_file_metadata`` follows file mount restrictions on
        ``sys_file``, while the parent ``sys_file`` is read-only.

Example — promoting blog category translations to standalone records::

    additionalStandaloneTables = sys_file_metadata,tx_blog_category_translation

..  _integrators_extconf_walkthroughs:

Worked examples
===============

Integrating ``georgringer/news``
--------------------------------

``georgringer/news`` is already workspace-capable, so its main record table
``tx_news_domain_model_news`` is exposed out of the box. The associated
``tx_news_domain_model_tag`` table is **not** workspace-capable, so the LLM
can't discover existing tags by default. Expose it read-only so the LLM
can read tags and assign existing ones to news articles:

..  code-block:: text

    additionalReadOnlyTables = sys_file,tx_news_domain_model_tag

``tx_news_domain_model_link`` is :php:`hideTable = true` and is correctly
handled as an embedded inline child — leave it embedded.

Integrating a blog extension
----------------------------

A typical blog extension exposes a workspace-capable post table plus
several non-workspace lookup tables (authors, categories, tags). Expose
the lookups read-only:

..  code-block:: text

    additionalReadOnlyTables = sys_file,tx_blog_author,tx_blog_category,tx_blog_tag

If the post table has an inline ``post_meta`` child that is
:php:`hideTable = true` but has its own translations, promote it to
standalone:

..  code-block:: text

    additionalStandaloneTables = sys_file_metadata,tx_blog_post_meta

Diagnosing whether a table should be exposed
============================================

Two questions for each table:

1.  Does the LLM need to **read** it (to make decisions or compose
    related writes)? If yes and the table is not workspace-capable,
    consider ``additionalReadOnlyTables``.

2.  Does the LLM need to **write** to it directly, even though TCA marks
    it ``hideTable``? If yes, ``additionalStandaloneTables``. If the
    parent's inline relation handles it cleanly, leave it embedded.

When in doubt, leave the default and let the LLM tell you what's
missing — it will normally try a ``ReadTable`` on the table it wants,
get an "access denied" response, and either report the limitation or
propose a workaround.
