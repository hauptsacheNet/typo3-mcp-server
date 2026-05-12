..  include:: /Includes.rst.txt

..  _integrators_customization_builtin_listeners:

====================
Built-in listeners
====================

The extension ships with three event listeners that double as reference
implementations of common patterns. Read their source alongside the recipes
in the previous pages.

All three are registered in
:file:`Configuration/Services.yaml` (lines 54–76).

..  list-table::
    :header-rows: 1

    *   - Listener
        - Pattern
        - Events
    *   - :ref:`FileEnrichmentListener <integrators_builtin_file_enrichment>`
        - declare + populate computed fields
        - :php:`AfterSchemaLoadEvent`,
          :php:`AfterRecordReadEvent`
    *   - :ref:`SysFileMountRestrictionListener
          <integrators_builtin_sys_file_mount>`
        - filter records by user context
        - :php:`BeforeRecordReadEvent`
    *   - :ref:`SysFileMetadataRestrictionListener
          <integrators_builtin_sys_file_metadata>`
        - subquery filter using another listener's logic
        - :php:`BeforeRecordReadEvent`

..  _integrators_builtin_file_enrichment:

FileEnrichmentListener
======================

File:
    :file:`Classes/EventListener/FileEnrichmentListener.php`

What it does:

*   On ``sys_file``, adds a computed ``public_url`` field plus the
    "hidden behind the ``fileinfo`` control" TCA columns (``name``,
    ``identifier``, ``mime_type``, ``size`` and friends) so the LLM can
    filter and read by them.
*   On ``sys_file_reference``, adds five computed fields derived from the
    referenced ``sys_file``: ``file_name``, ``file_identifier``,
    ``file_mime_type``, ``file_size``, ``public_url``.
*   On ``sys_file_metadata``, adds the ``file``, ``categories``, ``width``
    and ``height`` columns that exist in TCA but are also hidden behind
    the ``fileinfo`` control.

Why it's the canonical example:

*   Demonstrates pairing :php:`AfterSchemaLoadEvent::addField()` with the
    :php:`['mcp' => ['computed' => true]]` marker so the field appears
    in the schema tool and is allowed through ``ReadTable``'s type filter.
*   Demonstrates the :php:`shouldEnrich()` short-circuit — file URL
    resolution goes through ``ResourceFactory::getFileObject()``, which
    is expensive at scale. Skipping it for bulk reads that don't ask for
    the field keeps things fast.
*   Demonstrates a single-query batch lookup (one ``IN`` query for all
    referenced files instead of N+1) before assigning enriched fields
    record by record.

When customising similar enrichment for your own tables, follow this
shape: declare the field in ``onSchemaLoad``, populate it in
``onRecordRead`` guarded by ``shouldEnrich()``, and batch your lookups.

..  _integrators_builtin_sys_file_mount:

SysFileMountRestrictionListener
===============================

File:
    :file:`Classes/EventListener/SysFileMountRestrictionListener.php`

What it does:

*   Restricts ``sys_file`` reads to files within the current backend
    user's accessible file mounts. Non-admin users with no file mounts
    see no files at all.

Why it's the canonical example:

*   Demonstrates an early return when the listener doesn't apply
    (``$event->getTable() !== 'sys_file'``).
*   Demonstrates three branches of restriction: ``null`` for admins (no
    restriction), an always-false predicate for users without mounts
    (fail-safe default), or a real restriction expression built from
    user data.
*   Exposes a static :php:`buildSysFileMountRestriction()` helper so
    related listeners can reuse the exact same logic — see the
    metadata listener below.

When you write a "restrict by user context" listener, follow this shape.

..  _integrators_builtin_sys_file_metadata:

SysFileMetadataRestrictionListener
==================================

File:
    :file:`Classes/EventListener/SysFileMetadataRestrictionListener.php`

What it does:

*   Restricts ``sys_file_metadata`` reads to metadata records pointing
    at files visible to the user (via the mount restriction above).
*   As a side effect, filters out orphaned metadata pointing at
    non-existent ``sys_file`` rows.

Why it's the canonical example:

*   Demonstrates building a subquery on a related table inside a
    listener — important when your visibility rule depends on another
    table's restrictions.
*   Demonstrates reusing another listener's logic
    (``SysFileMountRestrictionListener::buildSysFileMountRestriction``)
    so the mount rule lives in exactly one place.

When you have a "filter table B by what's visible in table A" requirement,
this is the shape.

Registration template
=====================

For reference, the relevant block from
:file:`Configuration/Services.yaml`:

..  code-block:: yaml

    services:
      Hn\McpServer\EventListener\FileEnrichmentListener:
        tags:
          - name: event.listener
            event: Hn\McpServer\Event\AfterSchemaLoadEvent
            method: onSchemaLoad
            identifier: 'mcp-server/file-enrichment-schema'
          - name: event.listener
            event: Hn\McpServer\Event\AfterRecordReadEvent
            method: onRecordRead
            identifier: 'mcp-server/file-enrichment-read'

      Hn\McpServer\EventListener\SysFileMountRestrictionListener:
        tags:
          - name: event.listener
            event: Hn\McpServer\Event\BeforeRecordReadEvent
            identifier: 'mcp-server/sys-file-mount-restriction'

      Hn\McpServer\EventListener\SysFileMetadataRestrictionListener:
        tags:
          - name: event.listener
            event: Hn\McpServer\Event\BeforeRecordReadEvent
            identifier: 'mcp-server/sys-file-metadata-restriction'
