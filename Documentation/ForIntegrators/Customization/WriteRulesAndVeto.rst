..  include:: /Includes.rst.txt

..  _integrators_customization_write_rules:

==========================
Write rules, veto and audit
==========================

For write-side customization the MCP exposes two events:

*   :ref:`BeforeRecordWriteEvent <integrators_events_before_write>` —
    mutate data, veto the operation.
*   :ref:`AfterRecordWriteEvent <integrators_events_after_write>` — react
    to successful writes (read-only).

DataHandler runs in between (see
:doc:`../Architecture/DataHandlerIntegration`). Use the events for
MCP-specific logic; keep generic write-side logic in DataHandler hooks where
it belongs.

Recipe — auto-populate a field
==============================

Always set ``author_be`` to the current backend user on create.

..  code-block:: php

    <?php

    declare(strict_types=1);

    namespace Vendor\YourExt\EventListener;

    use Hn\McpServer\Event\BeforeRecordWriteEvent;

    final class AutoPopulateAuthorListener
    {
        public function __invoke(BeforeRecordWriteEvent $event): void
        {
            if ($event->getTable() !== 'tx_yourext_domain_model_article') {
                return;
            }
            if ($event->getAction() !== 'create') {
                return;
            }

            $data = $event->getData();
            if (empty($data['author_be'])) {
                $data['author_be'] = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
                $event->setData($data);
            }
        }
    }

..  code-block:: yaml

    services:
      Vendor\YourExt\EventListener\AutoPopulateAuthorListener:
        tags:
          - name: event.listener
            event: Hn\McpServer\Event\BeforeRecordWriteEvent
            identifier: 'your-ext/auto-populate-author'

Recipe — veto with an LLM-friendly reason
=========================================

Reject writes that would create a news article without categories — but
write the reason in a way the LLM can act on:

..  code-block:: php

    public function __invoke(BeforeRecordWriteEvent $event): void
    {
        if ($event->getTable() !== 'tx_news_domain_model_news') {
            return;
        }
        if ($event->getAction() !== 'create') {
            return;
        }

        $data = $event->getData();
        if (empty($data['categories'])) {
            $event->veto(
                'News articles need at least one category. Call ReadTable on ' .
                'tx_news_domain_model_category to find candidates, then retry ' .
                'with categories: [<uid>, ...] in data.'
            );
        }
    }

The reason string is surfaced to the LLM in the MCP error response. Treat
it as a brief to the model: actionable, specific, and pointing at how to
recover. "Permission denied" is a dead end; "you need to do X first, then
retry" lets the model self-correct.

Recipe — reroute to a different page
====================================

When the LLM tries to create a news article on the wrong page, silently
redirect it to the canonical storage folder:

..  code-block:: php

    public function __invoke(BeforeRecordWriteEvent $event): void
    {
        if ($event->getTable() !== 'tx_news_domain_model_news') {
            return;
        }
        if ($event->getAction() !== 'create') {
            return;
        }

        $storageFolderPid = 42; // Configure properly
        $data = $event->getData();
        if (($data['pid'] ?? 0) !== $storageFolderPid) {
            $data['pid'] = $storageFolderPid;
            $event->setData($data);
        }
    }

The MCP re-reads ``data['pid']`` after the event dispatch (see
:file:`Classes/MCP/Tool/Record/WriteTableTool.php` lines 261-274), so a
rerouting listener takes effect transparently.

Recipe — audit log on successful writes
=======================================

..  code-block:: php

    <?php

    declare(strict_types=1);

    namespace Vendor\YourExt\EventListener;

    use Hn\McpServer\Event\AfterRecordWriteEvent;
    use Psr\Log\LoggerInterface;

    final class AuditLogListener
    {
        public function __construct(private readonly LoggerInterface $logger) {}

        public function __invoke(AfterRecordWriteEvent $event): void
        {
            $this->logger->info('MCP wrote a record', [
                'table' => $event->getTable(),
                'action' => $event->getAction(),
                'uid' => $event->getUid(),
                'user' => $GLOBALS['BE_USER']->user['username'] ?? 'unknown',
            ]);
        }
    }

When **not** to use these events
================================

*   **Cache flushing on writes.** Use your DataHandler ``clearCachePostProc``
    or ``processDatamap_postProcFieldArray`` hook. It runs for the MCP write
    as well, with no extra wiring.
*   **TCA validation.** DataHandler enforces it. If you need a stricter
    rule than TCA can express, *and* the rule is MCP-specific (e.g. you
    want a friendlier message for the LLM), use the veto. Otherwise put
    a DataHandler hook.
*   **Side effects on every write regardless of source.** DataHandler hook.

The litmus test: would you want this logic to run when an editor edits the
same record via the backend? If yes, DataHandler hook. If no, MCP event.
