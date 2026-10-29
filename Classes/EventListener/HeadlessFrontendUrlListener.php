<?php

declare(strict_types=1);

namespace Hn\McpServer\EventListener;

use Hn\McpServer\Event\AfterRecordUrlGeneratedEvent;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Moves record URLs to the decoupled frontend of EXT:headless.
 *
 * In a headless site the site base serves JSON, visitors use the `frontendBase`
 * configured in the site (per language and base variant). EXT:headless rewrites
 * the URLs of the backend's view button, but only on paths the MCP server does
 * not take: on TYPO3 14 for router calls made during a backend request, on
 * TYPO3 13 for live-workspace previews plus an XClass of the workspace preview
 * builder. So without this listener every URL the assistant gets opens the JSON.
 *
 * It applies the same calls those EXT:headless listeners make, through the API
 * both EXT:headless 4 and 5 provide, so the site's headless mode decides as it
 * does for the view button: "full" rewrites, "mixed" follows the
 * headless.preview.overrideMode site setting. EXT:headless is an optional
 * dependency; class names are therefore only referenced as strings.
 */
final class HeadlessFrontendUrlListener implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const HEADLESS_MODE = 'FriendsOfTYPO3\\Headless\\Utility\\HeadlessModeInterface';
    private const FRONTEND_URL = 'FriendsOfTYPO3\\Headless\\Utility\\HeadlessFrontendUrlInterface';
    private const URL_UTILITY = 'FriendsOfTYPO3\\Headless\\Utility\\UrlUtility';

    public function __invoke(AfterRecordUrlGeneratedEvent $event): void
    {
        if (!ExtensionManagementUtility::isLoaded('headless') || !interface_exists(self::HEADLESS_MODE)) {
            return;
        }

        try {
            // The headless mode is derived from the site and language alone. The Accept
            // header goes: EXT:headless reads it for "mixed" mode, and the MCP client's
            // "application/json" must not decide where a backend user's browser would not.
            $request = ($GLOBALS['TYPO3_REQUEST'] ?? null) instanceof ServerRequestInterface
                ? $GLOBALS['TYPO3_REQUEST']
                : new ServerRequest($event->getUrl());
            $request = $request->withoutHeader('Accept');

            $boundRequest = GeneralUtility::makeInstance(self::HEADLESS_MODE)
                ->withRequest($request)
                ->overrideBackendRequestBySite($event->getSite(), $event->getLanguage());

            $event->setUrl(
                $this->getFrontendUrlUtility()
                    ->withRequest($boundRequest)
                    ->getFrontendUrlWithSite($event->getUrl(), $event->getSite())
            );
        } catch (\Throwable $e) {
            // A URL of the TYPO3 site base is still better than none
            $this->logger?->warning('EXT:headless could not rewrite the record URL', [
                'url' => $event->getUrl(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * EXT:headless 5 registers the interface as public service, 4 only the class.
     */
    private function getFrontendUrlUtility(): object
    {
        $container = GeneralUtility::getContainer();
        if ($container->has(self::FRONTEND_URL)) {
            return $container->get(self::FRONTEND_URL);
        }
        return GeneralUtility::makeInstance(self::URL_UTILITY);
    }
}
