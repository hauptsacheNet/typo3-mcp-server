<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Hn\McpServer\Traits\ExceptionHandlerTrait;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * Abstract base class for MCP tools
 * 
 * Implements the Template Method pattern for consistent error handling
 * across all tools. The execute() method is final and handles all
 * exceptions, while subclasses implement doExecute() for their logic.
 */
abstract class AbstractTool implements ToolInterface
{
    use ExceptionHandlerTrait;
    
    /**
     * Get the tool name based on the class name
     */
    public function getName(): string
    {
        $className = (new \ReflectionClass($this))->getShortName();
        return str_replace('Tool', '', $className);
    }

    /**
     * Get annotations for this tool from the schema
     *
     * Annotations provide metadata hints about the tool's behavior:
     * - readOnlyHint: Whether this tool only reads data
     * - idempotentHint: Whether repeated calls produce the same result
     * - allowedCallers: Which execution contexts can invoke this tool
     * - inputExamples: Example input parameters for the tool
     */
    public function getAnnotations(): array
    {
        $schema = $this->getSchema();
        return $schema['annotations'] ?? [];
    }
    
    /**
     * Execute the tool with the given parameters
     * 
     * This method is final to ensure consistent error handling across all tools.
     * Subclasses should implement doExecute() for their specific logic.
     *
     * @param array $params
     * @return CallToolResult
     */
    final public function execute(array $params): CallToolResult
    {
        try {
            // Initialize any necessary context (overridden in subclasses)
            $this->initialize();
            
            // Execute the actual tool logic
            return $this->doExecute($params);
        } catch (\Throwable $e) {
            // Use the trait's exception handler for consistent logging and messaging
            return $this->handleException($e, $this->getName());
        }
    }
    
    /**
     * Initialize any necessary context before execution
     * 
     * Override this method in subclasses to perform initialization
     * such as workspace context setup.
     */
    protected function initialize(): void
    {
        // Default implementation does nothing
        // Subclasses can override to add initialization logic
    }
    
    /**
     * Execute the tool logic
     * 
     * This method must be implemented by subclasses to provide
     * the actual tool functionality. Any exceptions thrown will
     * be handled by the execute() method.
     *
     * @param array $params
     * @return CallToolResult
     * @throws \Exception Any exception thrown will be handled by execute()
     */
    abstract protected function doExecute(array $params): CallToolResult;
    
    /**
     * Create an error result (required by ExceptionHandlerTrait)
     */
    protected function createErrorResult(string $message): CallToolResult
    {
        return new CallToolResult([new TextContent($message)], true);
    }
}
