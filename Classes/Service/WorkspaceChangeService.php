<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Utility\RecordFormattingUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;
use TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * The unpublished change of a record, as an editor thinks of it.
 *
 * An inline child is part of its parent: a changed file reference is a change of the
 * content element, a content element inside a news record a change of the news. So the
 * change of a record is the change of its outermost inline ancestor (the "root"), with
 * every changed record below it, and it is published or discarded as a whole.
 *
 * See Documentation/Architecture/McpApps.md.
 */
class WorkspaceChangeService
{
    public const STATE_NEW = 'new';
    public const STATE_CHANGED = 'changed';
    public const STATE_MOVED = 'moved';
    public const STATE_DELETED = 'deleted';
    public const STATE_UNCHANGED = 'unchanged';

    /**
     * Longest value shown per field, in characters.
     */
    private const MAX_VALUE_LENGTH = 5000;

    /**
     * Inline nesting is shallow in practice (news > content > file reference),
     * this only guards against cyclic configurations.
     */
    private const MAX_DEPTH = 6;

    /**
     * Columns that change with every write or only carry workspace bookkeeping.
     */
    private const IGNORED_COLUMNS = ['l10n_diffsource', 'l10n_state', 't3ver_label', 'perms_userid', 'perms_groupid', 'perms_user', 'perms_group', 'perms_everybody'];

    protected InlineParentService $inlineParentService;
    protected TableAccessService $tableAccessService;
    protected TcaSchemaFactory $schemaFactory;

    public function __construct()
    {
        $this->inlineParentService = GeneralUtility::makeInstance(InlineParentService::class);
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
        $this->schemaFactory = GeneralUtility::makeInstance(TcaSchemaFactory::class);
    }

    /**
     * The outermost inline ancestor of a record, which an edit of the record changes.
     *
     * @return array{table: string, uid: int}
     */
    public function resolveRoot(string $table, int $uid): array
    {
        $root = ['table' => $table, 'uid' => $uid];
        $visited = [];
        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $visited[$root['table'] . ':' . $root['uid']] = true;
            $parents = $this->inlineParentService->findParents($root['table'], $root['uid']);
            $parent = $parents[0] ?? null;
            if ($parent === null || isset($visited[$parent['table'] . ':' . $parent['uid']])) {
                break;
            }
            $root = $parent;
        }

