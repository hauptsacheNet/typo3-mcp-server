# LLM Tests for MCP Tools

This directory contains tests that use actual Language Models to verify that the MCP tools are intuitive and work as expected when used by LLMs.

## Purpose

These tests help ensure that:
1. Tool schemas are clear and understandable to LLMs
2. Tool descriptions alone are sufficient for correct tool usage (no hints needed)
3. LLMs can successfully complete common tasks using the tools
4. Tool behavior matches realistic user expectations
5. The tools work together effectively for complex workflows

## Running the Tests

### Prerequisites

1. Set the `ANTHROPIC_API_KEY` environment variable:
   ```bash
   export ANTHROPIC_API_KEY="your-api-key-here"
   ```
   or create a `.env.local`
   ```bash
   ANTHROPIC_API_KEY="your-api-key-here"
   ```

2. Run the LLM tests:
   ```bash
   composer test:llm
   ```

### Cost Considerations

These tests make actual API calls and will incur costs:
- Each test typically makes 2-5 API calls (due to multi-step conversations)
- Using Claude 3.5 Haiku (default) is cost-effective
- Tests are excluded from the default test suite to avoid unexpected charges
- Consider using environment-specific keys to track costs

### Test Configuration

The tests use:
- **Temperature**: 0 (for deterministic responses)
- **Model**: `claude-3-5-haiku-latest` (configurable)
- **Max Tokens**: 4000 per call

## Writing New Tests

### Basic Principles

1. **Extend `LlmTestCase`** for access to helper methods and proper setup
2. **Write realistic prompts** - avoid hints, IDs, or implementation details
3. **Expect exploration** - LLMs typically check context before acting
4. **Test real tool execution** - use multi-step conversations
5. **Assert tool failures properly** - WriteTable errors should fail the test

### Key Methods

- `callLlm($prompt)` - Send initial prompt with all MCP tools available
- `assertToolCalled($response, $toolName, $expectedParams)` - Verify tool usage
- `executeToolCall($toolCall)` - Execute a tool and get results
- `continueWithToolResult($previousResponse, $toolResult)` - Continue conversation
- `assertFollowsPattern($response, $patterns)` - Assert flexible tool sequences

### Example: Simple Page Creation

```php
public function testLlmCreatesPage(): void
{
    // Step 1: Send realistic prompt (no IDs or hints!)
    $prompt = "Create a new page called 'Services' under the home page";
    $response1 = $this->callLlm($prompt);
    
    // Step 2: Expect exploration first
    $this->assertToolCalled($response1, 'GetPageTree');
    
    // Step 3: Execute the tool call
    $treeResult = $this->executeToolCall($response1->getToolCalls()[0]);
    $this->assertFalse($treeResult['isError'] ?? false);
    
    // Step 4: Continue conversation with tree result
    $response2 = $this->continueWithToolResult($response1, $treeResult);
    
    // Step 5: Now expect page creation
    $this->assertToolCalled($response2, 'WriteTable', [
        'action' => 'create',
        'table' => 'pages',
        'data' => [
            'title' => 'Services'
        ]
    ]);
    
    // Step 6: Execute write and ensure it succeeds
    $writeResult = $this->executeToolCall($response2->getToolCalls()[0]);
    $this->assertFalse($writeResult['isError'] ?? false, 
        'WriteTable failed: ' . $writeResult['content']);
}
```

### Testing Patterns

1. **Exploration Pattern** - Most operations should explore first:
   ```php
   // Good: Expect GetPageTree → WriteTable
   // Bad: Expect immediate WriteTable without context
   ```

2. **Flexible Assertions** - Allow reasonable variations:
   ```php
   $this->assertContains($writeCall['data']['title'], 
       ['Products', 'Our Products'], 
       "Title could be interpreted either way");
   ```

3. **Error Handling** - Always assert tool success:
   ```php
   $this->assertFalse($result['isError'] ?? false, 
       'Tool failed: ' . $result['content']);
   ```

### Common Pitfalls to Avoid

1. **Don't give hints**: 
   - ❌ "Create page under ID 1"
   - ✅ "Create page under the home page"

2. **Don't force behavior**:
   - ❌ "Make sure to check the page tree first"
   - ✅ Let the LLM decide its approach

3. **Don't assume single tool calls**:
   - ❌ Expect everything in one response
   - ✅ Support multi-step conversations

4. **Don't ignore errors**:
   - ❌ Continue if WriteTable fails
   - ✅ Assert tool execution succeeds

## Switching Providers

To use OpenRouter or another provider:

1. Create a new client implementing `LlmClientInterface`
2. Update `LlmTestCase::setUp()` to use the new client
3. Adjust environment variable checks as needed

The client interface is designed to make provider switching straightforward.

## Handling Test Failures

LLM tests may fail due to:
1. **Model behavior changes**: LLMs evolve; update assertions if needed
2. **Non-determinism**: Despite temperature=0, some variation is possible
3. **Tool schema issues**: Failures may indicate unclear tool descriptions
4. **Missing extensions**: Ensure core extensions are loaded (workspaces, frontend)
5. **Authentication issues**: Backend user must be properly initialized

### Debugging Tips

- Use `getToolCallsDebugString()` to see what the LLM actually did
- Check for workspace errors - ensure `coreExtensionsToLoad` includes necessary extensions
- Verify backend user and language service are initialized in `setUp()`
- Enable debug output temporarily to see tool execution results
- Consider running a single test with `--filter` to isolate issues

## Architecture Notes

### Test Base Class Setup

The `LlmTestCase` base class handles:
- Loading core extensions (workspaces, frontend) and test extensions
- Setting up backend user authentication
- Initializing language service
- Loading `.env.local` for API keys
- Converting MCP tool schemas to OpenAI/Anthropic format

### Conversation Continuation

The framework supports multi-step conversations:
1. Initial prompt → LLM response with tool calls
2. Execute tool calls → Get results
3. Continue conversation with results → Next LLM response
4. Repeat as needed for complex workflows

This mirrors real-world usage where LLMs explore, act, and verify.

## Future Enhancements

- Support for multiple providers (OpenRouter, Google, etc.)
- Test result caching to reduce costs during development
- More complex multi-step workflow tests
- Performance benchmarks for different models
- Automatic retry on transient failures
- Cost tracking and reporting per test run