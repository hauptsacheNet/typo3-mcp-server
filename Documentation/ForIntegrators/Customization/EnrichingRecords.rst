..  include:: /Includes.rst.txt

..  _integrators_customization_enriching:

===========================
Enriching and redacting
===========================

You shape what the LLM sees in record results with two events:

*   :ref:`AfterSchemaLoadEvent <integrators_events_after_schema>` — declare
    fields. The schema tool advertises them; the LLM can request them.
*   :ref:`AfterRecordReadEvent <integrators_events_after_read>` — populate
    those fields (or strip existing fields).

Always pair the two when adding a computed field. Declaring a field
without populating it produces empty values; populating without declaring
means the schema tool doesn't advertise it and ``ReadTable`` filters it
out.

Recipe — computed read-only field
=================================

Add an ``absolute_url`` field to ``pages`` that the LLM can request to
get a fully-qualified URL.

..  code-block:: php

    <?php

    declare(strict_types=1);

    namespace Vendor\YourExt\EventListener;

    use Hn\McpServer\Event\AfterRecordReadEvent;
    use Hn\McpServer\Event\AfterSchemaLoadEvent;

    final class PageAbsoluteUrlListener
    {
        public function onSchemaLoad(AfterSchemaLoadEvent $event): void
        {
            if ($event->getTable() !== 'pages') {
                return;
            }
            $event->addField('absolute_url', [
                'label' => 'Absolute URL',
                'description' => 'Fully qualified frontend URL of this page. Computed read-only.',
                'config' => [
                    'type' => 'input',
                    'readOnly' => true,
                ],
                'mcp' => ['computed' => true],
            ]);
        }

        public function onRecordRead(AfterRecordReadEvent $event): void
        {
            if ($event->getTable() !== 'pages') {
                return;
            }
            // Skip the expensive lookup unless the LLM asked for the field
            // (or context demands it — e.g. inline children).
            if (!$event->shouldEnrich('absolute_url')) {
                return;
            }

            $records = $event->getRecords();
            foreach ($records as &$record) {
                $record['absolute_url'] = $this->resolveAbsoluteUrl((int)$record['uid']);
            }
            unset($record);

            $event->setRecords($records);
        }

        private function resolveAbsoluteUrl(int $pageUid): ?string
        {
            // Use SiteFinder or similar to produce the absolute URL.
            // Return null on failure — the LLM handles missing values gracefully.
        }
    }

Register both methods:

..  code-block:: yaml

    services:
      Vendor\YourExt\EventListener\PageAbsoluteUrlListener:
        tags:
          - name: event.listener
            event: Hn\McpServer\Event\AfterSchemaLoadEvent
            method: onSchemaLoad
            identifier: 'your-ext/page-absolute-url-schema'
          - name: event.listener
            event: Hn\McpServer\Event\AfterRecordReadEvent
            method: onRecordRead
            identifier: 'your-ext/page-absolute-url-read'

The ``mcp.computed`` marker
    Marking a field :php:`['mcp' => ['computed' => true]]` does three
    things:

    *   ``GetTableSchema`` lists it under "Computed (read-only)".
    *   ``ReadTable`` lets it through the type filter even though it has no
        backing TCA column.
    *   ``WriteTable`` rejects it as a write target.

The ``shouldEnrich()`` check
    Computed enrichment is often expensive. ``shouldEnrich()`` returns
    ``true`` only when work is actually needed — when at least one of the
    listed fields is in the caller's ``fields`` filter, or the caller did
    not pass a filter (default response includes all fields), or the
    records are inline children (always enriched). Skipping a network
    call or a joined query when no one asked for the result keeps bulk
    reads fast.

Recipe — redact a field
=======================

Strip ``personal_notes`` from every read of a customer table before the
LLM sees it.

..  code-block:: php

    public function __invoke(AfterRecordReadEvent $event): void
    {
        if ($event->getTable() !== 'tx_yourext_domain_model_customer') {
            return;
        }

        $records = $event->getRecords();
        foreach ($records as &$record) {
            unset($record['personal_notes']);
        }
        unset($record);

        $event->setRecords($records);
    }

If you want to remove the field from the schema entirely (so the LLM
doesn't even know it exists), do that in ``AfterSchemaLoadEvent`` with
:php:`$event->removeField('personal_notes')`.

Recipe — mask a value
=====================

Replace email addresses with masked versions so the LLM can still see
"who has an email" without seeing the actual address.

..  code-block:: php

    foreach ($records as &$record) {
        if (!empty($record['email'])) {
            $record['email'] = preg_replace(
                '/^(.).+(@.+)$/',
                '$1***$2',
                $record['email']
            );
        }
    }

Reference listener
==================

The built-in
:file:`Classes/EventListener/FileEnrichmentListener.php` is a worked
production example. It does three things in concert:

1.  Declares ``public_url`` on ``sys_file`` and ``file_name``,
    ``file_identifier``, ``file_mime_type``, ``file_size``, ``public_url``
    on ``sys_file_reference`` via ``AfterSchemaLoadEvent``.
2.  Pulls in TCA columns that exist but are referenced by no ``showitem``
    (the backend renders them through a virtual ``fileinfo`` control),
    so the LLM can filter and read by them — ``name``, ``identifier``,
    ``mime_type``, ``size``, etc. for ``sys_file``.
3.  Populates the computed fields in ``AfterRecordReadEvent``, with a
    ``shouldEnrich()`` short-circuit so bulk reads don't pay the cost of
    ``ResourceFactory::getFileObject($uid)->getPublicUrl()`` per record
    when no one asked for it.

Read it alongside this page — it's the canonical shape of a
"declare + populate" pair.
