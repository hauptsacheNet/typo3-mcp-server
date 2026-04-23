<?php

declare(strict_types=1);

namespace Hn\McpServer\Event;

/**
 * PSR-14 event dispatched after TableAccessService discovers available fields for a table.
 *
 * Listeners can add, remove, or modify fields to control what the MCP tools expose.
 * This event propagates through all tools (ReadTable, WriteTable, GetTableSchema, SearchTool)
 * since they all use TableAccessService::getAvailableFields() as single source of truth.
 */
final class ModifyAvailableFieldsEvent
{
    /**
     * @param string $table The table name
     * @param string $type The record type (e.g. CType value, empty for default)
     * @param array<string, array> $fields Field name => TCA config array
     */
    public function __construct(
        private readonly string $table,
        private readonly string $type,
        private array $fields,
    ) {}

    public function getTable(): string
    {
        return $this->table;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, array>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @param array<string, array> $fields
     */
    public function setFields(array $fields): void
    {
        $this->fields = $fields;
    }

    public function removeField(string $fieldName): void
    {
        unset($this->fields[$fieldName]);
    }

    public function addField(string $fieldName, array $configuration): void
    {
        $this->fields[$fieldName] = $configuration;
    }

    public function hasField(string $fieldName): bool
    {
        return isset($this->fields[$fieldName]);
    }
}
