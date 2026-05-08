<?php

declare(strict_types=1);

namespace Hn\McpServer\Event;

/**
 * PSR-14 event dispatched after ReadTableTool has loaded a batch of records.
 *
 * Listeners can mutate the record array to enrich or redact fields — for
 * example, attaching file metadata to sys_file_reference rows, resolving
 * public URLs, or stripping sensitive columns before the result is handed
 * to the LLM.
 *
 * Records are passed as a batch per table so that listeners can perform
 * efficient single-query lookups (no N+1).
 *
 * Computed fields (mcp.computed=true) only appear when the caller listed
 * them in the `fields` parameter. Inline children always include them
 * regardless. Listeners producing computed fields should call
 * isFieldRequested() / shouldEnrich() to skip expensive work that no one
 * asked for.
 */
final class AfterRecordReadEvent
{
    /**
     * @param string $table The table the records come from
     * @param array<int, array<string, mixed>> $records Mutable records array
     * @param string $context 'top' for directly queried records, 'inline' for inline relation children
     * @param array<int, string> $requestedFields The caller's `fields` whitelist, empty when no whitelist
     */
    public function __construct(
        private readonly string $table,
        private array $records,
        private readonly string $context,
        private readonly array $requestedFields = [],
    ) {}

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function setRecords(array $records): void
    {
        $this->records = $records;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    /**
     * @return array<int, string>
     */
    public function getRequestedFields(): array
    {
        return $this->requestedFields;
    }

    public function isFieldRequested(string $field): bool
    {
        return in_array($field, $this->requestedFields, true);
    }

    /**
     * Returns true when at least one of the given fields should be produced.
     *
     * - Inline children always need enrichment (option a: embedded relations
     *   ignore the parent's `fields` filter).
     * - When the caller did not pass a `fields` whitelist, the default
     *   response includes every advertised field — including computed ones —
     *   so enrichment runs.
     * - When the caller did pass a whitelist, only run enrichment if at
     *   least one of the listener's fields is in it.
     */
    public function shouldEnrich(string ...$fields): bool
    {
        if ($this->context !== 'top') {
            return true;
        }
        if (empty($this->requestedFields)) {
            return true;
        }
        foreach ($fields as $field) {
            if ($this->isFieldRequested($field)) {
                return true;
            }
        }
        return false;
    }
}
