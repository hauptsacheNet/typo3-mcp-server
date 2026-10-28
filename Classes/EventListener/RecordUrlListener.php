<?php

declare(strict_types=1);

namespace Hn\McpServer\EventListener;

use Hn\McpServer\Event\AfterRecordReadEvent;
use Hn\McpServer\Event\AfterSchemaLoadEvent;
use Hn\McpServer\Service\RecordUrlService;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adds the computed read-only fields `frontend_url` and `preview_url` to records
 * that can be shown in the frontend: pages, content elements and every table an
 * integrator configured TCEMAIN.preview.<table> for (see RecordUrlService).
 *
 * - AfterSchemaLoadEvent declares the fields where the table is linkable at the
 *   page the schema is resolved for, so GetTableSchema lists them and ReadTable
 *   lets them through.
 * - AfterRecordReadEvent fills them for directly read records only. Inline
 *   children are skipped: a content element embedded in a news record lives in
 *   a storage folder and has no URL of its own.
 */
final class RecordUrlListener
{
    private const FIELDS = ['frontend_url', 'preview_url'];

    public function __construct(
        private readonly RecordUrlService $recordUrlService,
    ) {}

    public function onSchemaLoad(AfterSchemaLoadEvent $event): void
    {
        $table = $event->getTable();
        $pid = GeneralUtility::makeInstance(TableAccessService::class)->resolveTSconfigPid($event->getPid());
        if (!$this->recordUrlService->isLinkableTable($table, $pid)) {
            return;
        }

        $declarations = [
            'frontend_url' => 'URL of this record on the live website. Missing when the record is not published yet '
                . 'or is not shown on a page of its own.',
        ];
        if ($this->recordUrlService->isPreviewLinkEnabled()) {
            $declarations['preview_url'] = 'Only present when the record has unpublished changes: a temporary URL '
                . 'that shows the website including all unpublished changes, without login. Use it to show '
                . 'the result of edits. It expires after a few days and grants read access to all unpublished '
                . 'changes, so share it only with people who may see them.';
        }
        foreach ($declarations as $name => $description) {
            // Never shadow a real column of the same name
            if (isset($GLOBALS['TCA'][$table]['columns'][$name])) {
                continue;
            }
            $event->addField($name, [
                'label' => $name,
                'description' => $description . ' Computed read-only.',
                'config' => [
                    'type' => 'input',
                    'readOnly' => true,
                ],
                'mcp' => ['computed' => true],
            ]);
        }
    }

    public function onRecordRead(AfterRecordReadEvent $event): void
    {
        if ($event->getContext() !== 'top' || !$event->shouldEnrich(...self::FIELDS)) {
            return;
        }
        $table = $event->getTable();
        if (array_intersect(self::FIELDS, array_keys($GLOBALS['TCA'][$table]['columns'] ?? [])) !== []) {
            return;
        }

        $records = $event->getRecords();
        if ($records === []) {
            return;
        }

        foreach ($this->recordUrlService->getUrls($table, $records) as $key => $urls) {
            $records[$key] = array_replace($records[$key], $urls);
        }
        $event->setRecords($records);
    }
}
