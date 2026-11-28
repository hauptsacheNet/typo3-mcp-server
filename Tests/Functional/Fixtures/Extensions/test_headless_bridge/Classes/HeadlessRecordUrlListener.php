<?php

declare(strict_types=1);

namespace Hn\TestHeadlessBridge;

use FriendsOfTYPO3\Headless\Utility\HeadlessModeInterface;
use FriendsOfTYPO3\Headless\Utility\UrlUtility;
use Hn\McpServer\Event\AfterRecordUrlGeneratedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The site package listener for TYPO3 13 with EXT:headless 4, verbatim as
 * documented in the README. TYPO3 14 with EXT:headless 5 needs none.
 */
#[AsEventListener]
final class HeadlessRecordUrlListener
{
    public function __invoke(AfterRecordUrlGeneratedEvent $event): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? new ServerRequest($event->getUrl());
        $request = GeneralUtility::makeInstance(HeadlessModeInterface::class)
            ->withRequest($request->withoutHeader('Accept'))
            ->overrideBackendRequestBySite($event->getSite(), $event->getLanguage());
        $event->setUrl(
            GeneralUtility::makeInstance(UrlUtility::class)
                ->withRequest($request)
                ->getFrontendUrlWithSite($event->getUrl(), $event->getSite())
        );
    }
}
