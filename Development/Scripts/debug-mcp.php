<?php

/**
 * MCP Server Debug Script
 * 
 * This script helps debug the MCP server by directly testing the communication
 * within the Docker container.
 */

// Set up error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Define the path to the TYPO3 CLI
$typo3Path = dirname(__DIR__, 5) . '/vendor/bin/typo3';

// Prepare the initialize request
$initRequest = [
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2024-11-05',
        'capabilities' => (object)[],
        'clientInfo' => [
            'name' => 'debug-client',
            'version' => '0.1.0'
        ]
    ]
];

// Convert to JSON and prepare the message
$json = json_encode($initRequest);
$message = "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;

// Write debug info
echo "Starting MCP server debug...\n";
echo "Initialize request: " . $json . "\n\n";

// Open pipes to the MCP server process
$descriptorspec = [
    0 => ["pipe", "r"],  // stdin
    1 => ["pipe", "w"],  // stdout
    2 => ["pipe", "w"]   // stderr
];

// Start the MCP server process
$process = proc_open("$typo3Path mcp:server", $descriptorspec, $pipes);

if (is_resource($process)) {
    echo "MCP server process started\n";
    
    // Set pipes to non-blocking mode
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    
    // Send the initialize request
    fwrite($pipes[0], $message);
    fflush($pipes[0]);
    echo "Sent initialize request\n";
    
    // Wait for response with timeout
    $start = time();
    $timeout = 10; // seconds
    $response = '';
    $headers = [];
    $contentLength = 0;
    $headersDone = false;
    $responseComplete = false;
    
    echo "Waiting for response...\n";
    
    while (time() - $start < $timeout && !$responseComplete) {
        // Check for stderr output
        $stderr = fread($pipes[2], 8192);
        if ($stderr) {
            echo "STDERR: $stderr\n";
        }
        
        // If we're still reading headers
        if (!$headersDone) {
            $line = fgets($pipes[1]);
            if ($line !== false) {
                $line = trim($line);
                echo "Read header line: '$line'\n";
                
                if (empty($line)) {
                    $headersDone = true;
                    echo "Headers complete, content length: $contentLength\n";
                } elseif (strpos($line, 'Content-Length:') === 0) {
                    $contentLength = (int)trim(substr($line, 15));
                }
            }
        } else {
            // Reading content
            $chunk = fread($pipes[1], $contentLength - strlen($response));
            if ($chunk !== false && $chunk !== '') {
                $response .= $chunk;
                echo "Read " . strlen($chunk) . " bytes, total: " . strlen($response) . "/" . $contentLength . "\n";
                
                if (strlen($response) >= $contentLength) {
                    $responseComplete = true;
                }
            }
        }
        
        // Small delay to avoid CPU spinning
        usleep(100000); // 100ms
    }
    
    // Process response
    if ($responseComplete) {
        echo "\nResponse received: $response\n";
        $decoded = json_decode($response, true);
        echo "Decoded response: " . print_r($decoded, true) . "\n";
    } else {
        echo "\nTimeout waiting for response\n";
    }
    
    // Clean up
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
} else {
    echo "Failed to start MCP server process\n";
}

echo "\nDebug completed\n";
