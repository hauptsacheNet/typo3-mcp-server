<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use Hn\McpServer\MCP\Tool\ToolInterface;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Tests\Llm\Client\AnthropicClient;
use Hn\McpServer\Tests\Llm\Client\LlmClientInterface;
use Hn\McpServer\Tests\Llm\Client\LlmResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Base class for LLM-based tests
 * 
 * @group llm
 */
abstract class LlmTestCase extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected ?LlmClientInterface $llmClient = null;
    protected string $lastPrompt = '';
    protected ?LlmResponse $lastResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip test if no API key is configured
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (empty($apiKey)) {
            $this->markTestSkipped('ANTHROPIC_API_KEY environment variable not set');
        }
        
        // Import backend user fixture
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/be_users.csv');
        
        // Set up backend user for DataHandler and other tools
        $this->setUpBackendUser(1);
        
        // Initialize language service
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
        
        // Initialize LLM client
        $this->llmClient = new AnthropicClient($apiKey);
    }

    /**
     * Convert MCP tool schemas to OpenAI/Anthropic function format
     * 
     * @return array
     */
    protected function getMcpToolsAsLlmFunctions(): array
    {
        $toolRegistry = GeneralUtility::makeInstance(ToolRegistry::class);
        $functions = [];
        
        foreach ($toolRegistry->getTools() as $tool) {
            $schema = $tool->getSchema();
            
            $functions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $schema['description'] ?? '',
                    'parameters' => $schema['parameters'] ?? [
                        'type' => 'object',
                        'properties' => [],
                        'required' => []
                    ]
                ]
            ];
        }
        
        return $functions;
    }

    /**
     * Call LLM with a prompt and available tools
     * 
     * @param string $prompt
     * @param array $options Additional options for the LLM call
     * @return LlmResponse
     */
    protected function callLlm(string $prompt, array $options = []): LlmResponse
    {
        $this->lastPrompt = $prompt;
        $tools = $this->getMcpToolsAsLlmFunctions();
        
        $this->lastResponse = $this->llmClient->complete(
            $prompt,
            $tools,
            array_merge([
                'temperature' => 0, // Deterministic responses
                'max_tokens' => 4000,
            ], $options)
        );
        
        return $this->lastResponse;
    }

    /**
     * Assert that a specific tool was called in the response
     * 
     * @param LlmResponse $response
     * @param string $toolName
     * @param array|null $expectedParams Partial params to match (null to skip param checking)
     */
    protected function assertToolCalled(LlmResponse $response, string $toolName, ?array $expectedParams = null): void
    {
        $toolCalls = $response->getToolCalls();
        
        $found = false;
        foreach ($toolCalls as $toolCall) {
            if ($toolCall['name'] === $toolName) {
                $found = true;
                
                if ($expectedParams !== null) {
                    $actualParams = $toolCall['arguments'] ?? [];
                    
                    // Check if expected params are present in actual params
                    foreach ($expectedParams as $key => $value) {
                        $this->assertArrayHasKey($key, $actualParams, "Expected parameter '$key' not found in tool call '$toolName'");
                        
                        // For nested arrays, do partial matching
                        if (is_array($value) && is_array($actualParams[$key])) {
                            foreach ($value as $nestedKey => $nestedValue) {
                                if (is_string($nestedKey)) {
                                    $this->assertArrayHasKey($nestedKey, $actualParams[$key]);
                                    $this->assertEquals($nestedValue, $actualParams[$key][$nestedKey]);
                                }
                            }
                        } else {
                            $this->assertEquals($value, $actualParams[$key], "Parameter '$key' value mismatch in tool call '$toolName'");
                        }
                    }
                }
                
                break;
            }
        }
        
        if (!$found) {
            $this->fail(
                "Expected tool '$toolName' was not called.\n\n" .
                "Prompt: " . $this->lastPrompt . "\n\n" .
                $this->getToolCallsDebugString($response)
            );
        }
    }

    /**
     * Assert that tools were called in a specific order (with flexibility)
     * 
     * @param LlmResponse $response
     * @param array $expectedSequence Array of tool names
     * @param bool $strict If true, no other tools allowed between expected ones
     */
    protected function assertToolSequence(LlmResponse $response, array $expectedSequence, bool $strict = false): void
    {
        $toolCalls = $response->getToolCalls();
        $actualSequence = array_map(fn($call) => $call['name'], $toolCalls);
        
        if ($strict) {
            $this->assertEquals($expectedSequence, $actualSequence);
        } else {
            // Check that expected tools appear in order (but allow other tools between)
            $lastIndex = -1;
            foreach ($expectedSequence as $expectedTool) {
                $found = false;
                for ($i = $lastIndex + 1; $i < count($actualSequence); $i++) {
                    if ($actualSequence[$i] === $expectedTool) {
                        $lastIndex = $i;
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue($found, "Expected tool '$expectedTool' not found in sequence or out of order");
            }
        }
    }

    /**
     * Get a formatted string of actual tool calls for debugging
     * 
     * @param LlmResponse $response
     * @return string
     */
    protected function getToolCallsDebugString(LlmResponse $response): string
    {
        $toolCalls = $response->getToolCalls();
        $debug = "Tool calls made:\n";
        
        foreach ($toolCalls as $i => $call) {
            $debug .= sprintf(
                "%d. %s(%s)\n",
                $i + 1,
                $call['name'],
                json_encode($call['arguments'] ?? [], JSON_PRETTY_PRINT)
            );
        }
        
        return $debug;
    }

    /**
     * Assert that the response follows one of the acceptable patterns
     * 
     * @param LlmResponse $response
     * @param array $acceptablePatterns Array of tool sequences, each can be partial
     * @param string $description Description of what patterns are expected
     */
    protected function assertFollowsPattern(LlmResponse $response, array $acceptablePatterns, string $description = ''): void
    {
        $actualSequence = array_map(fn($call) => $call['name'], $response->getToolCalls());
        
        foreach ($acceptablePatterns as $pattern) {
            if ($this->matchesPattern($actualSequence, $pattern)) {
                return; // Pattern matched!
            }
        }
        
        // No pattern matched
        $this->fail(
            "Response does not follow any acceptable pattern" . ($description ? " ($description)" : "") . ".\n\n" .
            "Expected patterns:\n" . 
            implode("\n", array_map(fn($p) => '  - ' . implode(' → ', $p), $acceptablePatterns)) . "\n\n" .
            "Actual sequence: " . implode(' → ', $actualSequence) . "\n\n" .
            "Prompt: " . $this->lastPrompt . "\n\n" .
            $this->getToolCallsDebugString($response)
        );
    }

    /**
     * Check if actual sequence matches a pattern (pattern can be partial)
     */
    private function matchesPattern(array $actualSequence, array $pattern): bool
    {
        $patternIndex = 0;
        
        foreach ($actualSequence as $tool) {
            if ($patternIndex < count($pattern) && $tool === $pattern[$patternIndex]) {
                $patternIndex++;
            }
        }
        
        return $patternIndex === count($pattern);
    }

    /**
     * Execute a tool call using the real MCP tool
     * 
     * @param array $toolCall Tool call from LLM response
     * @return array Tool result with 'content' and optionally 'error' keys
     */
    protected function executeToolCall(array $toolCall): array
    {
        $toolRegistry = GeneralUtility::makeInstance(ToolRegistry::class);
        $tool = $toolRegistry->getTool($toolCall['name']);
        
        if (!$tool) {
            return [
                'error' => "Tool '{$toolCall['name']}' not found",
                'content' => "Error: Tool not found"
            ];
        }
        
        try {
            $result = $tool->execute($toolCall['arguments'] ?? []);
            
            // Convert CallToolResult to simple array
            $content = '';
            foreach ($result->content as $contentItem) {
                if ($contentItem instanceof \Mcp\Types\TextContent) {
                    $content .= $contentItem->text;
                } else {
                    $content .= json_encode($contentItem);
                }
            }
            
            // Check if content indicates an error even if isError is false
            $hasErrorContent = str_starts_with($content, 'Error:') || 
                              str_contains($content, 'authentication') ||
                              str_contains($content, 'not properly initialized');
            
            return [
                'content' => $content,
                'isError' => $result->isError || $hasErrorContent
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'content' => "Error: " . $e->getMessage()
            ];
        }
    }

    /**
     * Continue conversation with tool results
     * 
     * @param LlmResponse $previousResponse Previous LLM response
     * @param array $toolResults Array of tool results (from executeToolCall)
     * @param array $options Additional options for the LLM call
     * @return LlmResponse
     */
    protected function continueWithToolResult(
        LlmResponse $previousResponse, 
        array $toolResults, 
        array $options = []
    ): LlmResponse {
        // Wrap single result in array if needed
        if (isset($toolResults['content'])) {
            $toolResults = [$toolResults];
        }
        
        $this->lastResponse = $this->llmClient->completeWithHistory(
            $this->lastPrompt,
            $previousResponse,
            $toolResults,
            $this->getMcpToolsAsLlmFunctions(),
            array_merge([
                'temperature' => 0,
                'max_tokens' => 4000,
            ], $options)
        );
        
        return $this->lastResponse;
    }

    /**
     * Execute all tool calls from a response and continue the conversation
     * 
     * @param LlmResponse $response Response containing tool calls
     * @param array $options Additional options for the LLM call
     * @return LlmResponse
     */
    protected function executeAndContinue(LlmResponse $response, array $options = []): LlmResponse
    {
        $toolResults = [];
        
        foreach ($response->getToolCalls() as $toolCall) {
            $toolResults[] = $this->executeToolCall($toolCall);
        }
        
        return $this->continueWithToolResult($response, $toolResults, $options);
    }
}