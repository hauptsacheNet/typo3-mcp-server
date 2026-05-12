..  include:: /Includes.rst.txt

..  _testing_compatibility_checklist:

================================
Extension compatibility checklist
================================

Run this when integrating a third-party extension (or your own) with the
MCP. It's a per-extension audit that catches the common gaps: missing
workspace support, opaque TCA, ``hideTable`` children that need
promotion, restrictions you forgot.

Roughly 15–30 minutes per extension once you know the drill.

The checklist
=============

For each table the extension contributes:

1.  Does the LLM need to read it?
2.  Does the LLM need to write to it?
3.  If read+write: is it workspace-capable
    (:php:`['ctrl']['versioningWS'] = true`)?
4.  If read-only and not workspace-capable: is it listed in
    ``additionalReadOnlyTables``? See
    :doc:`../ForIntegrators/ExtensionConfiguration`.
5.  If :php:`hideTable = true`: is the standard "embedded child" behaviour
    correct, or does it need ``additionalStandaloneTables``? See
    :doc:`../ForIntegrators/Architecture/InlineRelations`.
6.  Are the TCA labels and descriptions human-readable?
7.  Do select fields have proper item labels?
8.  Are there fields that should be hidden from the LLM (internal flags,
    sync markers)? If yes, add an
    :ref:`AfterSchemaLoadEvent::removeField()
    <integrators_events_after_schema>` listener.

The empirical part
==================

After the static review, run three practical tests:

**Read**

..  code-block:: bash

    vendor/bin/typo3 mcp:test ReadTable --table=<extension-table> --limit=5

Expected: five sample records returned with the fields you expect.
Check:

*   Are the labels meaningful in the response?
*   Are inline children expanded the way you want (full objects vs. UIDs)?
*   Are computed fields showing up if you added any?

**Schema**

..  code-block:: bash

    vendor/bin/typo3 mcp:test GetTableSchema --table=<extension-table>

Expected: a schema description with every advertised field, its type
and its description. Check:

*   Every important field has a description that explains its purpose.
*   Select fields list their items with labels.
*   Read-only fields are clearly marked.

**Write**

Pick a low-risk action. Through your MCP client:

    *"Read the schema for <extension-table>, then create a test record on
    page <some-test-page>. Use realistic placeholder values."*

Expected: the LLM creates a draft. Open the draft in Workspaces, review,
discard.

Failure modes and fixes
=======================

..  list-table::
    :header-rows: 1
    :widths: 35 35 30

    *   - Symptom
        - Diagnosis
        - Fix
    *   - Table doesn't show up in ``ListTables``.
        - Not workspace-capable and not in ``additionalReadOnlyTables``,
          or the user lacks permissions.
        - Add to ``additionalReadOnlyTables`` if read-only is enough.
          Otherwise add :php:`['ctrl']['versioningWS'] = true` to TCA.
    *   - LLM consistently picks the wrong record from this table.
        - Labels are ambiguous; multiple records share the same title.
        - Improve the ``label_alt`` chain in TCA; use the ``description``
          to discriminate similar records.
    *   - LLM fills random values into a select field.
        - The select items have cryptic labels.
        - Improve item labels (humans first, LLM follows).
    *   - Embedded inline children get dropped on update.
        - The parent's update path is replacing the children array
          without echoing the existing ones.
        - Either include the existing children explicitly in the update
          payload, or promote the child table to
          ``additionalStandaloneTables`` so the LLM edits each child
          directly.
    *   - LLM fails to translate an embedded child.
        - The child has its own language column but lives inside the
          parent's inline relation.
        - Promote to ``additionalStandaloneTables``.
    *   - LLM hallucinates a field that doesn't exist.
        - Schema description is missing or unclear; LLM is guessing.
        - Add ``description`` to the related TCA fields. Run
          ``GetTableSchema`` and confirm the LLM sees what you expect.

When the extension passes
=========================

Document what you found. A short note in your project README — "news is
configured as: ``additionalReadOnlyTables = tx_news_domain_model_tag``" —
saves the next person reading the install a re-audit.

For your own extensions, fold these checks into your CI: see
:doc:`WritingFunctionalTests`.
