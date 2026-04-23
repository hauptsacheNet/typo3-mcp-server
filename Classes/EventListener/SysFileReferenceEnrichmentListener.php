<?php

declare(strict_types=1);

namespace Hn\McpServer\EventListener;

use Hn\McpServer\Event\AfterRecordReadEvent;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Enriches sys_file_reference rows with a minimal sys_file summary so the LLM
 * knows which file each reference points at without having to do a follow-up
 * read on sys_file.
 */
final class SysFileReferenceEnrichmentListener
{
    public function __invoke(AfterRecordReadEvent $event): void
    {
        if ($event->getTable() !== 'sys_file_reference') {
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

                try {
                    $record['public_url'] = $resourceFactory->getFileObject($fileUid)->getPublicUrl();
                } catch (\Exception $e) {
                    $record['public_url'] = null;
                }
            }
        }
        unset($record);

        $event->setRecords($records);
    }
}
