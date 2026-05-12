..  include:: /Includes.rst.txt

..  _reference_events:

=================
Event reference
=================

Quick signature table. Full descriptions and recipes are in
:doc:`../ForIntegrators/Customization/EventsReference`.

..  list-table::
    :header-rows: 1
    :widths: 32 18 50

    *   - Event
        - Phase
        - Key methods
    *   - :php:`Hn\\McpServer\\Event\\BeforeRecordReadEvent`
        - read
        - ``getTable()``, ``getQueryBuilder()`` (mutable),
          ``getSource()``, ``getQueryType()``
    *   - :php:`Hn\\McpServer\\Event\\AfterRecordReadEvent`
        - read
        - ``getTable()``, ``getRecords()`` / ``setRecords()``,
          ``getContext()``, ``isFieldRequested()``, ``shouldEnrich()``
    *   - :php:`Hn\\McpServer\\Event\\AfterSchemaLoadEvent`
        - read / schema
        - ``getTable()``, ``getType()``, ``getFields()`` / ``setFields()``,
          ``addField()``, ``removeField()``, ``hasField()``
    *   - :php:`Hn\\McpServer\\Event\\BeforeRecordWriteEvent`
        - write
        - ``getTable()``, ``getAction()``,
          ``getData()`` / ``setData()``, ``getUid()``, ``getPid()``,
          ``veto()``, ``isVetoed()``, ``getVetoReason()``
    *   - :php:`Hn\\McpServer\\Event\\AfterRecordWriteEvent`
        - write (read-only)
        - ``getTable()``, ``getAction()``, ``getUid()``, ``getData()``,
          ``getPid()``

Constants
=========

``BeforeRecordReadEvent::SOURCE_*``
    Identifies the originating call site of a read query:

    *   ``SOURCE_READ`` — top-level ``ReadTable`` query.
    *   ``SOURCE_READ_INLINE`` — fetch of inline-relation children.
    *   ``SOURCE_SEARCH`` — per-table search query.
    *   ``SOURCE_SEARCH_PARENT`` — search parent lookup.

Most restrictions ignore the source. Branch on it only when behaviour
truly differs per call site.

Registration
============

PSR-14 listener tag in :file:`Configuration/Services.yaml`. Every
registration needs a unique ``identifier`` (TYPO3 enforces this);
use a namespace-style prefix.

..  code-block:: yaml

    services:
      Vendor\YourExt\EventListener\YourListener:
        tags:
          - name: event.listener
            event: Hn\McpServer\Event\BeforeRecordReadEvent
            identifier: 'your-ext/some-restriction'

When the listener class handles multiple events, use ``method``:

..  code-block:: yaml

    Vendor\YourExt\EventListener\YourListener:
      tags:
        - name: event.listener
          event: Hn\McpServer\Event\AfterSchemaLoadEvent
          method: onSchemaLoad
          identifier: 'your-ext/foo-schema'
        - name: event.listener
          event: Hn\McpServer\Event\AfterRecordReadEvent
          method: onRecordRead
          identifier: 'your-ext/foo-read'
