<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\DiffUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;

/**
 * Build the structured diff payload that the write_table MCP App widget
 * renders inline after every write.
 *
 * Payload shape (also returned as CallToolResult::$structuredContent so MCP
 * App hosts surface it via window.openai.toolOutput):
 *   {
 *     table, uid, action, writeId, currentWriteId, recordLabel,
 *     hasWorkspaceChange, isNewRecord, isDeleted,
 *     previewUrl,            // null when the record isn't placed on a page
 *     fields: [{name, label, type, isText, before, after, diffHtml}]
 *   }
 */
class RecordDiffBuilder
{
    private WorkspaceVersionService $workspaceVersionService;

    public function __construct(?WorkspaceVersionService $workspaceVersionService = null)
    {
        $this->workspaceVersionService = $workspaceVersionService
            ?? GeneralUtility::makeInstance(WorkspaceVersionService::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $table, int $liveUid, string $action, int $writeId, int $currentWriteId): array
    {
        // The MCP convention exposes only live UIDs. In practice that uid may
        // be (a) a real live record uid with a workspace overlay, or (b) the
        // workspace uid of a brand-new record that has never been in live.
        // BackendUtility::getRecord queries by raw uid so it doesn't
        // disambiguate the two — t3ver_state on the row tells us which.
        $rawRow = BackendUtility::getRecord($table, $liveUid, '*', '', false);
        $isNewRecord = false;
        $liveRecord = null;
        $workspaceRecord = null;

        if (is_array($rawRow) && (int)($rawRow['t3ver_state'] ?? 0) === 1 && (int)($rawRow['t3ver_wsid'] ?? 0) > 0) {
            $workspaceRecord = $rawRow;
            $isNewRecord = true;
        } else {
            $liveRecord = $rawRow;
            $workspaceUid = $this->workspaceVersionService->resolveToWorkspaceUid($table, $liveUid);
            if ($workspaceUid !== $liveUid) {
                $workspaceRecord = BackendUtility::getRecord($table, $workspaceUid, '*', '', false);
            }
        }

        // For deletes, the workspace record is the delete placeholder; surface
        // every previously-set field as a removal by treating the live row as
        // the "before" and an empty row as the "after".
        $isDeleted = $this->isDeletePlaceholder($workspaceRecord);
        $afterRow = $isDeleted ? [] : ($workspaceRecord ?? $liveRecord ?? []);
        $beforeRow = $liveRecord ?? [];

        $fields = $this->buildFieldDiffs($table, $beforeRow, $afterRow, $isNewRecord || $isDeleted);

        $recordLabel = '';
        $labelSource = $workspaceRecord ?? $liveRecord;
        if (is_array($labelSource) && $labelSource !== []) {
            $recordLabel = (string)BackendUtility::getRecordTitle($table, $labelSource, true);
        }

        return [
            'table' => $table,
            'uid' => $liveUid,
            'action' => $action,
            'writeId' => $writeId,
            'currentWriteId' => $currentWriteId,
            'hasWorkspaceChange' => $workspaceRecord !== null,
            'isNewRecord' => $isNewRecord,
            'isDeleted' => $isDeleted,
            'recordLabel' => $recordLabel,
            'previewUrl' => $this->buildPreviewUrl($table, $liveUid),
            'fields' => $fields,
        ];
    }

    /**
     * @param array<string, mixed> $beforeRow
     * @param array<string, mixed> $afterRow
     * @return array<int, array<string, mixed>>
     */
    private function buildFieldDiffs(string $table, array $beforeRow, array $afterRow, bool $forceShowAllChanges): array
    {
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        if ($columns === []) {
            return [];
        }

        $diffUtility = GeneralUtility::makeInstance(DiffUtility::class);
        $diffs = [];

        foreach ($columns as $fieldName => $columnConfig) {
            if ($this->shouldSkipField($fieldName)) {
                continue;
            }

            $beforeRaw = $beforeRow[$fieldName] ?? null;
            $afterRaw = $afterRow[$fieldName] ?? null;

            if (!$forceShowAllChanges && $this->valuesEqual($beforeRaw, $afterRaw)) {
                continue;
            }

            $config = $columnConfig['config'] ?? [];
            $type = (string)($config['type'] ?? 'input');
            $isText = $this->isTextField($type, $config);
            $label = $this->translateLabel($columnConfig['label'] ?? $fieldName);

            $entry = [
                'name' => $fieldName,
                'label' => $label,
                'type' => $type,
                'isText' => $isText,
                'before' => $this->normaliseScalar($beforeRaw),
                'after' => $this->normaliseScalar($afterRaw),
                'diffHtml' => null,
            ];

            if ($isText) {
                $entry['diffHtml'] = $diffUtility->diff(
                    (string)($beforeRaw ?? ''),
                    (string)($afterRaw ?? '')
                );
            }

            $diffs[] = $entry;
        }

        return $diffs;
    }

    private function shouldSkipField(string $fieldName): bool
    {
        // These house workspace bookkeeping, sorting and timestamps — they
        // always differ between live and workspace and add noise rather than
        // signal in a user-facing diff.
        static $skip = [
            'uid', 'pid', 'tstamp', 'crdate', 'sorting', 'cruser_id',
            't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 't3_origuid',
        ];
        return in_array($fieldName, $skip, true);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isTextField(string $type, array $config): bool
    {
        if ($type === 'text') {
            return true;
        }
        if ($type === 'input') {
            $renderType = (string)($config['renderType'] ?? '');
            $eval = (string)($config['eval'] ?? '');
            // Skip fields that hold scalar/numeric/date values — DiffUtility
            // would produce noise on those.
            if ($renderType !== '' && $renderType !== 'inputLink') {
                return false;
            }
            foreach (['int', 'double2', 'datetime', 'date', 'time', 'timesec', 'num'] as $marker) {
                if (str_contains($eval, $marker)) {
                    return false;
                }
            }
            return true;
        }
        if ($type === 'slug') {
            return true;
        }
        return false;
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $aN = $a === null ? '' : (string)$a;
        $bN = $b === null ? '' : (string)$b;
        return $aN === $bN;
    }

    private function normaliseScalar(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_scalar($value)) {
            return $value;
        }
        return (string)json_encode($value);
    }

    private function translateLabel(string $label): string
    {
        if ($label === '') {
            return $label;
        }
        if (!str_starts_with($label, 'LLL:')) {
            return $label;
        }
        try {
            $translated = $GLOBALS['LANG']->sL($label) ?? '';
        } catch (\Throwable $e) {
            // Extension referenced in TCA labels may not be loaded in this
            // request (e.g. running tests against tt_content with EXT:news TCA
            // also pulling foreign LLL refs). Fall back to the raw key rather
            // than fail the entire write.
            return $label;
        }
        return $translated !== '' ? $translated : $label;
    }

    /**
     * @param array<string, mixed>|null $workspaceRecord
     */
    private function isDeletePlaceholder(?array $workspaceRecord): bool
    {
        if ($workspaceRecord === null) {
            return false;
        }
        return (int)($workspaceRecord['t3ver_state'] ?? 0) === 2;
    }

    private function buildPreviewUrl(string $table, int $liveUid): ?string
    {
        try {
            $builder = GeneralUtility::makeInstance(PreviewUriBuilder::class);
            $uri = $builder->buildUriForElement($table, $liveUid);
            $uri = trim((string)$uri);
            return $uri !== '' ? $uri : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