        return $root;
    }

    /**
     * The change of the root record and its inline descendants.
     *
     * @return array{record: array<string, mixed>, pending: bool}|null null if the record doesn't exist
     */
    public function describe(string $table, int $uid): ?array
    {
        $node = $this->buildNode($table, $uid, 0);
        if ($node === null) {
            return null;
        }

        return [
            'record' => $this->exportNode($node),
            'pending' => $this->hasChanges($node),
        ];
    }

    /**
     * The root record as it appears in the workspace, for URLs and titles.
     *
     * @return array<string, mixed>|null
     */
    public function getWorkspaceRecord(string $table, int $uid): ?array
    {
        $row = BackendUtility::getRecord($table, $uid);
        if (!is_array($row)) {
            return null;
        }
        $live = $row;
        BackendUtility::workspaceOL($table, $row);

        return is_array($row) ? $row : $live;
    }

    /**
     * Publish the root record and every version below it.
     *
     * @return list<string> errors reported by TYPO3, empty on success
     */
    public function publish(string $table, int $uid): array
    {
        $node = $this->buildNode($table, $uid, 0);
        if ($node === null) {
            return ['Record ' . $table . ':' . $uid . ' not found.'];
        }

        $commandMap = [];
        foreach ($this->collectVersions($node) as $version) {
            $commandMap[$version['table']][$version['liveUid']]['version'] = [
                'action' => 'publish',
                'swapWith' => $version['versionUid'],
            ];
        }

        return $this->processCommandMap($commandMap);
    }

    /**
     * Discard the versions of the root record and every record below it.
     *
     * @return list<string> errors reported by TYPO3, empty on success
     */
    public function discard(string $table, int $uid): array
    {
        $node = $this->buildNode($table, $uid, 0);
        if ($node === null) {
            return ['Record ' . $table . ':' . $uid . ' not found.'];
        }

        $commandMap = [];
        foreach ($this->collectVersions($node) as $version) {
            $commandMap[$version['table']][$version['versionUid']]['version'] = ['action' => 'clearWSID'];
        }

        return $this->processCommandMap($commandMap);
    }

    /**
     * Whether the current user may publish the root record's change: workspace publish
     * access as the workspace module checks it, and the publish stage where the workspace
     * only allows publishing from there.
     */
    public function canPublish(string $table, int $uid): bool
    {
        $workspaceId = $this->getWorkspaceId();
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($workspaceId === 0 || $backendUser === null) {
            return false;
        }
        if (!GeneralUtility::makeInstance(WorkspacePublishGate::class)->isGranted($backendUser, $workspaceId)) {
            return false;
        }
        $workspace = $backendUser->checkWorkspace($workspaceId);
        if (!is_array($workspace) || !((int)($workspace['publish_access'] ?? 0) & WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE)) {
            return true;
        }
        $node = $this->buildNode($table, $uid, 0);
        foreach ($node !== null ? $this->collectVersions($node) : [] as $version) {
            $row = BackendUtility::getRecord($version['table'], $version['versionUid'], 't3ver_stage');
            if ((int)($row['t3ver_stage'] ?? 0) !== StagesService::STAGE_PUBLISH_ID) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $commandMap
     * @return list<string>
     */
    protected function processCommandMap(array $commandMap): array
    {
        if ($commandMap === []) {
            return [];
        }
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start([], $commandMap);
        $dataHandler->process_cmdmap();

        return array_values(array_map('strval', $dataHandler->errorLog));
    }

    /**
     * A record of the change: its live and workspace row, and its inline children.
     *
     * @return array<string, mixed>|null
     */
    protected function buildNode(string $table, int $uid, int $depth, ?array $live = null, ?array $version = null): ?array
    {
        if ($live === null && $version === null) {
            $row = BackendUtility::getRecord($table, $uid);
            if (!is_array($row)) {
                return null;
            }
            if ((int)($row['t3ver_oid'] ?? 0) > 0) {
                // A version was passed, the change is described from the live record.
                $row = BackendUtility::getRecord($table, (int)$row['t3ver_oid']);
                if (!is_array($row)) {
                    return null;
                }
            }
            if ((int)($row['t3ver_wsid'] ?? 0) === 0) {
                $live = $row;
                $version = $this->getVersion($table, (int)$row['uid']);
            } else {
                // Created in the workspace, there is no live record.
                $version = $row;
            }
        }

        $fields = $this->diffFields($table, $live, $version);
        $state = $this->getState($live, $version, $fields !== []);
        $effective = $version ?? $live;
        $liveUid = (int)($live['uid'] ?? $version['uid']);

        $node = [
            'table' => $table,
            'uid' => $liveUid,
            'versionUid' => $version !== null ? (int)$version['uid'] : null,
            'state' => $state,
            'title' => RecordFormattingUtility::getRecordTitle($table, $state === self::STATE_DELETED ? ($live ?? $effective) : $effective),
            'fields' => $state === self::STATE_DELETED ? [] : $fields,
            'relations' => [],
        ];
        if ($state !== self::STATE_DELETED && $depth < self::MAX_DEPTH) {
            $node['relations'] = $this->buildRelations($table, $liveUid, $effective, $depth);
        }

        return $node;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getVersion(string $table, int $liveUid): ?array
    {
        $workspaceId = $this->getWorkspaceId();
        if ($workspaceId === 0 || !$this->isWorkspaceAware($table)) {
            return null;
        }
        $version = BackendUtility::getWorkspaceVersionOfRecord($workspaceId, $table, $liveUid);

        return is_array($version) ? $version : null;
    }

    protected function getState(?array $live, ?array $version, bool $fieldsChanged): string
    {
        if ($version === null) {
            return self::STATE_UNCHANGED;
        }
        $versionState = VersionState::tryFrom((int)($version['t3ver_state'] ?? 0));
        if ($live === null || $versionState === VersionState::NEW_PLACEHOLDER) {
            return self::STATE_NEW;
        }
        if ($versionState === VersionState::DELETE_PLACEHOLDER) {
            return self::STATE_DELETED;
        }
        if ($versionState === VersionState::MOVE_POINTER) {
            return self::STATE_MOVED;
        }
        return $fieldsChanged ? self::STATE_CHANGED : self::STATE_UNCHANGED;
    }

    /**
     * Fields that differ between the live and the workspace row, with display values.
     * For a new record every field of its type that holds a value.
     *
     * @return list<array{field: string, label: string, before: string, after: string}>
     */
    protected function diffFields(string $table, ?array $live, ?array $version): array
    {
        if ($version === null) {
            return [];
        }
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        $typeField = $this->tableAccessService->getTypeFieldName($table);
        $type = $typeField !== null ? (string)($version[$typeField] ?? '') : '';
        $fieldNames = $this->tableAccessService->getFieldNamesForType($table, $type);
        if ($fieldNames === []) {
            $fieldNames = array_keys($columns);
        }
        $pid = (int)($version['pid'] ?? 0);

        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $bookkeepingFields = array_filter([$ctrl['tstamp'] ?? null, $ctrl['crdate'] ?? null, $ctrl['origUid'] ?? null, $ctrl['transOrigDiffSourceField'] ?? null, $ctrl['sortby'] ?? null]);

        $fields = [];
        if ($live !== null && (int)$live['pid'] !== $pid) {
            $fields[] = [
                'field' => 'pid',
                'label' => $this->translate('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.page', 'Page'),
                'before' => $this->formatPage((int)$live['pid']),
                'after' => $this->formatPage($pid),
            ];
        }
        foreach ($fieldNames as $field) {
            $config = $columns[$field]['config'] ?? null;
            if (!is_array($config)
                || in_array($field, self::IGNORED_COLUMNS, true)
                || in_array($field, $bookkeepingFields, true)
                || str_starts_with($field, 't3ver_')
                || in_array($config['type'] ?? '', ['inline', 'file', 'passthrough', 'none'], true)
                || !$this->tableAccessService->canAccessField($table, $field, $type, $pid)
            ) {
                continue;
            }
            // A new record shows what was set, not every default
            if ($live === null && $this->isEmptyOrDefault($version[$field] ?? null, $config)) {
                continue;
            }
            $after = $this->formatValue($table, $field, $version[$field] ?? null, $version);
            $before = $live !== null ? $this->formatValue($table, $field, $live[$field] ?? null, $live) : '';
            // DataHandler fills an empty slug of a live record when it versions the record,
            // that is not a change of the editor (and of an unchanged parent).
            $isGeneratedSlug = $live !== null && ($config['type'] ?? '') === 'slug' && $before === '';
            if ($before === $after || $isGeneratedSlug) {
                continue;
            }
            $fields[] = [
                'field' => $field,
                'label' => $this->getFieldLabel($table, $field),
                'before' => $before,
                'after' => $after,
            ];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $config TCA config of the field
     */
    protected function isEmptyOrDefault(mixed $value, array $config): bool
    {
        return $value === null
            || (string)$value === ''
            || (string)$value === '0'
            || (is_scalar($config['default'] ?? null) && (string)$value === (string)$config['default']);
    }

    /**
     * The inline children of a record, grouped by field. Only children with a change
     * (or a changed descendant) are kept.
     *
     * @param array<string, mixed> $parentRow the parent as it is in the workspace
     * @return list<array{field: string, label: string, records: list<array<string, mixed>>}>
     */
    protected function buildRelations(string $table, int $parentLiveUid, array $parentRow, int $depth): array
    {
        $relations = [];
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        foreach ($columns as $field => $column) {
            $config = $column['config'] ?? [];
            $childTable = (string)($config['foreign_table'] ?? '');
            $foreignField = (string)($config['foreign_field'] ?? '');
            if (!in_array($config['type'] ?? '', ['inline', 'file'], true)
                || $childTable === ''
                || $foreignField === ''
                || !$this->isWorkspaceAware($childTable)
            ) {
                continue;
            }

            $children = [];
            foreach ($this->fetchChildren($childTable, $config, $table, $parentLiveUid, (int)$parentRow['uid']) as [$live, $version]) {
                $child = $this->buildNode($childTable, (int)($live['uid'] ?? $version['uid']), $depth + 1, $live, $version);
                if ($child !== null && $this->hasChanges($child)) {
                    $children[] = $child;
                }
            }
            if ($children !== []) {
                $relations[] = [
                    'field' => (string)$field,
                    'label' => $this->getFieldLabel($table, (string)$field),
                    'records' => $children,
                ];
            }
        }

        return $relations;
    }

    /**
     * Live rows and workspace versions of the children of one inline field, paired up
     * and in display order. A child that was moved to another parent in the workspace
     * counts as deleted here.
     *
     * @param array<string, mixed> $config TCA config of the inline field
     * @return list<array{0: array<string, mixed>|null, 1: array<string, mixed>|null}>
     */
    protected function fetchChildren(string $childTable, array $config, string $parentTable, int $parentLiveUid, int $parentVersionUid): array
    {
        $workspaceId = $this->getWorkspaceId();
        $foreignField = (string)$config['foreign_field'];
        $foreignTableField = (string)($config['foreign_table_field'] ?? '');
        $matchFields = is_array($config['foreign_match_fields'] ?? null) ? $config['foreign_match_fields'] : [];

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($childTable);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder
            ->select('*')
            ->from($childTable)
            ->where(
                $queryBuilder->expr()->in(
                    $foreignField,
                    $queryBuilder->createNamedParameter(array_unique([$parentLiveUid, $parentVersionUid]), ArrayParameterType::INTEGER)
                ),
                $queryBuilder->expr()->in('t3ver_wsid', $queryBuilder->createNamedParameter(array_unique([0, $workspaceId]), ArrayParameterType::INTEGER))
            );
        if ($foreignTableField !== '') {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($foreignTableField, $queryBuilder->createNamedParameter($parentTable)));
        }
        foreach ($matchFields as $matchField => $matchValue) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq((string)$matchField, $queryBuilder->createNamedParameter((string)$matchValue)));
        }

        $pairs = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $liveUid = (int)$row['t3ver_oid'] ?: (int)$row['uid'];
            if ((int)$row['t3ver_wsid'] === 0) {
                $pairs[$liveUid][0] = $row;
            } else {
                $pairs[$liveUid][1] = $row;
            }
        }

        $children = [];
        foreach ($pairs as $liveUid => $pair) {
            $live = $pair[0] ?? null;
            $version = $pair[1] ?? null;
            if ($live === null && $version !== null && (int)$version['t3ver_oid'] > 0) {
                // A version that was moved here from another parent.
                $live = BackendUtility::getRecord($childTable, $liveUid);
            }
            if ($live !== null && $version === null) {
                $version = $this->getVersion($childTable, $liveUid);
                if ($version !== null && !$this->pointsTo($version, $config, $parentTable, $parentLiveUid, $parentVersionUid)) {
                    // Moved away to another parent: gone from this one.
                    $version = ['t3ver_state' => VersionState::DELETE_PLACEHOLDER->value] + $version;
                }
            }
            $children[] = [$live, $version];
        }

        $sortField = (string)($config['foreign_sortby'] ?? $GLOBALS['TCA'][$childTable]['ctrl']['sortby'] ?? '');
        if ($sortField !== '') {
            usort($children, static function (array $a, array $b) use ($sortField): int {
                return (int)(($a[1] ?? $a[0])[$sortField] ?? 0) <=> (int)(($b[1] ?? $b[0])[$sortField] ?? 0);
            });
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $config
     */
    protected function pointsTo(array $row, array $config, string $parentTable, int $parentLiveUid, int $parentVersionUid): bool
    {
        if (!in_array((int)($row[$config['foreign_field']] ?? 0), [$parentLiveUid, $parentVersionUid], true)) {
            return false;
        }
        $foreignTableField = (string)($config['foreign_table_field'] ?? '');

        return $foreignTableField === '' || (string)($row[$foreignTableField] ?? '') === $parentTable;
    }

    /**
     * @param array<string, mixed> $node
     */
    protected function hasChanges(array $node): bool
    {
        return $node['state'] !== self::STATE_UNCHANGED || $node['relations'] !== [];
    }

    /**
     * Every record of the change that has a version in the workspace, including
     * unchanged versions (staged along with a parent), children first.
     *
     * @param array<string, mixed> $node
     * @return list<array{table: string, liveUid: int, versionUid: int}>
     */
    protected function collectVersions(array $node): array
    {
        $versions = [];
        // Descendants without changes are not part of the node tree, ask for all of them.
        foreach ($this->fetchVersionedDescendants($node['table'], $node['uid'], 0) as $descendant) {
            $versions[] = $descendant;
        }
        if ($node['versionUid'] !== null) {
            $versions[] = [
                'table' => $node['table'],
                // A record created in the workspace is published by its own uid.
                'liveUid' => $node['state'] === self::STATE_NEW ? $node['versionUid'] : $node['uid'],
                'versionUid' => $node['versionUid'],
            ];
        }

        return $versions;
    }

    /**
     * @return list<array{table: string, liveUid: int, versionUid: int}>
     */
    protected function fetchVersionedDescendants(string $table, int $liveUid, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }
        $versionUid = (int)($this->getVersion($table, $liveUid)['uid'] ?? $liveUid);
        $versions = [];
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $column) {
            $config = $column['config'] ?? [];
            $childTable = (string)($config['foreign_table'] ?? '');
            if (!in_array($config['type'] ?? '', ['inline', 'file'], true)
                || $childTable === ''
                || ($config['foreign_field'] ?? '') === ''
                || !$this->isWorkspaceAware($childTable)
            ) {
                continue;
            }
            foreach ($this->fetchChildren($childTable, $config, $table, $liveUid, $versionUid) as [$live, $version]) {
                $childLiveUid = (int)($live['uid'] ?? $version['uid']);
                array_push($versions, ...$this->fetchVersionedDescendants($childTable, $childLiveUid, $depth + 1));
                if ($version !== null && isset($version['uid'])) {
                    $isNew = $live === null;
                    $versions[] = [
                        'table' => $childTable,
                        'liveUid' => $isNew ? (int)$version['uid'] : $childLiveUid,
                        'versionUid' => (int)$version['uid'],
                    ];
                }
            }
        }

        return $versions;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    protected function exportNode(array $node): array
    {
        return [
            'table' => $node['table'],
            'uid' => $node['uid'],
            'tableLabel' => RecordFormattingUtility::getTableLabel($node['table']),
            'title' => $this->truncate((string)$node['title'], 200),
            'state' => $node['state'],
            'fields' => $node['fields'],
            'relations' => array_map(fn(array $relation): array => [
                'field' => $relation['field'],
                'label' => $relation['label'],
                'records' => array_map($this->exportNode(...), $relation['records']),
            ], $node['relations']),
        ];
    }

    /**
     * A field value as an editor reads it: labels of selected items, titles of
     * related records, text of rich text.
     *
     * @param array<string, mixed> $row
     */
    protected function formatValue(string $table, string $field, mixed $value, array $row): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $config = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];
        $type = $config['type'] ?? '';

        if ($type === 'text' && !empty($config['enableRichtext'])) {
            return $this->truncate($this->htmlToText((string)$value));
        }
        if ($type === 'flex') {
            return $this->truncate($this->flexFormToText((string)$value));
        }
        if ($type === 'check' && empty($config['items'])) {
            // getProcessedValue() spells a single checkbox as "Yes"/"No" only with items
            return (int)$value ? '✓' : '';
        }

        try {
            $processed = BackendUtility::getProcessedValue(
                $table,
                $field,
                $value,
                0,
                false,
                false,
                (int)($row['uid'] ?? 0),
                true,
                (int)($row['pid'] ?? 0),
                $row
            );
        } catch (\Throwable) {
            $processed = null;
        }

        return $this->truncate((string)($processed ?? (is_scalar($value) ? $value : '')));
    }

    protected function htmlToText(string $html): string
    {
        $text = preg_replace('/<(br|\/p|\/h[1-6]|\/li|\/div|\/tr)\b[^>]*>/i', "$0\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    protected function flexFormToText(string $xml): string
    {
        $data = GeneralUtility::xml2array($xml);
        if (!is_array($data)) {
            return '';
        }
        $lines = [];
        foreach ($data['data'] ?? [] as $sheet) {
            foreach ((array)($sheet['lDEF'] ?? []) as $name => $field) {
                $value = is_array($field) ? ($field['vDEF'] ?? '') : '';
                if (is_scalar($value) && (string)$value !== '') {
                    $lines[] = $name . ': ' . $value;
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function formatPage(int $pid): string
    {
        $page = BackendUtility::getRecordWSOL('pages', $pid, 'uid,pid,title');
        $title = is_array($page) ? RecordFormattingUtility::getRecordTitle('pages', $page) : '';

        return $title !== '' ? $title . ' [' . $pid . ']' : '[' . $pid . ']';
    }

    protected function getFieldLabel(string $table, string $field): string
    {
        $label = (string)($GLOBALS['TCA'][$table]['columns'][$field]['label'] ?? '');
        $translated = $label !== '' ? TableAccessService::translateLabel($label) : '';

        return $translated !== '' ? $translated : $field;
    }

    protected function translate(string $label, string $fallback): string
    {
        $translated = TableAccessService::translateLabel($label);

        return $translated !== '' && $translated !== $label && !str_starts_with($translated, 'LLL:') ? $translated : $fallback;
    }

    protected function truncate(string $value, int $length = self::MAX_VALUE_LENGTH): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) . '…' : $value;
    }

    protected function isWorkspaceAware(string $table): bool
    {
        return $this->schemaFactory->has($table)
            && $this->schemaFactory->get($table)->hasCapability(TcaSchemaCapability::Workspace);
    }

    protected function getWorkspaceId(): int
    {
        return (int)($GLOBALS['BE_USER']->workspace ?? 0);
    }
}
