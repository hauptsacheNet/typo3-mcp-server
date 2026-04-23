<?php

declare(strict_types=1);

namespace Hn\McpServer\Event;

/**
 * PSR-14 event dispatched before WriteTableTool processes a write or move operation.
 *
 * Dispatched after parameter validation and ISO code conversion, but before
 * validateRecordData() and DataHandler execution. This allows listeners to:
 * - Modify data before validation and storage (auto-populate fields, normalize values)
 * - Veto the operation with a reason (custom access control, policy enforcement)
 * - Distinguish AI-originated writes from human-originated writes
 */
final class BeforeRecordWriteEvent
{
    private bool $vetoed = false;
    private ?string $vetoReason = null;

    /**
     * @param string $table The target table
     * @param string $action The action: 'create', 'update', 'delete', 'translate', or 'move'
     * @param array $data The record data (mutable)
     * @param int|null $uid Record UID (null for create)
     * @param int|null $pid Page ID (null for update/delete)
     */
    public function __construct(
        private readonly string $table,
        private readonly string $action,
        private array $data,
        private readonly ?int $uid,
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

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function getUid(): ?int
    {
        return $this->uid;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function veto(string $reason): void
    {
        $this->vetoed = true;
        $this->vetoReason = $reason;
    }

    public function isVetoed(): bool
    {
        return $this->vetoed;
    }

    public function getVetoReason(): ?string
    {
        return $this->vetoReason;
    }
}
