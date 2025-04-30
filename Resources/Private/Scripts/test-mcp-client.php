<?php

/**
 * Simple MCP client test script
 * 
 * This script simulates an MCP client by sending requests to the MCP server
 * and printing the responses.
 * 
 * Usage:
 * php test-mcp-client.php | vendor/bin/typo3 mcp:server
 */

// Function to send a request and read the response
function sendRequest($request) {
    // Send the request
    $json = json_encode($request);
    $message = "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;
    fwrite(STDOUT, $message);
    fflush(STDOUT);
    
    // Read the response
    $headers = [];
    $line = '';
    $contentLength = 0;
    
    // Read headers
    while (($char = fgetc(STDIN)) !== false) {
        if ($char === "\r") {
            continue;
        }
        
        if ($char === "\n") {
            if ($line === '') {
                break;
            }
            
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
                
                if (strtolower(trim($name)) === 'content-length') {
                    $contentLength = (int)trim($value);
                }
            }
            
            $line = '';
        } else {
            $line .= $char;
        }
    }
    
    // Read content
    $content = '';
    $bytesRead = 0;
    
    while ($bytesRead < $contentLength) {
        $chunk = fread(STDIN, $contentLength - $bytesRead);
        if ($chunk === false || $chunk === '') {
            break;
        }
        
        $content .= $chunk;
        $bytesRead += strlen($chunk);
    }
    
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

echo "Sending initialize request...\n" . PHP_EOL;
$initResponse = sendRequest($initRequest);
echo "Received initialize response:\n";
print_r($initResponse);
echo PHP_EOL;

// List tools request
$toolsRequest = [
    'jsonrpc' => '2.0',
    'id' => 2,
    'method' => 'tools/list',
    'params' => (object)[]
];

echo "Sending tools/list request...\n" . PHP_EOL;
$toolsResponse = sendRequest($toolsRequest);
echo "Received tools/list response:\n";
print_r($toolsResponse);
echo PHP_EOL;

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

echo "Sending tools/call request for page tree...\n" . PHP_EOL;
$callToolResponse = sendRequest($callToolRequest);
echo "Received tools/call response:\n";
print_r($callToolResponse);
echo PHP_EOL;

// Shutdown request
$shutdownRequest = [
    'jsonrpc' => '2.0',
    'id' => 4,
    'method' => 'shutdown',
    'params' => (object)[]
];

echo "Sending shutdown request...\n" . PHP_EOL;
$shutdownResponse = sendRequest($shutdownRequest);
echo "Received shutdown response:\n";
print_r($shutdownResponse);
echo PHP_EOL;

echo "Test completed.\n";
