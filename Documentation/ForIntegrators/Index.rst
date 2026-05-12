..  include:: /Includes.rst.txt

..  _integrators:

================
For integrators
================

This track is for the people who install and tune the extension on a TYPO3
site: site developers, integrators, and extension authors who want their
records to play well with MCP. It assumes you are comfortable with TCA,
PSR-14 events, and the TYPO3 backend.

The first thing to read after installation depends on what you need to do
next:

..  list-table::
    :header-rows: 1
    :widths: 35 65

    *   - Goal
        - Start here
    *   - "Get this extension and another extension (news, blog, …) to play
          nicely together."
        - :doc:`ExtensionConfiguration`
    *   - "Restrict what the LLM sees per user or per tenant."
        - :doc:`Customization/FilteringRecords`
    *   - "Add a computed field that the LLM can read."
        - :doc:`Customization/EnrichingRecords`
    *   - "Enforce a business rule on every write."
        - :doc:`Customization/WriteRulesAndVeto`
    *   - "Make the LLM more accurate on my custom tables."
        - :doc:`OptimizingTcaForLlms`
    *   - "Understand how the request flow works under the hood."
        - :doc:`Architecture/Overview`
    *   - "Debug a permission or workspace error."
        - :doc:`Troubleshooting`

..  toctree::
    :maxdepth: 1
    :titlesonly:

    Architecture/Index
    Customization/Index
    ExtensionConfiguration
    OptimizingTcaForLlms
    Authentication
    Troubleshooting

The integration surface, in one paragraph
=========================================

You touch the MCP from four places. **Extension settings**
(``additionalReadOnlyTables`` / ``additionalStandaloneTables``) control which
tables exist for the LLM. **PSR-14 events** dispatched around every read and
write let you filter, enrich, mutate or veto records — the entire
customization surface, by design (there are no custom hooks). **TCA itself**
controls how fields are presented to the LLM, with a few MCP-specific markers
for computed fields. And **DataHandler** runs untouched in between the two
write events, so existing DataHandler hooks (cache flushing, side effects,
etc.) keep working without modification.

That's the whole extension API. Most installations need none of it; a few
need extension settings; the more interesting ones add an event listener or
two. See :doc:`Customization/Index` for the decision table on when to use
which.
