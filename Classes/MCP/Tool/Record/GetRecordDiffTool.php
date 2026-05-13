<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\Service\WorkspaceVersionService;
use Hn\McpServer\Service\WriteLogService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\DiffUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;

/**
 * Produce the diff payload consumed by the write_table MCP App widget.
 *
 * Returns a structured comparison between the live record and its workspace
 * version, plus a workspace preview URL and the current writeId. Restricted
 * to workspace-capable tables that the user can also write to — listing the
 * diff is conceptually a read operation on a pending write, so we require
 * the same access as ReadTable.
 */
class GetRecordDiffTool extends AbstractRecordTool
{
    protected WorkspaceVersionService $workspaceVersionService;
    protected WriteLogService $writeLogService;

    public function __construct()
    {
        parent::__construct();
        $this->workspaceVersionService = GeneralUtility::makeInstance(WorkspaceVersionService::class);
        $this->writeLogService = GeneralUtility::makeInstance(WriteLogService::class);
    }

    public function getSchema(): array
    {
        return [
            'description' => 'Internal companion tool for the write_table inline diff widget. '
                . 'Returns the per-field diff between the live record and its current workspace '
                . 'version, the current write counter, and a workspace preview URL. Intended to be '
                . 'called from the MCP App widget — calling it directly from chat is rarely useful.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table name of the record.',
                    ],
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The live UID of the record (same UID write_table returned).',
                    ],
                ],
                'required' => ['table', 'uid'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string)($params['table'] ?? '');
        $liveUid = (int)($params['uid'] ?? 0);

        if ($table === '' || $liveUid <= 0) {
            throw new ValidationException(['table and uid are required']);
        }

        $this->ensureTableAccess($table, 'read');

        // The MCP convention is that callers always pass a "live UID". In
        // practice that may be (a) a real live record UID with a workspace
        // overlay, or (b) the workspace UID of a brand-new record that has
        // never been in live. BackendUtility::getRecord queries by raw uid
        // so it doesn't disambiguate the two — we look at t3ver_state on the
        // row we get back to tell which situation we're in.
        $rawRow = BackendUtility::getRecord($table, $liveUid, '*', '', false);
        $isNewRecord = false;
        $liveRecord = null;
        $workspaceRecord = null;

        if (is_array($rawRow) && (int)($rawRow['t3ver_state'] ?? 0) === 1 && (int)($rawRow['t3ver_wsid'] ?? 0) > 0) {
            // Brand-new workspace record — the row IS the workspace record.
            $workspaceRecord = $rawRow;
            $isNewRecord = true;
        } else {
            $liveRecord = $rawRow;
            $workspaceUid = $this->workspaceVersionService->resolveToWorkspaceUid($table, $liveUid);
            if ($workspaceUid !== $liveUid) {
                $workspaceRecord = BackendUtility::getRecord($table, $workspaceUid, '*', '', false);
            }
        }

        $fields = $this->buildFieldDiffs(
            $table,
            $liveRecord ?? [],
            $workspaceRecord ?? $liveRecord ?? [],
            $isNewRecord
        );

        $recordLabel = '';
        $labelSource = $workspaceRecord ?? $liveRecord;
        if (is_array($labelSource) && $labelSource !== []) {
            $recordLabel = (string)BackendUtility::getRecordTitle($table, $labelSource, true);
        }

        return $this->createJsonResult([
            'table' => $table,
            'uid' => $liveUid,
            'currentWriteId' => $this->writeLogService->getCurrentWriteId($table, $liveUid),
            'hasWorkspaceChange' => $workspaceRecord !== null,
            'isNewRecord' => $isNewRecord,
            'isDeleted' => $this->isDeletePlaceholder($workspaceRecord),
            'recordLabel' => $recordLabel,
            'previewUrl' => $this->buildPreviewUrl($table, $liveUid),
            'fields' => $fields,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFieldDiffs(
        string $table,
        array $liveRecord,
        array $workspaceRecord,
        bool $isNewRecord
    ): array {
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

            $beforeRaw = $liveRecord[$fieldName] ?? null;
            $afterRaw = $workspaceRecord[$fieldName] ?? null;

            if (!$isNewRecord && $this->valuesEqual($beforeRaw, $afterRaw)) {
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
        // These house workspace bookkeeping, sorting, timestamps — they always
        // differ between live and workspace and add noise rather than signal.
        static $skip = [
            'uid', 'pid', 'tstamp', 'crdate', 'sorting', 'cruser_id',
            't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 't3_origuid',
        ];
        return in_array($fieldName, $skip, true);
    }

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
        // Treat null and empty string as equivalent — TCA inserts often store
        // either one depending on the column default.
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
        if (str_starts_with($label, 'LLL:')) {
            $translated = $GLOBALS['LANG']->sL($label) ?? '';
            return $translated !== '' ? $translated : $label;
        }
        return $label;
    }

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
