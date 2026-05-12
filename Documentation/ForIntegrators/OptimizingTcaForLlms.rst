..  include:: /Includes.rst.txt

..  _integrators_optimizing_tca:

================================
Optimizing TCA for LLMs
================================

The MCP server presents TCA almost verbatim to the LLM. Anything that helps
a human editor pick the right value also helps the LLM pick the right value
— there is no separate "LLM model" to maintain. This page lists the TCA
patterns that pay off most.

The TL;DR
=========

*   Write clear ``label`` and ``description`` strings for every field that
    isn't obvious from its name.
*   Set sensible ``default`` values. The LLM uses them as cues for
    expected types and formats.
*   Provide ``items`` with descriptive labels for ``select`` fields. The
    label, not the value, is what the LLM matches on.
*   Use ``hideTable`` deliberately — see
    :doc:`Architecture/InlineRelations` and
    :ref:`additionalStandaloneTables <integrators_extconf_additional_standalone_tables>`.

Field labels and descriptions
=============================

The LLM sees the TCA :php:`label` and :php:`description` for every field
listed by ``GetTableSchema``. Treat both as documentation for a slightly
literal-minded reader.

Good:

..  code-block:: php

    'release_date' => [
        'label' => 'Release date',
        'description' => 'When this article should be published. Future dates schedule the article for later publication.',
        'config' => ['type' => 'datetime'],
    ],

Less good:

..  code-block:: php

    'release_date' => [
        'label' => 'Date',
        'config' => ['type' => 'datetime'],
    ],

Two specific things the description should cover when relevant:

*   **Format expectations** ("ISO date", "without protocol", "comma-separated").
*   **Effect on other fields or behaviour** ("hides the article from
    listings when set", "must match an existing tag UID").

For boolean fields, a description that names what ``true`` means is much
clearer than ``"Active"``:

..  code-block:: php

    'mark_as_featured' => [
        'label' => 'Mark as featured',
        'description' => 'Featured items appear in the homepage hero. At most one featured item is shown at a time; the most recent wins.',
        'config' => ['type' => 'check'],
    ],

Select items
============

For ``select`` fields with a fixed set of options, the LLM matches on the
**label**, not the value. Two practical consequences:

*   Use natural-language labels, not abbreviations:
    ``'Press release'`` beats ``'pr'``.
*   When the label is purely visual (an icon name, a colour code),
    add a description so the LLM has something to read.

For dynamic select sources (``foreign_table``), the LLM dereferences the
foreign record and uses its label field — make sure the linked table has a
sensible label.

Default values
==============

Default values communicate "this is the normal case". An LLM creating a
new record will respect defaults unless told otherwise, which keeps
unrelated values from drifting.

..  code-block:: php

    'sorting_priority' => [
        'label' => 'Sorting priority',
        'description' => 'Higher values appear first in listings.',
        'config' => [
            'type' => 'number',
            'default' => 100,
        ],
    ],

Hiding fields the LLM should not touch
======================================

If a field is purely backend-internal (an import flag, a synchronisation
marker), leave it out of the LLM's view by removing it via
:ref:`AfterSchemaLoadEvent::removeField() <integrators_events_after_schema>`
in a listener.

For per-user hiding (e.g. an "admin only" field), use TCA
``displayCond`` — the MCP respects it. A field that's hidden in the
backend for the current user is also hidden from the LLM, automatically.

Read-only fields
================

Mark fields :php:`readOnly: true` in TCA when the LLM should be able to
see them but never change them — for example, a "last imported" timestamp
maintained by a scheduled task. ``ReadTable`` returns them, ``WriteTable``
silently ignores writes. The schema tool documents them as read-only.

If you want a field that is **computed** at read time (no underlying TCA
column), use the
:ref:`computed field pattern <integrators_customization_enriching>` with
the :php:`['mcp' => ['computed' => true]]` marker.

Per-page TSconfig
=================

The MCP respects :php:`TCEFORM` TSconfig at the actual page rootline. If
you hide a field, alter its label, restrict its select items, or change
its description for a specific page tree, the LLM sees the same view.
This means an editor's reality and an LLM's reality stay aligned without
extra work.

Use the ``pid`` parameter to ``GetTableSchema`` to resolve the schema for
a specific page — useful when verifying that a TSconfig change affects
the LLM's view as expected.

Trade-offs of common shortcuts
==============================

..  list-table::
    :header-rows: 1
    :widths: 50 50

    *   - Shortcut
        - What it costs
    *   - Empty ``description``
        - LLM falls back to the label and field name. Often enough for
          ``title`` or ``email``; rarely enough for domain-specific
          fields.
    *   - Cryptic ``label``
        - LLM has to guess from context. Errors and unexpected writes
          go up.
    *   - ``select`` without items
        - LLM has no idea what values are valid; it will guess and
          DataHandler will reject.
    *   - ``hideTable = true`` on a table that needs translations
        - LLM cannot translate via the parent. Promote to
          ``additionalStandaloneTables`` (see
          :doc:`ExtensionConfiguration`).
