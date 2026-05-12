..  include:: /Includes.rst.txt

..  _integrators_architecture_datahandler:

========================
DataHandler integration
========================

The single most important thing to know about the MCP write path:
**DataHandler still runs**. Every write goes through
``DataHandler::process_datamap()`` (or :php:`process_cmdmap()` for moves and
deletes), exactly as if an editor had clicked Save in the backend.

What this means
===============

*   **All your existing DataHandler hooks fire.** Cache flushing, search
    index updates, custom validators, log writers — anything you have
    registered as ``processDatamapClass`` or similar runs unchanged when the
    LLM writes.
*   **TCA validation is enforced.** Required fields, regex rules, eval
    rules, max length — DataHandler applies them, and the MCP surfaces the
    error to the LLM in the response.
*   **Inline relations and references** are handled by DataHandler the same
    way the backend does it. Workspace versioning is wired up.
*   **History entries** are written. The Workspaces module shows the LLM's
    edits as ordinary draft versions you can publish, discard or roll back.

The MCP adds events :ref:`around <integrators_architecture_overview>`
DataHandler, but does not replace any of it. Conceptually:

..  code-block:: text

    BeforeRecordWriteEvent  ─►  validateRecordData()  ─►  DataHandler  ─►  AfterRecordWriteEvent
        (mutate / veto)                                  (TCA, hooks,
                                                          versioning,
                                                          history)

Which hook should I use?
========================

A common question for extension authors: I have logic that should run on
every write to my table. Where do I put it?

Use a **DataHandler hook** when:

*   You already have one.
*   The logic is generic — it should run regardless of who is writing
    (backend user, CLI, MCP, another extension that emits DataHandler
    calls).
*   You need access to DataHandler-specific information like the data
    map, the cmd map, or the full set of related operations.

Use a :ref:`BeforeRecordWriteEvent <integrators_events_before_write>` listener when:

*   The logic is specific to MCP writes — "when the LLM creates this
    record, also do X".
*   You want to **veto** the write with a friendly message the LLM can
    understand and recover from (DataHandler errors are noisier and
    less actionable for an LLM).
*   You want to mutate or normalize data before TCA validation runs.

Use an :ref:`AfterRecordWriteEvent <integrators_events_after_write>` listener when:

*   You want to react to successful MCP writes only — audit logging,
    webhook notifications, analytics for "what is the AI doing on my
    site".

In practice, most existing TYPO3 extensions need to do nothing. Their
DataHandler hooks already handle MCP writes correctly because the MCP
write path is just another DataHandler call.

Special cases the MCP handles itself
====================================

There is one place where MCP touches the database outside DataHandler: when
creating an embedded inline child whose foreign-field column is intentionally
absent from TCA. DataHandler can only set fields it sees in TCA, so the MCP
inserts the child, lets DataHandler do its thing, then writes the foreign
field directly via a single :php:`Connection::update()`. This is the only
known case; everything else is plain DataHandler. See
:doc:`InlineRelations` for the details if you maintain a hideTable child
with foreign keys.
