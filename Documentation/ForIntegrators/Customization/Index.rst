..  include:: /Includes.rst.txt

..  _integrators_customization:

==============
Customization
==============

The MCP server has a small, deliberate customization surface: **two extension
settings** for static table visibility, and **five PSR-14 events** for
everything else. There are no traditional TYPO3 hooks added by this
extension — every customization goes through events.

DataHandler hooks, on the other hand, continue to fire as normal for every
MCP write. If your logic should run on every write regardless of where it
came from, that's where it belongs. See :doc:`../Architecture/DataHandlerIntegration`.

The decision table
==================

..  list-table::
    :header-rows: 1
    :widths: 50 50

    *   - You want to …
        - Use
    *   - Expose an additional read-only table that has no workspace support
          (e.g. ``tx_blog_domain_model_author``, lookup tables, settings
          tables).
        - ``additionalReadOnlyTables`` in
          :doc:`extension configuration <../ExtensionConfiguration>`
    *   - Expose a ``hideTable = true`` table as a standalone target rather
          than an embedded inline child (e.g. records the LLM should
          discover and edit without going through the parent).
        - ``additionalStandaloneTables`` in
          :doc:`extension configuration <../ExtensionConfiguration>`
    *   - Hide records from the LLM based on user, tenant, workflow state,
          or any other ``WHERE`` condition.
        - :ref:`BeforeRecordReadEvent <integrators_events_before_read>`
          (recipes in :doc:`FilteringRecords`)
    *   - Strip or redact fields after fetch — e.g. mask emails, hide a
          private column from the LLM.
        - :ref:`AfterRecordReadEvent <integrators_events_after_read>`
          (recipes in :doc:`EnrichingRecords`)
    *   - Add a computed read-only field the LLM can request (resolved URL,
          formatted address, joined sub-record).
        - :ref:`AfterSchemaLoadEvent <integrators_events_after_schema>` +
          :ref:`AfterRecordReadEvent <integrators_events_after_read>`
          (:doc:`EnrichingRecords`)
    *   - Mutate write payloads — auto-populate fields, normalize values,
          reroute to a different page.
        - :ref:`BeforeRecordWriteEvent <integrators_events_before_write>`
          (:doc:`WriteRulesAndVeto`)
    *   - Enforce a business rule on writes (block the operation with a
          message the LLM can recover from).
        - :ref:`BeforeRecordWriteEvent::veto() <integrators_events_before_write>`
          (:doc:`WriteRulesAndVeto`)
    *   - Audit, log, or send a webhook when MCP writes succeed.
        - :ref:`AfterRecordWriteEvent <integrators_events_after_write>`
          (:doc:`WriteRulesAndVeto`)
    *   - React to MCP writes the same way you already react to backend
          writes (e.g. flush caches, update an index).
        - **Your existing DataHandler hook** — it still fires.
          See :doc:`../Architecture/DataHandlerIntegration`.
    *   - Validate data against TCA, manage inline children, write a history
          entry.
        - Nothing — DataHandler does this automatically.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    EventsReference
    FilteringRecords
    EnrichingRecords
    WriteRulesAndVeto
    BuiltInListeners

The five events at a glance
===========================

All events live under :php:`Hn\\McpServer\\Event\\`. Register a listener
in your extension's :file:`Configuration/Services.yaml` with the standard
``event.listener`` tag.

..  list-table::
    :header-rows: 1
    :widths: 30 20 50

    *   - Event
        - Phase
        - Purpose
    *   - ``BeforeRecordReadEvent``
        - read
        - Attach extra ``WHERE`` clauses to restrict visible records.
    *   - ``AfterRecordReadEvent``
        - read
        - Enrich or redact fetched records before the LLM sees them.
    *   - ``AfterSchemaLoadEvent``
        - read / schema
        - Add, remove or reconfigure fields advertised to the LLM.
    *   - ``BeforeRecordWriteEvent``
        - write
        - Mutate data, veto writes with a reason.
    *   - ``AfterRecordWriteEvent``
        - write
        - React to successful writes (audit, webhooks).

Full signatures and code samples are in :doc:`EventsReference`. Recipes
grouped by use case are in the other pages of this chapter.
