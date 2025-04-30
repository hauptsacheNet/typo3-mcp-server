<?php

/**
 * Improved MCP client test script
 * 
 * This script simulates an MCP client by sending requests to the MCP server
 * and properly handling the responses.
 * 
 * Usage:
 * php improved-test-client.php | docker exec -i fabius-php-1 vendor/bin/typo3 mcp:server
 */

// Function to send a request and read the response
function sendRequest($request) {
    // Send the request
    $json = json_encode($request);
    $message = "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;
    fwrite(STDOUT, $message);
    fflush(STDOUT);
    
    // Send debug info to stderr instead of stdout
    fwrite(STDERR, "Sent request: " . json_encode($request, JSON_PRETTY_PRINT) . "\n");
    
    // Read the response
    $headers = [];
    $line = '';
    $contentLength = 0;
    $headersDone = false;
    
    // Read headers
    while (!$headersDone && ($char = fgetc(STDIN)) !== false) {
        if ($char === "\r") {
            continue; // Skip CR
        }
        
        if ($char === "\n") {
            // End of line
            if ($line === '') {
                // Empty line marks end of headers
                $headersDone = true;
                continue;
            }
            
            // Parse Content-Length header
            if (stripos($line, 'Content-Length:') === 0) {
                $contentLength = (int)trim(substr($line, 15));
                fwrite(STDERR, "Found Content-Length: $contentLength\n");
            }
            
            $line = '';
        } else {
            $line .= $char;
        }
    }
    
    if (!$headersDone) {
        fwrite(STDERR, "Error: Failed to read headers\n");
        return null;
    }
    
    if ($contentLength <= 0) {
        fwrite(STDERR, "Error: Invalid Content-Length: $contentLength\n");
        return null;
    }
    
    // Read the message content
    $content = '';
    $bytesRead = 0;
    
    while ($bytesRead < $contentLength) {
        $chunk = fread(STDIN, min(8192, $contentLength - $bytesRead));
        if ($chunk === false || $chunk === '') {
            // End of stream or error
            fwrite(STDERR, "Error: Failed to read content at byte $bytesRead\n");
            break;
        }
        
        $content .= $chunk;
        $bytesRead += strlen($chunk);
        fwrite(STDERR, "Read $bytesRead of $contentLength bytes\n");
    }
    
    fwrite(STDERR, "Received response: $content\n\n");
    return json_decode($content, true);
}

// Initialize request
$initRequest = [
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2024-11-05',
        'capabilities' => (object)[],
        'clientInfo' => [
            'name' => 'mcp-test-client',
            'version' => '0.1.0'
        ]
    ]
];

function debug($message) {
    fwrite(STDERR, $message . "\n");
}

debug("===== Testing initialize request =====");
$initResponse = sendRequest($initRequest);
if ($initResponse) {
    debug("Initialize successful!\n");
} else {
    debug("Initialize failed!\n");
    exit(1);
}

// List tools request
$toolsRequest = [
    'jsonrpc' => '2.0',
    'id' => 2,
    'method' => 'tools/list',
    'params' => (object)[]
];

debug("===== Testing tools/list request =====");
$toolsResponse = sendRequest($toolsRequest);
if ($toolsResponse && isset($toolsResponse['result']['tools'])) {
    debug("Found " . count($toolsResponse['result']['tools']) . " tools");
    foreach ($toolsResponse['result']['tools'] as $tool) {
        debug("- " . $tool['name'] . ": " . $tool['description']);
    }
    debug("");
} else {
    debug("Failed to list tools!\n");
}

// Call a tool (page tree)
$callToolRequest = [
    'jsonrpc' => '2.0',
    'id' => 3,
    'method' => 'tools/call',
    'params' => [
        'name' => 'typo3.page.tree',
        'arguments' => [
            'startPage' => 0,
            'depth' => 2,
            'includeHidden' => true
        ]
    ]
];

debug("===== Testing tools/call request =====");
$callToolResponse = sendRequest($callToolRequest);
if ($callToolResponse && isset($callToolResponse['result']['content'])) {
    debug("Tool call successful!\n");
} else {
    debug("Tool call failed!\n");
}

// Shutdown request
$shutdownRequest = [
    'jsonrpc' => '2.0',
    'id' => 4,
    'method' => 'shutdown',
    'params' => (object)[]
];

debug("===== Testing shutdown request =====");
$shutdownResponse = sendRequest($shutdownRequest);
if ($shutdownResponse) {
    debug("Shutdown successful!\n");
} else {
    debug("Shutdown failed!\n");
}

debug("Test completed.");
