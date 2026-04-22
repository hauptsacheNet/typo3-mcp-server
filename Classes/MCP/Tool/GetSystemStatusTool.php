<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Registry\StatusRegistry;
use TYPO3\CMS\Reports\RequestAwareStatusProviderInterface;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

/**
 * Tool for reading TYPO3 system status reports collected via the Reports
 * extension's StatusRegistry. Only registered when EXT:reports is installed.
 */
class GetSystemStatusTool extends AbstractTool
{
    public function __construct(private readonly StatusRegistry $statusRegistry)
    {
    }

    public function getSchema(): array
    {
        return [
            'description' => 'Get the TYPO3 system status: PHP and TYPO3 version, database schema state, security checks, '
                . 'scheduler warnings and anything else contributed by installed extensions via the Reports API. '
                . 'Useful for answering "is there anything wrong with the system?" or "what version of X is running?". '
                . 'Requires an admin backend user.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'minSeverity' => [
                        'type' => 'string',
                        'enum' => ['NOTICE', 'INFO', 'OK', 'WARNING', 'ERROR'],
                        'description' => 'Only return statuses at or above this severity. '
                            . 'Use WARNING to triage issues. Default: NOTICE (include everything).',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Optional: limit to a single category label (e.g. "System", "Security", '
                            . '"Configuration"). Matched case-insensitively.',
                    ],
                ],
                'required' => [],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser || !$backendUser->isAdmin()) {
            return $this->createErrorResult('GetSystemStatus requires an admin backend user.');
        }

        $minSeverity = $this->parseSeverity($params['minSeverity'] ?? null);
        $categoryFilter = isset($params['category']) ? strtolower(trim((string)$params['category'])) : null;

        $grouped = [];
        $counts = ['NOTICE' => 0, 'INFO' => 0, 'OK' => 0, 'WARNING' => 0, 'ERROR' => 0];

        foreach ($this->statusRegistry->getProviders() as $provider) {
            $category = $provider->getLabel();
            if ($categoryFilter !== null && strtolower($category) !== $categoryFilter) {
                continue;
            }

            foreach ($this->collectStatuses($provider) as $status) {
                if (!$status instanceof Status) {
                    continue;
                }
                $severity = $status->getSeverity();
                if ($minSeverity !== null && $severity->value < $minSeverity->value) {
                    continue;
                }
                $counts[$severity->name]++;
                $grouped[$category][] = $status;
            }
        }

        return new CallToolResult([new TextContent($this->formatReport($grouped, $counts, $minSeverity, $categoryFilter))]);
    }

    /**
     * @return iterable<Status>
     */
    private function collectStatuses(StatusProviderInterface $provider): iterable
    {
        if ($provider instanceof RequestAwareStatusProviderInterface) {
            return $provider->getStatus($GLOBALS['TYPO3_REQUEST'] ?? null);
        }
        return $provider->getStatus();
    }

    private function parseSeverity(?string $name): ?ContextualFeedbackSeverity
    {
        if ($name === null || $name === '') {
            return null;
        }
        foreach (ContextualFeedbackSeverity::cases() as $case) {
            if ($case->name === strtoupper($name)) {
                return $case;
            }
        }
        throw new \InvalidArgumentException('Unknown severity: ' . $name);
    }

    /**
     * @param array<string, Status[]> $grouped
     * @param array<string, int> $counts
     */
    private function formatReport(array $grouped, array $counts, ?ContextualFeedbackSeverity $minSeverity, ?string $categoryFilter): string
    {
        $out = "TYPO3 SYSTEM STATUS\n";
        $out .= "===================\n\n";

        $filters = [];
        if ($minSeverity !== null) {
            $filters[] = 'minSeverity=' . $minSeverity->name;
        }
        if ($categoryFilter !== null) {
            $filters[] = 'category=' . $categoryFilter;
        }
        if ($filters !== []) {
            $out .= 'Filters: ' . implode(', ', $filters) . "\n\n";
        }

        $summary = [];
        foreach (['ERROR', 'WARNING', 'OK', 'INFO', 'NOTICE'] as $name) {
            if ($counts[$name] > 0) {
                $summary[] = $counts[$name] . ' ' . $name;
            }
        }
        $out .= 'Summary: ' . ($summary === [] ? 'no entries' : implode(', ', $summary)) . "\n\n";

        if ($grouped === []) {
            $out .= "No status entries match the given filters.\n";
            return $out;
        }

        $severityOrder = [
            ContextualFeedbackSeverity::ERROR->value => 0,
            ContextualFeedbackSeverity::WARNING->value => 1,
            ContextualFeedbackSeverity::OK->value => 2,
            ContextualFeedbackSeverity::INFO->value => 3,
            ContextualFeedbackSeverity::NOTICE->value => 4,
        ];

        ksort($grouped);
        foreach ($grouped as $category => $statuses) {
            usort($statuses, static fn(Status $a, Status $b) =>
                $severityOrder[$a->getSeverity()->value] <=> $severityOrder[$b->getSeverity()->value]
                ?: strcmp($a->getTitle(), $b->getTitle())
            );

            $out .= $category . ":\n";
            foreach ($statuses as $status) {
                $out .= sprintf(
                    "- [%s] %s: %s\n",
                    $status->getSeverity()->name,
                    $status->getTitle(),
                    $this->plainText($status->getValue())
                );
                $message = $this->plainText($status->getMessage());
                if ($message !== '') {
                    foreach (explode("\n", wordwrap($message, 100, "\n", true)) as $line) {
                        $out .= '    ' . $line . "\n";
                    }
                }
            }
            $out .= "\n";
        }

        return $out;
    }

    private function plainText(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', strip_tags($value)) ?? '';
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
