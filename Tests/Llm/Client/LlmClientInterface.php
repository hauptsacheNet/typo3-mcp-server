<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm\Client;

/**
 * Interface for LLM clients
 * Allows easy switching between providers (Anthropic, OpenRouter, etc.)
 */
interface LlmClientInterface
{
    /**
     * Complete a prompt with available tools
     * 
     * @param string $prompt The user prompt
     * @param array $tools Available tools in OpenAI function format
     * @param array $options Additional options (temperature, max_tokens, etc.)
     * @return LlmResponse
     */
    public function complete(string $prompt, array $tools, array $options = []): LlmResponse;

    /**
     * Continue a conversation with tool results
     * 
     * @param string $initialPrompt The original user prompt
     * @param LlmResponse $previousResponse The previous LLM response containing tool calls
     * @param array $toolResults Array of tool execution results
     * @param array $tools Available tools in OpenAI function format
     * @param array $options Additional options
     * @return LlmResponse
     */
    public function completeWithHistory(
        string $initialPrompt,
        LlmResponse $previousResponse,
        array $toolResults,
        array $tools,
        array $options = []
    ): LlmResponse;
}