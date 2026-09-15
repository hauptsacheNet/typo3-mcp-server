<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\AbstractOnlineMediaHelper;

/**
 * Stand-in for third-party online media helpers that only support other
 * creation paths (e.g. a JSON payload from a media database) and throw from
 * transformUrlToFile() instead of returning null as the interface requires.
 * Registered in TYPO3_CONF_VARS['SYS']['fal']['onlineMediaHelpers'] by the
 * tests that need it.
 */
class ThrowingOnlineMediaHelper extends AbstractOnlineMediaHelper
{
    public static int $calls = 0;

    public function transformUrlToFile($url, Folder $targetFolder)
    {
        self::$calls++;
        throw new \Exception('Not implemented. This media adapter requires more than just an url to create a file.');
    }

    public function getPublicUrl(File $file)
    {
        return '';
    }

    public function getPreviewImage(File $file)
    {
        return '';
    }

    public function getMetaData(File $file)
    {
        return [];
    }
}
