<?php

declare(strict_types=1);

/**
 * SSE (Server-Sent Events) endpoint for streaming build progress
 */

// Error handlers - must be first
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e) {
    // Only set headers if not already sent
    if (!headers_sent()) {
        @header('Content-Type: text/event-stream');
    }
    echo "event: error\n";
    echo "data: " . json_encode(['message' => 'Server error: ' . $e->getMessage()]) . "\n\n";
    flush();
    exit(1);
});

// Disable time limits
set_time_limit(600);
ini_set('max_execution_time', '600');
ignore_user_abort(false);

// Disable all output buffering
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 'off');
@ini_set('implicit_flush', '1');
while (ob_get_level()) {
    ob_end_clean();
}

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// === SESSION HANDLING - Must be before any echo ===
session_start();

$token = $_GET['token'] ?? '';
if (empty($token)) {
    echo "event: error\ndata: " . json_encode(['message' => 'Missing build token']) . "\n\n";
    exit(1);
}

$buildData = $_SESSION['build_tokens'][$token] ?? null;
if (!$buildData) {
    echo "event: error\ndata: " . json_encode(['message' => 'Invalid or expired build token']) . "\n\n";
    exit(1);
}

// Remove token and close session IMMEDIATELY
unset($_SESSION['build_tokens'][$token]);
session_write_close();

// Check expiry
if (time() - ($buildData['created'] ?? 0) > 300) {
    echo "event: error\ndata: " . json_encode(['message' => 'Build token expired']) . "\n\n";
    exit(1);
}

// Extract build params
$repo = $buildData['repo'];
$ref = $buildData['ref'];
$env = $buildData['env'];
$sessionId = $buildData['sessionId'];
$clientIp = $buildData['clientIp'];

// === NOW we can start streaming ===
echo ": ping\n\n";
flush();

// Helper functions
function sendEvent(string $event, array $data): void {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
}

function sendHeartbeat(): void {
    echo ": heartbeat\n\n";
    flush();
}

function clientConnected(): bool {
    return !connection_aborted();
}

// Load dependencies
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    sendEvent('error', ['message' => 'Dependencies not installed']);
    exit(1);
}
require $autoloadPath;

use App\Sandbox;
use App\RateLimiter;
use App\Utils;

$config = require __DIR__ . '/../config/config.php';

Utils::initLogging(
    $config['log_file'] ?? __DIR__ . '/../logs/docka.log',
    $config['logging']['level'] ?? 'INFO'
);

Utils::log('INFO', 'SSE stream started', [
    'token' => substr($token, 0, 8) . '...',
    'repo' => $repo,
    'ip' => $clientIp,
]);

// Send connected event
sendEvent('connected', ['message' => 'Build stream connected']);

$rateLimiter = new RateLimiter($config);

try {
    $rateLimiter->record($clientIp, 'build_minute');
    $rateLimiter->record($clientIp, 'build_hour');
    $rateLimiter->incrementConcurrent();

    sendEvent('progress', ['stage' => 'init', 'message' => 'Initializing build...']);
    sendHeartbeat();

    if (!clientConnected()) {
        throw new \RuntimeException('Client disconnected');
    }

    Sandbox::cleanupOld($config['build_root'], $config['ttl_minutes']);

    $sandbox = new Sandbox($repo, $config, $sessionId, $ref, $env);

    sendEvent('progress', ['stage' => 'cloning', 'message' => 'Cloning repository...']);
    sendHeartbeat();

    if (!clientConnected()) {
        throw new \RuntimeException('Client disconnected');
    }

    // Progress callback for stage updates
    $progressCallback = function(string $stage, string $message) {
        sendEvent('progress', ['stage' => $stage, 'message' => $message]);
        sendHeartbeat();
        return clientConnected();
    };

    // Heartbeat callback for long operations (Docker build)
    $heartbeatCallback = function() {
        sendHeartbeat();
    };

    $result = $sandbox->runWithProgress($progressCallback, $heartbeatCallback);

    Utils::log('INFO', 'Build successful (SSE)', [
        'sandbox' => $sandbox->getId(),
        'repo' => $repo,
    ]);

    sendEvent('complete', [
        'ok' => true,
        'sandboxId' => $sandbox->getId(),
        'ports' => $result['ports'],
        'containerIds' => $result['containerIds'],
        'ttl' => $config['container_ttl_seconds'] ?? 3600,
    ]);

} catch (\InvalidArgumentException $e) {
    Utils::log('WARN', 'Validation error (SSE)', ['error' => $e->getMessage()]);
    sendEvent('error', ['message' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    Utils::log('ERROR', 'Build error (SSE)', ['error' => $e->getMessage()]);
    sendEvent('error', ['message' => $e->getMessage()]);
} catch (\Throwable $e) {
    Utils::log('ERROR', 'Unexpected error (SSE)', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    sendEvent('error', ['message' => $e->getMessage()]);
} finally {
    $rateLimiter->decrementConcurrent();
}

sendEvent('done', ['message' => 'Stream complete']);
