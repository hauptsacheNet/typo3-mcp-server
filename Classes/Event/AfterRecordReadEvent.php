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
 */
final class AfterRecordReadEvent
{
    /**
     * @param string $table The table the records come from
     * @param array<int, array<string, mixed>> $records Mutable records array
     * @param string $context 'top' for directly queried records, 'inline' for inline relation children
     */
    public function __construct(
        private readonly string $table,
        private array $records,
        private readonly string $context,
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
}
