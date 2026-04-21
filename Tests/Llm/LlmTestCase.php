<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm;

use Hn\McpServer\MCP\Tool\ToolInterface;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Tests\Llm\Client\LlmClientInterface;
use Hn\McpServer\Tests\Llm\Client\LlmResponse;
use Hn\McpServer\Tests\Llm\Client\OpenRouterClient;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Base class for LLM-based tests
 *
 * Uses OpenRouter for multi-provider LLM testing.
 * Set OPENROUTER_API_KEY to enable tests.
 *
 * @group llm
 */
abstract class LlmTestCase extends FunctionalTestCase
{
    /**
     * Models available via OpenRouter for multi-model tests.
     * Keys are used as labels in TestDox output and must include model version.
     */
    protected const MODELS = [
        'haiku-4.5' => 'anthropic/claude-haiku-4.5',
        'gpt-5.4' => 'openai/gpt-5.4',
        'gpt-oss-120b' => 'openai/gpt-oss-120b',
        'mistral-large-2512' => 'mistralai/mistral-large-2512',
        'gemini-3-flash' => 'google/gemini-3-flash-preview',
    ];

    protected const MODEL_OPTIONS = [
        'gpt-5.4' => ['reasoning' => ['effort' => 'high']],
    ];

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
    protected array $toolCallHistory = [];

    /** @var string The model to use for LLM calls, set via setModel() or data providers */
    protected string $llmModel = '';

    /** @var string The provider being used */
    protected string $llmProvider = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeLlmClient();

        // Import backend user fixture
        $this->importCSVDataSet(__DIR__ . '/../Functional/Fixtures/be_users.csv');

        // Set up backend user for DataHandler and other tools
        $this->setUpBackendUser(1);

