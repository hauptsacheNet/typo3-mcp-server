<?php

declare(strict_types=1);

namespace Hn\McpServer\EventListener;

use Hn\McpServer\Event\AfterRecordReadEvent;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Enriches sys_file_reference rows with a minimal sys_file summary, and
 * sys_file rows with a public_url, so the LLM can locate and load files
 * without having to compose URLs from raw storage/identifier columns.
 */
final class FileEnrichmentListener
{
    public function __invoke(AfterRecordReadEvent $event): void
    {
        match ($event->getTable()) {
            'sys_file_reference' => $this->enrichFileReferences($event),
            'sys_file' => $this->enrichFiles($event),
            default => null,
        };
    }

    private function enrichFileReferences(AfterRecordReadEvent $event): void
    {
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
            return $resourceFactory->getFileObject($fileUid)->getPublicUrl();
        } catch (\Exception) {
            return null;
        }
    }
}
