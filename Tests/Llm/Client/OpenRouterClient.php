<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm\Client;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * OpenRouter API client implementation
 *
 * Uses the OpenAI-compatible API provided by OpenRouter to access
 * multiple LLM providers (Anthropic, OpenAI, Mistral, Moonshot, etc.)
 */
class OpenRouterClient implements LlmClientInterface
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    private string $apiKey;
    private RequestFactory $requestFactory;

    /** @var array Full conversation history for multi-turn support */
    private array $conversationHistory = [];

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
    }

    public function complete(string $prompt, array $tools, array $options = []): LlmResponse
    {
        // Reset conversation history for new conversation
        $this->conversationHistory = [];

        $model = $options['model'] ?? 'anthropic/claude-3-5-haiku';
        $temperature = $options['temperature'] ?? 0;
        $maxTokens = $options['max_tokens'] ?? 4000;

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a TYPO3 CMS content management assistant with access to MCP tools. '
                    . 'Execute tasks directly using the available tools — do not ask for confirmation or present options. '
                    . 'When asked to create, update, or modify content, do it immediately.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $this->conversationHistory = $messages;

        $requestBody = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => $messages,
            'tools' => $this->convertToolsToOpenAIFormat($tools),
        ];

        if (isset($options['reasoning'])) {
            $requestBody['reasoning'] = $options['reasoning'];
        }

        return $this->sendRequest($requestBody);
    }

    public function completeWithHistory(
        string $initialPrompt,
        LlmResponse $previousResponse,
        array $toolResults,
        array $tools,
        array $options = []
    ): LlmResponse {
        $model = $options['model'] ?? 'anthropic/claude-3-5-haiku';
        $temperature = $options['temperature'] ?? 0;
        $maxTokens = $options['max_tokens'] ?? 4000;

        // Build full conversation from history
        if (empty($this->conversationHistory)) {
            $this->conversationHistory = [
                [
                    'role' => 'user',
                    'content' => $initialPrompt,
                ],
            ];
        }

        // Add the assistant's response (with tool calls)
        $assistantMessage = $this->buildAssistantMessage($previousResponse);
        $this->conversationHistory[] = $assistantMessage;

        // Add tool results
        $toolCalls = $previousResponse->getToolCalls();
        $rawResponse = $previousResponse->getRawResponse();
        $rawToolCalls = $rawResponse['choices'][0]['message']['tool_calls'] ?? [];

        foreach ($toolResults as $index => $result) {
            $toolCallId = $rawToolCalls[$index]['id'] ?? ('call_' . $index);

            $this->conversationHistory[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => $result['content'] ?? json_encode($result),
            ];
        }

        $requestBody = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => $this->conversationHistory,
            'tools' => $this->convertToolsToOpenAIFormat($tools),
        ];

        if (isset($options['reasoning'])) {
            $requestBody['reasoning'] = $options['reasoning'];
        }

        return $this->sendRequest($requestBody);
    }

    /**
     * Send a request to OpenRouter API with retry on transient failures
     */
    private function sendRequest(array $requestBody): LlmResponse
    {
        $maxRetries = 3;
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 2s, 4s, 8s
                sleep((int)pow(2, $attempt));
            }

            try {
                $response = $this->requestFactory->request(
                    self::API_URL,
                    'POST',
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->apiKey,
                            'Content-Type' => 'application/json',
                            'HTTP-Referer' => 'https://github.com/hauptsacheNet/typo3-mcp-server',
                            'X-Title' => 'TYPO3 MCP Server LLM Tests',
                        ],
                        'body' => json_encode($requestBody),
                    ]
                );

                $statusCode = $response->getStatusCode();

                if ($statusCode === 200) {
                    $responseData = json_decode($response->getBody()->getContents(), true);
                    return $this->parseResponse($responseData);
                }

                $errorBody = $response->getBody()->getContents();

                // Retry on server errors (5xx) and rate limits (429)
                if ($statusCode >= 500 || $statusCode === 429) {
                    $lastException = new \RuntimeException(
                        'OpenRouter API error: ' . $statusCode . ' - ' . $errorBody
                    );
                    continue;
                }

                // Client errors (4xx except 429) are not retryable
                throw new \RuntimeException(
                    'OpenRouter API error: ' . $statusCode . ' - ' . $errorBody .
                    "\n\nRequest body:\n" . json_encode($requestBody, JSON_PRETTY_PRINT)
                );
            } catch (\GuzzleHttp\Exception\ServerException $e) {
                $lastException = new \RuntimeException(
                    'OpenRouter API server error: ' . $e->getMessage(),
                    0,
                    $e
                );
                continue;
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                // Retry on 429 Too Many Requests (rate limiting)
                if ($e->getResponse() && $e->getResponse()->getStatusCode() === 429) {
                    $lastException = new \RuntimeException(
                        'OpenRouter API rate limited: ' . $e->getMessage(),
                        0,
                        $e
                    );
                    continue;
                }
                throw new \RuntimeException(
                    'OpenRouter API client error: ' . $e->getMessage(),
                    0,
                    $e
                );
            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                $lastException = new \RuntimeException(
                    'OpenRouter API connection error: ' . $e->getMessage(),
                    0,
                    $e
                );
                continue;
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Exception $e) {
                throw new \RuntimeException(
                    'Failed to call OpenRouter API: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        throw $lastException ?? new \RuntimeException('OpenRouter API request failed after retries');
    }

    /**
     * Convert tools to OpenAI function calling format
     *
     * Tools are already in OpenAI format from getMcpToolsAsLlmFunctions(),
     * so this is essentially a pass-through with validation.
     */
    private function convertToolsToOpenAIFormat(array $tools): array
    {
        $openAITools = [];

        foreach ($tools as $tool) {
            if ($tool['type'] === 'function') {
                $openAITools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['function']['name'],
                        'description' => $tool['function']['description'] ?? '',
                        'parameters' => $tool['function']['parameters'] ?? [
                            'type' => 'object',
                            'properties' => new \stdClass(),
                        ],
                    ],
                ];
            }
        }

        return $openAITools;
    }

    /**
     * Parse OpenAI-format response into LlmResponse
     *
     * Surfaces two failure modes that previously masqueraded as "the model
     * just didn't call the tool right":
     *   - finish_reason=length: response truncated by max_tokens. For
     *     reasoning models (gpt-5*, o-series), reasoning tokens count
     *     toward max_tokens on most providers, so the model can run out
     *     of budget mid-tool-call.
     *   - tool_calls with malformed JSON arguments: previously silently
     *     coerced to []; now raises so the test sees a clear cause.
     */
    private function parseResponse(array $responseData): LlmResponse
    {
        $choice = $responseData['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $finishReason = $choice['finish_reason'] ?? null;

        if ($finishReason === 'length') {
            $usage = $responseData['usage'] ?? [];
            throw new \RuntimeException(
                'OpenRouter response truncated by max_tokens (finish_reason=length). '
                . 'Reasoning tokens count toward max_tokens on most providers, '
                . 'so the model likely never finished its tool call. '
                . 'Usage: ' . json_encode($usage)
            );
        }

        $content = $message['content'] ?? '';
        $toolCalls = [];

        foreach ($message['tool_calls'] ?? [] as $idx => $toolCall) {
            if (($toolCall['type'] ?? '') === 'function') {
                $name = $toolCall['function']['name'] ?? '';
                $arguments = $toolCall['function']['arguments'] ?? '{}';

                if (is_string($arguments)) {
                    $trimmed = trim($arguments);
                    if ($trimmed === '' || $trimmed === 'null') {
                        $arguments = [];
                    } else {
                        $decoded = json_decode($arguments, true);
                        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                            throw new \RuntimeException(
                                'OpenRouter returned tool call #' . $idx . ' (' . $name . ') '
                                . 'with malformed JSON arguments (' . json_last_error_msg() . '). '
                                . 'This usually means the response was truncated mid-arguments. '
                                . 'Raw args (first 500 chars): '
                                . mb_substr($arguments, 0, 500)
                            );
                        }
                        $arguments = is_array($decoded) ? $decoded : [];
                    }
                }

                $toolCalls[] = [
                    'name' => $name,
                    'arguments' => $arguments,
                ];
            }
        }

        return new LlmResponse($content, $toolCalls, $responseData);
    }

    /**
     * Build assistant message from previous response for conversation history
     */
    private function buildAssistantMessage(LlmResponse $previousResponse): array
    {
        $rawResponse = $previousResponse->getRawResponse();
        $message = $rawResponse['choices'][0]['message'] ?? [];

        $assistantMessage = [
            'role' => 'assistant',
        ];

        if (!empty($message['content'])) {
            $assistantMessage['content'] = $message['content'];
        }

        if (!empty($message['tool_calls'])) {
            $assistantMessage['tool_calls'] = $message['tool_calls'];
        }

        return $assistantMessage;
    }
}
