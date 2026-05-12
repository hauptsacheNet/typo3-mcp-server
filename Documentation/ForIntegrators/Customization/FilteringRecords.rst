..  include:: /Includes.rst.txt

..  _integrators_customization_filtering:

==================
Filtering records
==================

You restrict what the LLM can see by listening to
:ref:`BeforeRecordReadEvent <integrators_events_before_read>` and attaching
extra ``WHERE`` clauses to its query builder. The restriction applies
transitively to:

*   Top-level reads (``ReadTable``)
*   Inline relation children
*   Search results (``SearchTool``)
*   Search parent lookups

So one listener covers all paths to your table — no need to repeat yourself
per tool.

Recipe — restrict by tenant
===========================

A multi-tenant TYPO3 install where each editor only sees records belonging
to their tenant.

..  code-block:: php

    <?php

    declare(strict_types=1);

    namespace Vendor\YourExt\EventListener;

    use Hn\McpServer\Event\BeforeRecordReadEvent;

    final class TenantRestrictionListener
    {
        public function __invoke(BeforeRecordReadEvent $event): void
        {
            if ($event->getTable() !== 'tx_yourext_domain_model_item') {
                return;
            }

            $tenantId = $this->getCurrentTenantId();
            if ($tenantId === null) {
                return;
            }

            $qb = $event->getQueryBuilder();
            $qb->andWhere(
                $qb->expr()->eq(
                    'tenant',
                    $qb->createNamedParameter($tenantId, \PDO::PARAM_INT)
                )
            );
        }

        private function getCurrentTenantId(): ?int
        {
            // Pull from $GLOBALS['BE_USER']->user, a custom service, etc.
            return $GLOBALS['BE_USER']->user['tx_yourext_tenant'] ?? null;
        }
    }

Register it in :file:`Configuration/Services.yaml`:

..  code-block:: yaml

    services:
      Vendor\YourExt\EventListener\TenantRestrictionListener:
        tags:
          - name: event.listener
            event: Hn\McpServer\Event\BeforeRecordReadEvent
            identifier: 'your-ext/tenant-restriction'

Recipe — hide records by workflow state
=======================================

Only show records in the "approved" workflow state to the LLM. Drafts in
other states stay invisible.

..  code-block:: php

    public function __invoke(BeforeRecordReadEvent $event): void
    {
        if ($event->getTable() !== 'tx_yourext_domain_model_article') {
            return;
        }

        $qb = $event->getQueryBuilder();
        $qb->andWhere(
            $qb->expr()->eq(
                'workflow_state',
                $qb->createNamedParameter('approved')
            )
        );
    }

Recipe — fail safely when context is missing
============================================

If you cannot determine the user's tenant (e.g. CLI context, missing
relation), default to "see nothing" rather than "see everything". The
:php:`SysFileMountRestrictionListener` does the same with an
``1 = 0`` predicate.

..  code-block:: php

    $tenantId = $this->getCurrentTenantId();
    if ($tenantId === null) {
        $qb->andWhere($qb->expr()->and('1 = 0'));
        return;
    }

Reference listener
==================

The built-in
:file:`Classes/EventListener/SysFileMountRestrictionListener.php` is a
production-grade example of this pattern. It restricts ``sys_file`` reads
to the user's file mounts, returns ``null`` for admins, returns an
always-false expression for users without mounts, and exposes a reusable
``buildSysFileMountRestriction()`` helper so a sibling listener
(``SysFileMetadataRestrictionListener``) can apply the same logic to a
related table via a subquery.

Read it alongside this page — the same shape works for any "filter by
user context" requirement.

When **not** to filter here
===========================

*   **TYPO3 page permissions.** Don't reimplement them in a listener — the
    MCP already respects them.
*   **TCA exclude fields.** Already enforced.
*   **Workspace visibility.** Already handled at the query level via the
    workspace restrictions.

A listener should encode policy that TYPO3 does not already know about.
