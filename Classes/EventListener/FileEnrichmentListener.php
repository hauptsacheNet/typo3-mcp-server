<?php

declare(strict_types=1);

namespace Hn\McpServer\EventListener;

use Hn\McpServer\Event\AfterRecordReadEvent;
use Hn\McpServer\Event\AfterSchemaLoadEvent;
use Hn\McpServer\Service\SiteInformationService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adds and populates a small set of computed read-only fields:
 *
 * - sys_file gets `public_url` so the LLM can browse fileadmin and load
 *   the file without composing URLs from `storage` and `identifier`.
 * - sys_file_reference gets `file_name`, `file_identifier`,
 *   `file_mime_type`, `file_size` and `public_url` derived from the
 *   referenced sys_file.
 *
 * Discovery and filtering work via the `mcp.computed` marker:
 * - AfterSchemaLoadEvent registers each computed field, so it appears in
 *   GetTableSchema (under "Computed (read-only)") and ReadTable lets it
 *   through the schema filter.
 * - AfterRecordReadEvent enriches when relevant: inline children always
 *   include the fields (option a — embedded relations ignore the parent's
 *   `fields` filter), top-level reads only when the caller listed at
 *   least one computed field. Skipping the sys_file lookup avoids the
 *   cost of getPublicUrl() on bulk reads no one asked enrichment for.
 */
final class FileEnrichmentListener
{
    private const SYS_FILE_FIELDS = ['public_url'];
    private const SYS_FILE_REFERENCE_FIELDS = [
        'file_name',
        'file_identifier',
        'file_mime_type',
        'file_size',
        'public_url',
    ];

    public function onSchemaLoad(AfterSchemaLoadEvent $event): void
    {
        match ($event->getTable()) {
            'sys_file' => $this->declareSysFileFields($event),
            'sys_file_reference' => $this->declareSysFileReferenceFields($event),
            default => null,
        };
    }

    public function onRecordRead(AfterRecordReadEvent $event): void
    {
        match ($event->getTable()) {
            'sys_file_reference' => $this->enrichFileReferences($event),
            'sys_file' => $this->enrichFiles($event),
            default => null,
        };
    }

    private function declareSysFileFields(AfterSchemaLoadEvent $event): void
    {
        $event->addField('public_url', [
            'label' => 'Public URL',
            'description' => 'Resolved frontend URL of the file. Computed read-only.',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
            'mcp' => ['computed' => true],
        ]);
    }

    private function declareSysFileReferenceFields(AfterSchemaLoadEvent $event): void
    {
        $declarations = [
            'file_name' => 'Filename of the referenced sys_file.',
            'file_identifier' => 'Storage path of the referenced sys_file.',
            'file_mime_type' => 'MIME type of the referenced sys_file.',
            'file_size' => 'Size in bytes of the referenced sys_file.',
            'public_url' => 'Resolved frontend URL of the referenced sys_file.',
        ];
        foreach ($declarations as $name => $description) {
            $event->addField($name, [
                'label' => $name,
                'description' => $description . ' Computed read-only.',
                'config' => [
                    'type' => $name === 'file_size' ? 'number' : 'input',
                    'readOnly' => true,
                ],
                'mcp' => ['computed' => true],
            ]);
        }
    }

    private function enrichFileReferences(AfterRecordReadEvent $event): void
    {
        if (!$event->shouldEnrich(...self::SYS_FILE_REFERENCE_FIELDS)) {
            return;
        }

        $records = $event->getRecords();
        if (empty($records)) {
            return;
        }

        $fileUids = [];
        foreach ($records as $record) {
            $fileUid = (int)($record['uid_local'] ?? 0);
            if ($fileUid > 0) {
                $fileUids[$fileUid] = true;
            }
        }
        if (empty($fileUids)) {
            return;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();

        $fileRecords = $queryBuilder
            ->select('uid', 'name', 'identifier', 'storage', 'type', 'mime_type', 'size')
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter(array_keys($fileUids), Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $fileMap = [];
        foreach ($fileRecords as $fileRecord) {
            $fileMap[(int)$fileRecord['uid']] = $fileRecord;
        }

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);

        foreach ($records as &$record) {
            $fileUid = (int)($record['uid_local'] ?? 0);
            if ($fileUid > 0 && isset($fileMap[$fileUid])) {
                $file = $fileMap[$fileUid];
                $record['file_name'] = $file['name'];
                $record['file_identifier'] = $file['identifier'];
                $record['file_mime_type'] = $file['mime_type'];
                $record['file_size'] = (int)$file['size'];
                $record['public_url'] = $this->resolvePublicUrl($resourceFactory, $fileUid);
            }
        }
        unset($record);

        $event->setRecords($records);
    }

    private function enrichFiles(AfterRecordReadEvent $event): void
    {
        if (!$event->shouldEnrich(...self::SYS_FILE_FIELDS)) {
            return;
        }

        $records = $event->getRecords();
        if (empty($records)) {
            return;
        }

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        foreach ($records as &$record) {
            $fileUid = (int)($record['uid'] ?? 0);
            if ($fileUid > 0) {
                $record['public_url'] = $this->resolvePublicUrl($resourceFactory, $fileUid);
            }
        }
        unset($record);

        $event->setRecords($records);
    }

    private function resolvePublicUrl(ResourceFactory $resourceFactory, int $fileUid): ?string
    {
        try {
            $url = $resourceFactory->getFileObject($fileUid)->getPublicUrl();
        } catch (\Exception) {
            return null;
        }
        // TYPO3 returns a path relative to the document root; the LLM needs an
        // absolute URL to actually fetch the file from the outside.
        $siteInfo = GeneralUtility::makeInstance(SiteInformationService::class);
        return $siteInfo->makeAbsoluteUrl($url);
    }
}
