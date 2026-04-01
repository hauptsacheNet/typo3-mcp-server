<?php

declare(strict_types=1);

namespace Hn\McpServer\Event;

/**
 * PSR-14 event dispatched after WriteTableTool successfully creates or updates a record.
 *
 * This event is read-only — the operation has already completed. Use it for:
 * - Audit logging of AI-initiated writes
 * - Triggering side effects (cache clearing, webhook notifications)
 * - Analytics and tracking of MCP operations
 *
 * Not dispatched on errors or vetoed operations.
 */
final class AfterRecordWriteEvent
{
    /**
     * @param string $table The target table
     * @param string $action The action that was performed: 'create', 'update', or 'delete'
     * @param int $uid The record UID (live UID for workspace transparency)
     * @param array $data The record data that was written (empty for delete)
     * @param int|null $pid Page ID (only for create)
     */
    public function __construct(
        private readonly string $table,
        private readonly string $action,
        private readonly int $uid,
        private readonly array $data,
        private readonly ?int $pid,
    ) {}

    public function getTable(): string
    {
        return $this->table;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getUid(): int
    {
        return $this->uid;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }
}
