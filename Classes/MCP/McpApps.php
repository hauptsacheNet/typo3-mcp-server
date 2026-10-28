<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Mcp\Server\Server;
use Mcp\Types\ExtensionIds;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * The MCP Apps (io.modelcontextprotocol/ui) part of the server: the HTML views that
 * hosts render next to a tool call, and which client may see the app-only tools.
 *
 * See Documentation/Architecture/McpApps.md.
 */
final class McpApps
{
    public const MIME_TYPE = 'text/html;profile=mcp-app';

    /**
     * Shown with every WriteTable call: what changed, with preview, publish and discard.
     */
    public const CHANGE_REVIEW_URI = 'ui://mcp_server/change-review.html';

    private const VIEWS = [
        self::CHANGE_REVIEW_URI => [
            'name' => 'Change review',
            'description' => 'Shows a change written to the TYPO3 workspace, with buttons to preview, publish or discard it.',
            'file' => 'Resources/Private/McpApps/ChangeReview.html',
        ],
    ];

    /**
     * Whether the connected client renders MCP Apps views.
     *
     * Hosts without the extension don't know `_meta.ui.visibility` and would hand
     * app-only tools to the model, so those exist only for clients declaring it.
     */
    public static function isSupportedByClient(Server $server): bool
    {
        $extensions = $server->getSession()?->getClientParams()?->capabilities?->extensions ?? [];
        $settings = $extensions[ExtensionIds::UI] ?? null;
        if ($settings instanceof \stdClass) {
            $settings = (array)$settings;
        }
        $mimeTypes = is_array($settings) ? ($settings['mimeTypes'] ?? []) : [];

        return is_array($mimeTypes) && in_array(self::MIME_TYPE, $mimeTypes, true);
    }

    /**
     * @return array<string, array{name: string, description: string}>
     */
    public static function getViews(): array
    {
        return array_map(
            static fn(array $view): array => ['name' => $view['name'], 'description' => $view['description']],
            self::VIEWS
        );
    }

    /**
     * `_meta` of a tool that is rendered with a view.
     *
     * @param list<'model'|'app'>|null $visibility null for the default, model and app
     * @return array<string, mixed>
     */
    public static function getToolMeta(string $uri, ?array $visibility = null): array
    {
        $ui = ['resourceUri' => $uri];
        if ($visibility !== null) {
            $ui['visibility'] = $visibility;
        }

        // The flat key is deprecated, but hosts that implemented an early draft only read that one.
        return ['ui' => $ui, 'ui/resourceUri' => $uri];
    }

    /**
     * The HTML document of a view, null for unknown URIs.
     */
    public static function getViewHtml(string $uri): ?string
    {
        if (!isset(self::VIEWS[$uri])) {
            return null;
        }
        $html = file_get_contents(ExtensionManagementUtility::extPath('mcp_server') . self::VIEWS[$uri]['file']);

        return $html === false ? null : $html;
    }

    /**
     * Host hints for the views, served as `_meta` of the resources. The views are
     * self-contained, so the host's restrictive default CSP applies unchanged.
     *
     * @return array<string, mixed>
     */
    public static function getViewMeta(): array
    {
        return ['ui' => ['prefersBorder' => true]];
    }
}
