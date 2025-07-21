<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Llm\Client;

/**
 * Represents an LLM response with tool calls
 */
class LlmResponse
{
    private string $content;
    private array $toolCalls;
    private array $rawResponse;

    public function __construct(string $content, array $toolCalls, array $rawResponse)
    {
        $this->content = $content;
        $this->toolCalls = $toolCalls;
        $this->rawResponse = $rawResponse;
    }

    /**
     * Get the text content of the response
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get tool calls made by the LLM
     * 
     * @return array Array of tool calls with 'name' and 'arguments' keys
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * Get the raw API response for debugging
     */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    /**
     * Check if any tool calls were made
     */
    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    /**
     * Get tool calls by name
     * 
     * @param string $toolName
     * @return array Array of matching tool calls
     */
    public function getToolCallsByName(string $toolName): array
    {
        return array_filter($this->toolCalls, fn($call) => $call['name'] === $toolName);
    }
}