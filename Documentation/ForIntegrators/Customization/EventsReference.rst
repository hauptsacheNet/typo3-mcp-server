..  include:: /Includes.rst.txt

..  _integrators_events:

================
Events reference
================

The MCP server dispatches five PSR-14 events. They are the entire
customization surface — there are no traditional TYPO3 hooks added by this
extension. All event classes live in
:php:`Hn\\McpServer\\Event\\`.

Registering a listener
======================

Use TYPO3's standard PSR-14 listener registration. In your extension's
:file:`Configuration/Services.yaml`:

..  code-block:: yaml

    services:
      Vendor\YourExt\EventListener\YourListener:
        tags:
          - name: event.listener
            event: Hn\McpServer\Event\BeforeRecordReadEvent
            identifier: 'your-ext/your-restriction'

The ``identifier`` is required and must be unique. Use a namespace-style
prefix so listeners are easy to spot in TYPO3's logs.

A listener can either declare a ``method`` (and live on a class that handles
multiple events) or omit it and use :php:`__invoke()`. Either form is fine.

..  _integrators_events_before_read:

BeforeRecordReadEvent
=====================

Dispatched before a tool executes a record-loading query — top-level reads,
inline-relation child lookups, and ``SearchTool`` per-table search queries.
Restrictions added here apply transitively to all of them, so any visibility
rule you encode covers reads, search results, and embedded relations in one
place.

The event is **not** dispatched on internal integrity queries from
``WriteTableTool`` (duplicate checks, child lookups during save). Those
must see all rows to prevent corrupt writes.

Source
    :file:`Classes/Event/BeforeRecordReadEvent.php`

Signature
    ..  code-block:: php

        final class BeforeRecordReadEvent
        {
            public const SOURCE_READ = 'read';
            public const SOURCE_READ_INLINE = 'read-inline';
            public const SOURCE_SEARCH = 'search';
            public const SOURCE_SEARCH_PARENT = 'search-parent';

            public function getTable(): string;
            public function getQueryBuilder(): QueryBuilder; // mutable
            public function getQueryType(): string; // 'select' | 'count'
            public function getSource(): string;
        }

What a listener can do
    *   Call ``andWhere()`` on the query builder to restrict visible rows.
    *   Branch on ``getSource()`` when behaviour should differ between
        top-level reads and inline children (rare — most restrictions
        ignore the source).
    *   Branch on ``getQueryType()`` when the count and the result query
        need different handling (also rare).

Recipes
    See :doc:`FilteringRecords`.

..  _integrators_events_after_read:

AfterRecordReadEvent
====================

Dispatched after ``ReadTableTool`` has loaded a batch of records. Listeners
can mutate the record array to enrich or redact fields. Records are passed
as a batch so listeners can perform single-query lookups instead of N+1
fetches.

Source
    :file:`Classes/Event/AfterRecordReadEvent.php`

Signature
    ..  code-block:: php

        final class AfterRecordReadEvent
        {
            public function getTable(): string;
            public function getRecords(): array;
            public function setRecords(array $records): void;
            public function getContext(): string;        // 'top' | 'inline'
            public function getRequestedFields(): array;
            public function isFieldRequested(string $field): bool;
            public function shouldEnrich(string ...$fields): bool;
        }

The ``shouldEnrich()`` helper
    Computed enrichment can be expensive (file URL resolution, joined
    sub-records, external lookups). ``shouldEnrich()`` returns ``true`` only
    when the work is actually needed:

    *   Inline children always need enrichment — embedded relations ignore
        the parent's ``fields`` filter.
    *   When the caller did not pass a ``fields`` whitelist, the default
        response includes every advertised field, computed ones included,
        so enrichment runs.
    *   When the caller did pass a whitelist, enrichment only runs if at
        least one of the listener's fields is in it.

    Always call ``shouldEnrich()`` at the top of your listener for
    expensive enrichment.

Recipes
    See :doc:`EnrichingRecords`.

..  _integrators_events_after_schema:

AfterSchemaLoadEvent
====================

Dispatched after ``TableAccessService::getAvailableFields()`` has loaded the
field set for a table (and a specific record type, where applicable). Every
read/write/schema tool routes through this method, so changes here propagate
consistently.

Source
    :file:`Classes/Event/AfterSchemaLoadEvent.php`

Signature
    ..  code-block:: php

        final class AfterSchemaLoadEvent
        {
            public function getTable(): string;
            public function getType(): string;
            public function getFields(): array;
            public function setFields(array $fields): void;
            public function addField(string $name, array $configuration): void;
            public function removeField(string $name): void;
            public function hasField(string $name): bool;
        }

Adding a computed read-only field
    Set the marker :php:`['mcp' => ['computed' => true]]` on the field
    configuration. The schema tool then renders the field in a dedicated
    "Computed (read-only)" section, ``ReadTable`` lets it through the type
    filter, and ``WriteTable`` rejects it. Combine with an
    ``AfterRecordReadEvent`` listener to actually compute the value.

Recipes
    See :doc:`EnrichingRecords` for computed-field examples.

..  _integrators_events_before_write:

BeforeRecordWriteEvent
======================

Dispatched after parameter validation and ISO-code conversion, but **before**
``validateRecordData()`` and DataHandler execution. This is where listeners
can change the data DataHandler is about to write, or veto the operation
outright.

Source
    :file:`Classes/Event/BeforeRecordWriteEvent.php`

Signature
    ..  code-block:: php

        final class BeforeRecordWriteEvent
        {
            public function getTable(): string;
            public function getAction(): string;
                // 'create' | 'update' | 'delete' | 'translate' | 'move'

            public function getData(): array;
            public function setData(array $data): void;

            public function getUid(): ?int;   // null on create
            public function getPid(): ?int;   // null on update/delete

            public function veto(string $reason): void;
            public function isVetoed(): bool;
            public function getVetoReason(): ?string;
        }

What a listener can do
    *   Call ``setData()`` to mutate the payload (auto-populate fields,
        normalize values, reroute by overwriting ``data['pid']``).
    *   Call ``veto($reason)`` to block the operation. The MCP returns an
        error to the LLM containing the reason; well-worded reasons let the
        LLM correct itself ("you need to set ``categories`` first") instead
        of giving up.

Recipes
    See :doc:`WriteRulesAndVeto`.

..  _integrators_events_after_write:

AfterRecordWriteEvent
=====================

Dispatched after ``WriteTableTool`` successfully completes a write. This
event is read-only — the operation has already happened. Not dispatched on
errors or vetoed operations.

Source
    :file:`Classes/Event/AfterRecordWriteEvent.php`

Signature
    ..  code-block:: php

        final class AfterRecordWriteEvent
        {
            public function getTable(): string;
            public function getAction(): string;   // 'create' | 'update' | 'delete'
            public function getUid(): int;         // live UID
            public function getData(): array;      // [] for delete
            public function getPid(): ?int;        // only for create
        }

Use cases
    *   Audit logs of AI-initiated writes.
    *   Webhook notifications to other systems.
    *   Analytics for "how is the LLM actually being used".

Note that for cache flushing and search-index updates you usually do
**not** need this event — your DataHandler hook already runs as part of
the write. See :doc:`../Architecture/DataHandlerIntegration`.
