<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm\Client;

use Symfony\AI\Platform\Bridge\Anthropic\PlatformFactory as AnthropicPlatformFactory;
use Symfony\AI\Platform\Bridge\Mistral\PlatformFactory as MistralPlatformFactory;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Symfony AI Platform client implementation
 * Supports multiple providers (Anthropic, Mistral) through a unified interface
 */
class SymfonyAiClient implements LlmClientInterface
{
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_MISTRAL = 'mistral';

    private PlatformInterface $platform;
    private string $provider;

    public function __construct(string $apiKey, string $provider = self::PROVIDER_ANTHROPIC)
    {
        $this->provider = $provider;
        $this->platform = match ($provider) {
            self::PROVIDER_ANTHROPIC => AnthropicPlatformFactory::create($apiKey),
            self::PROVIDER_MISTRAL => MistralPlatformFactory::create($apiKey),
            default => throw new \InvalidArgumentException("Unknown provider: {$provider}")
        };
    }

    /**
     * Complete a prompt with available tools
     */
    public function complete(string $prompt, array $tools, array $options = []): LlmResponse
    {
        $model = $options['model'] ?? $this->getDefaultModel();
        $temperature = $options['temperature'] ?? 0;
        $maxTokens = $options['max_tokens'] ?? 4000;

        $messages = new MessageBag(
            Message::ofUser($prompt)
        );

        $platformOptions = [
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        // Convert tools to Symfony AI format
        $symfonyTools = $this->convertToolsToSymfonyFormat($tools);
        if (!empty($symfonyTools)) {
            $platformOptions['tools'] = $symfonyTools;
        }

        try {
            $deferredResult = $this->platform->invoke($model, $messages, $platformOptions);
            return $this->parseResponse($deferredResult);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to call %s API: %s',
                    ucfirst($this->provider),
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
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
        $model = $options['model'] ?? $this->getDefaultModel();
        $temperature = $options['temperature'] ?? 0;
        $maxTokens = $options['max_tokens'] ?? 4000;

        // Build message history
        $messages = new MessageBag(
            Message::ofUser($initialPrompt)
        );

        // Add assistant message with tool calls
        $rawResponse = $previousResponse->getRawResponse();
        $toolCalls = $rawResponse['toolCalls'] ?? [];

        if (!empty($toolCalls)) {
            $messages->add(Message::ofAssistant(
                $previousResponse->getContent() ?: null,
                $toolCalls
            ));

            // Add tool results
            foreach ($toolResults as $index => $result) {
                if (isset($toolCalls[$index])) {
                    $messages->add(Message::ofToolCall(
                        $toolCalls[$index],
                        $result['content'] ?? json_encode($result)
                    ));
                }
            }
        }

        $platformOptions = [
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        // Convert tools to Symfony AI format
        $symfonyTools = $this->convertToolsToSymfonyFormat($tools);
        if (!empty($symfonyTools)) {
            $platformOptions['tools'] = $symfonyTools;
        }

        try {
            $deferredResult = $this->platform->invoke($model, $messages, $platformOptions);
            return $this->parseResponse($deferredResult);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to call %s API: %s',
                    ucfirst($this->provider),
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
    }

    /**
     * Get the default model for the configured provider
     */
    private function getDefaultModel(): string
    {
        return match ($this->provider) {
            self::PROVIDER_ANTHROPIC => 'claude-3-5-haiku-latest',
            self::PROVIDER_MISTRAL => 'mistral-large-latest',
            default => throw new \RuntimeException("Unknown provider: {$this->provider}")
        };
    }

    /**
     * Convert OpenAI-style tools to Symfony AI format
     */
    private function convertToolsToSymfonyFormat(array $tools): array
    {
        $symfonyTools = [];

        foreach ($tools as $tool) {
            if ($tool['type'] === 'function') {
                $function = $tool['function'];

                // Create a dummy execution reference (tools are executed externally)
                $reference = new ExecutionReference(\stdClass::class, 'dummy');

                $symfonyTools[] = new Tool(
                    $reference,
                    $function['name'],
                    $function['description'] ?? '',
                    $function['parameters'] ?? null
                );
            }
        }

        return $symfonyTools;
    }

    /**
     * Parse Symfony AI Platform response into LlmResponse
     */
    private function parseResponse($deferredResult): LlmResponse
    {
        $content = '';
        $toolCalls = [];
        $rawToolCalls = [];

        try {
            $result = $deferredResult->getResult();

            if ($result instanceof TextResult) {
                $content = $result->getContent();
            } elseif ($result instanceof ToolCallResult) {
                foreach ($result->getContent() as $toolCall) {
                    $toolCalls[] = [
                        'name' => $toolCall->getName(),
                        'arguments' => $toolCall->getArguments()
                    ];
                    $rawToolCalls[] = $toolCall;
                }
            }
        } catch (\Symfony\AI\Platform\Exception\UnexpectedResultTypeException $e) {
            // Try to get tool calls if text was expected but tool calls were returned
            try {
                $toolCallsResult = $deferredResult->asToolCalls();
                foreach ($toolCallsResult as $toolCall) {
                    $toolCalls[] = [
                        'name' => $toolCall->getName(),
                        'arguments' => $toolCall->getArguments()
                    ];
                    $rawToolCalls[] = $toolCall;
                }
            } catch (\Exception $inner) {
                // If neither text nor tool calls, re-throw original
                throw $e;
            }
        }

        // Store raw tool calls for history building
        $rawResponse = [
            'content' => $content,
            'toolCalls' => $rawToolCalls,
            'provider' => $this->provider,
        ];

        return new LlmResponse($content, $toolCalls, $rawResponse);
    }

    /**
     * Get the provider name
     */
    public function getProvider(): string
    {
        return $this->provider;
    }
}