        // Initialize language service
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
    }

    /**
     * Retry flaky LLM tests up to 3 times on assertion failure.
     * LLM responses are inherently non-deterministic, so a single
     * failure does not necessarily indicate a broken test.
     */
    protected function runTest(): mixed
    {
        $maxRetries = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return parent::runTest();
            } catch (\PHPUnit\Framework\SkippedWithMessageException | \PHPUnit\Framework\IncompleteTestError $e) {
                throw $e;
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $lastException = $e;
                if ($attempt < $maxRetries) {
                    try {
                        $this->tearDown();
                    } catch (\Throwable) {
                    }
                    $this->setUp();
                }
            }
        }

        throw $lastException;
    }

    /**
     * Initialize the LLM client based on available API keys.
     */
    protected function initializeLlmClient(): void
    {
        $openRouterKey = getenv('OPENROUTER_API_KEY');

        if (!empty($openRouterKey)) {
            $this->llmClient = new OpenRouterClient($openRouterKey);
            $this->llmProvider = 'openrouter';
            if (empty($this->llmModel)) {
                $this->llmModel = self::MODELS['haiku-4.5'];
            }
            return;
        }

        $this->markTestSkipped('No LLM API key configured. Set OPENROUTER_API_KEY.');
    }

    /**
     * Set the model to use for subsequent LLM calls.
     * Use model keys from MODELS constant or full model IDs.
     */
    protected function setModel(string $model): void
    {
        // Allow using short keys like 'haiku', 'gpt-5.2', etc.
        $this->llmModel = self::MODELS[$model] ?? $model;
    }

    /**
     * Data provider for multi-model tests.
     * Returns all configured models to test against.
     */
    public static function modelProvider(): array
    {
        return array_map(
            fn(string $key) => [$key],
            array_combine(array_keys(static::MODELS), array_keys(static::MODELS))
        );
    }

    /**
     * Convert MCP tool schemas to OpenAI-compatible function format
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
                    'description' => $schema['description'],
                    'parameters' => $schema['inputSchema'],
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

        $defaults = [
            'temperature' => 0,
            'max_tokens' => 4000,
        ];

        // Inject current model if not explicitly overridden
        if (!empty($this->llmModel) && !isset($options['model'])) {
            $defaults['model'] = $this->llmModel;
        }

        $this->lastResponse = $this->llmClient->complete(
            $prompt,
            $tools,
            array_merge($defaults, $this->getModelOptions(), $options)
        );

        return $this->lastResponse;
    }

    protected function getModelOptions(): array
    {
        $modelKey = array_search($this->llmModel, static::MODELS, true);
        if ($modelKey !== false && isset(static::MODEL_OPTIONS[$modelKey])) {
            return static::MODEL_OPTIONS[$modelKey];
        }
        return [];
    }

    /**
     * Build a context string for failure messages, including model and prompt.
     */
    protected function getFailureContext(LlmResponse $response = null): string
    {
        $context = "[Model: {$this->llmModel}]\n";
        $context .= "Prompt: {$this->lastPrompt}\n";
        if ($response !== null) {
            $textResponse = $response->getContent();
            if (!empty($textResponse)) {
                $context .= "LLM text response: " . mb_substr($textResponse, 0, 500) . "\n";
            }
            $context .= "\n" . $this->getToolCallsDebugString($response);
        }
        return $context;
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

                    foreach ($expectedParams as $key => $value) {
                        // For 'data' key, use extractWriteData() to handle models
                        // that place record fields at top level instead of in 'data'
                        if ($key === 'data' && is_array($value)) {
                            $extractedData = $this->extractWriteData($actualParams);
                            foreach ($value as $nestedKey => $nestedValue) {
                                if (is_string($nestedKey)) {
                                    $this->assertArrayHasKey($nestedKey, $extractedData,
                                        "Expected data field '$nestedKey' not found in tool call '$toolName'.\n" .
                                        $this->getFailureContext($response));
                                    $this->assertEquals($nestedValue, $extractedData[$nestedKey],
                                        "Data field '$nestedKey' value mismatch in tool call '$toolName'.\n" .
                                        $this->getFailureContext($response));
                                }
                            }
                            continue;
                        }

                        $this->assertArrayHasKey($key, $actualParams,
                            "Expected parameter '$key' not found in tool call '$toolName'.\n" .
                            $this->getFailureContext($response));

                        if (is_array($value) && is_array($actualParams[$key])) {
                            foreach ($value as $nestedKey => $nestedValue) {
                                if (is_string($nestedKey)) {
                                    $this->assertArrayHasKey($nestedKey, $actualParams[$key],
                                        "Expected nested parameter '$key.$nestedKey' not found in tool call '$toolName'.\n" .
                                        $this->getFailureContext($response));
                                    $this->assertEquals($nestedValue, $actualParams[$key][$nestedKey],
                                        "Nested parameter '$key.$nestedKey' value mismatch in tool call '$toolName'.\n" .
                                        $this->getFailureContext($response));
                                }
                            }
                        } else {
                            $this->assertEquals($value, $actualParams[$key],
                                "Parameter '$key' value mismatch in tool call '$toolName'.\n" .
                                $this->getFailureContext($response));
                        }
                    }
                }

                break;
            }
        }

        if (!$found) {
            $this->fail(
                "Expected tool '$toolName' was not called.\n\n" .
                $this->getFailureContext($response)
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
            $this->assertEquals($expectedSequence, $actualSequence,
                "Tool sequence mismatch (strict).\n" .
                "Expected: " . implode(' → ', $expectedSequence) . "\n" .
                "Actual:   " . implode(' → ', $actualSequence) . "\n" .
                $this->getFailureContext($response));
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
                $this->assertTrue($found,
                    "Expected tool '$expectedTool' not found in sequence or out of order.\n" .
                    "Expected sequence: " . implode(' → ', $expectedSequence) . "\n" .
                    "Actual sequence:   " . implode(' → ', $actualSequence) . "\n" .
                    $this->getFailureContext($response));
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
     * Extract the record data from a WriteTable tool call's arguments.
     * Some models (e.g. OpenAI GPT) place record fields at the top level
     * instead of nesting them inside the 'data' parameter.
     */
    protected function extractWriteData(array $arguments): array
    {
        if (isset($arguments['data']) && is_array($arguments['data'])) {
            return $arguments['data'];
        }

        $knownKeys = ['action', 'table', 'pid', 'uid', 'data', 'position', 'where'];
        return array_diff_key($arguments, array_flip($knownKeys));
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
            "Expected one of:\n" .
            implode("\n", array_map(fn($p) => '  - ' . implode(' → ', $p), $acceptablePatterns)) . "\n\n" .
            "Actual sequence: " . implode(' → ', $actualSequence) . "\n\n" .
            $this->getFailureContext($response)
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

        $defaults = [
            'temperature' => 0,
            'max_tokens' => 4000,
        ];

        // Inject current model if not explicitly overridden
        if (!empty($this->llmModel) && !isset($options['model'])) {
            $defaults['model'] = $this->llmModel;
        }

        $this->lastResponse = $this->llmClient->completeWithHistory(
            $this->lastPrompt,
            $previousResponse,
            $toolResults,
            $this->getMcpToolsAsLlmFunctions(),
            array_merge($defaults, $this->getModelOptions(), $options)
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
    
    /**
     * Execute all tool calls and continue, handling multiple tool calls properly
     * 
     * @param LlmResponse $response
     * @return LlmResponse
     */
    protected function executeAllToolsAndContinue(LlmResponse $response): LlmResponse
    {
        if (!$response->hasToolCalls()) {
            return $response;
        }
        
        return $this->executeAndContinue($response);
    }
    
    /**
     * Execute tool calls until a specific tool is found or max iterations reached
     * 
     * @param LlmResponse $response Initial response
     * @param string $targetToolName Tool name to search for
     * @param int $maxIterations Maximum iterations before giving up
     * @return LlmResponse Response containing the target tool or final response
     */
    protected function executeUntilToolFound(
        LlmResponse $response,
        string $targetToolName,
        int $maxIterations = 5
    ): LlmResponse {
        $currentResponse = $response;
        $this->toolCallHistory = [];

        for ($i = 0; $i < $maxIterations && $currentResponse->hasToolCalls(); $i++) {
            foreach ($currentResponse->getToolCalls() as $toolCall) {
                $this->toolCallHistory[] = $toolCall['name'];
            }

            $targetCalls = $currentResponse->getToolCallsByName($targetToolName);
            if ($targetCalls) {
                // Validate that WriteTable calls include record data.
                // Some models (e.g. GPT) omit the data parameter; execute all
                // tools so the LLM gets the validation error and can retry.
                if ($targetToolName === 'WriteTable') {
                    $args = $targetCalls[0]['arguments'] ?? [];
                    $action = $args['action'] ?? '';
                    $data = $this->extractWriteData($args);
                    $hasPosition = !empty($args['position']) && $args['position'] !== 'bottom';
                    if (in_array($action, ['create', 'update', 'translate']) && empty($data) && !$hasPosition) {
                        $currentResponse = $this->executeAndContinue($currentResponse);
                        continue;
                    }
                }
                return $currentResponse;
            }

            $currentResponse = $this->executeAndContinue($currentResponse);
        }

        return $currentResponse;
    }
    
    /**
     * Get all tool names that were called during exploration
     * 
     * @return array
     */
    protected function getToolCallHistory(): array
    {
        return $this->toolCallHistory ?? [];
    }
    
    /**
     * Assert that a specific tool was called before another tool
     *
     * @param string $firstTool Tool that should be called first
     * @param string $secondTool Tool that should be called after
     * @param string $message Optional failure message
     */
    protected function assertToolWasCalledBefore(string $firstTool, string $secondTool, string $message = ''): void
    {
        $history = $this->getToolCallHistory();
        $firstIndex = array_search($firstTool, $history);
        $secondIndex = array_search($secondTool, $history);

        $historyStr = implode(' → ', $history);
        $contextSuffix = "\nTool history: $historyStr\n" . $this->getFailureContext();

        if ($firstIndex === false) {
            $this->fail(($message ?: "Expected tool '$firstTool' was not called") . $contextSuffix);
        }

        if ($secondIndex === false) {
            $this->fail(($message ?: "Expected tool '$secondTool' was not called") . $contextSuffix);
        }

        $this->assertLessThan($secondIndex, $firstIndex,
            ($message ?: "Expected '$firstTool' to be called before '$secondTool'") . $contextSuffix);
    }

    /**
     * Assert that a specific tool was called during exploration
     *
     * @param string $toolName Tool name to check
     * @param string $message Optional failure message
     */
    protected function assertToolWasCalled(string $toolName, string $message = ''): void
    {
        $history = $this->getToolCallHistory();
        $historyStr = implode(' → ', $history);
        $this->assertContains($toolName, $history,
            ($message ?: "Expected tool '$toolName' to be called during exploration") .
            "\nTool history: $historyStr\n" . $this->getFailureContext());
    }
}