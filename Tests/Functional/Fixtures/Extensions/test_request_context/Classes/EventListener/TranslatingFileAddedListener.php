<?php

declare(strict_types=1);

namespace Hn\TestRequestContext\EventListener;

use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Translates a label without passing a request, so LocalizationUtility falls
 * back to $GLOBALS['TYPO3_REQUEST'], as listeners in real projects do.
 */
final class TranslatingFileAddedListener
{
    public function __invoke(AfterFileAddedEvent $event): void
    {
        LocalizationUtility::translate('LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:save');
    }
}
