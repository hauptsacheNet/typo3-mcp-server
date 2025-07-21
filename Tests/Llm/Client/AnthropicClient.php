<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm\Client;

use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Anthropic API client implementation
 */
class AnthropicClient implements LlmClientInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    
    private string $apiKey;
    private RequestFactory $requestFactory;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
    }

    /**
     * Complete a prompt with available tools
     */
    public function complete(string $prompt, array $tools, array $options = []): LlmResponse
    {
        $model = $options['model'] ?? 'claude-3-5-haiku-latest';
        $temperature = $options['temperature'] ?? 0;
        $maxTokens = $options['max_tokens'] ?? 4000;

        $requestBody = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'tools' => $this->convertToolsToAnthropicFormat($tools)
        ];

        try {
            $response = $this->requestFactory->request(
                self::API_URL,
                'POST',
                [
                    'headers' => [
                        'x-api-key' => $this->apiKey,
                        'anthropic-version' => self::API_VERSION,
                        'content-type' => 'application/json'
                    ],
                    'body' => json_encode($requestBody)
                ]
            );

            if ($response->getStatusCode() !== 200) {
                $errorBody = $response->getBody()->getContents();
                throw new \RuntimeException(
                    'Anthropic API error: ' . $response->getStatusCode() . ' - ' . $errorBody . "\n\nRequest body:\n" . json_encode($requestBody, JSON_PRETTY_PRINT)
                );
            }

            $responseData = json_decode($response->getBody()->getContents(), true);
            return $this->parseResponse($responseData);

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            throw new \RuntimeException(
                'Failed to call Anthropic API: ' . $e->getMessage() . "\n\nError body:\n" . $errorBody . "\n\nRequest body:\n" . json_encode($requestBody, JSON_PRETTY_PRINT), 
                0, 
                $e
            );
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to call Anthropic API: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Convert OpenAI-style tools to Anthropic format
     */
    private function convertToolsToAnthropicFormat(array $tools): array
    {
        $anthropicTools = [];
        
        foreach ($tools as $tool) {
            if ($tool['type'] === 'function') {
                $function = $tool['function'];
                $anthropicTools[] = [
                    'name' => $function['name'],
                    'description' => $function['description'] ?? '',
                    'input_schema' => $function['parameters'] ?? [
                        'type' => 'object',
                        'properties' => []
                    ]
                ];
            }
        }
        
        return $anthropicTools;
    }

    /**
     * Parse Anthropic API response into LlmResponse
     */
    private function parseResponse(array $responseData): LlmResponse
    {
        $content = '';
        $toolCalls = [];

        foreach ($responseData['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'name' => $block['name'],
                    'arguments' => $block['input'] ?? []
                ];
            }
        }

        return new LlmResponse($content, $toolCalls, $responseData);
    }

    /**
     * Continue a conversation with tool results
     */
    public function completeWithHistory(
        string $initialPrompt,
        LlmResponse $previousResponse,
        array $toolResults,
        array $tools,
        array $options = []
    ): LlmResponse {
        $model = $options['model'] ?? 'claude-3-5-haiku-latest';
        $temperature = $options['temperature'] ?? 0;
        $maxTokens = $options['max_tokens'] ?? 4000;

        // Build message history
        $messages = [
            // Original user prompt
            [
                'role' => 'user',
                'content' => $initialPrompt
            ],
            // Assistant's response with tool calls
            [
                'role' => 'assistant',
                'content' => $this->buildAssistantMessage($previousResponse)
            ]
        ];

        // Add tool results as user messages
        foreach ($toolResults as $index => $result) {
            $messages[] = [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => $this->getToolUseId($previousResponse, $index),
                        'content' => $result['content'] ?? json_encode($result)
                    ]
                ]
            ];
        }

        $requestBody = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => $messages,
            'tools' => $this->convertToolsToAnthropicFormat($tools)
        ];

        try {
            $response = $this->requestFactory->request(
                self::API_URL,
                'POST',
                [
                    'headers' => [
                        'x-api-key' => $this->apiKey,
                        'anthropic-version' => self::API_VERSION,
                        'content-type' => 'application/json'
                    ],
                    'body' => json_encode($requestBody)
                ]
            );

            if ($response->getStatusCode() !== 200) {
                $errorBody = $response->getBody()->getContents();
                throw new \RuntimeException(
                    'Anthropic API error: ' . $response->getStatusCode() . ' - ' . $errorBody . "\n\nRequest body:\n" . json_encode($requestBody, JSON_PRETTY_PRINT)
                );
            }

            $responseData = json_decode($response->getBody()->getContents(), true);
            return $this->parseResponse($responseData);

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            throw new \RuntimeException(
                'Failed to call Anthropic API: ' . $e->getMessage() . "\n\nError body:\n" . $errorBody . "\n\nRequest body:\n" . json_encode($requestBody, JSON_PRETTY_PRINT), 
                0, 
                $e
            );
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to call Anthropic API: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build assistant message content from previous response
     */
    private function buildAssistantMessage(LlmResponse $previousResponse): array
    {
        $content = [];
        
        // Add text content if any
        if ($previousResponse->getContent()) {
            $content[] = [
                'type' => 'text',
                'text' => $previousResponse->getContent()
            ];
        }
        
        // Add tool uses from raw response
        $rawResponse = $previousResponse->getRawResponse();
        foreach ($rawResponse['content'] ?? [] as $block) {
            if ($block['type'] === 'tool_use') {
                $content[] = $block;
            }
        }
        
        return $content;
    }

    /**
     * Get tool use ID from previous response
     */
    private function getToolUseId(LlmResponse $previousResponse, int $index): string
    {
        $rawResponse = $previousResponse->getRawResponse();
        $toolUseIndex = 0;
        
        foreach ($rawResponse['content'] ?? [] as $block) {
            if ($block['type'] === 'tool_use') {
                if ($toolUseIndex === $index) {
                    return $block['id'] ?? 'tool_' . $index;
                }
                $toolUseIndex++;
            }
        }
        
        return 'tool_' . $index;
    }
}