<?php

declare(strict_types=1);

// Catch any PHP errors/warnings and convert to JSON
ob_start();

// Disable error display (we'll handle it ourselves)
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');
// ... rest of headers ...

session_start();
$sessionId = session_id();

// Check if vendor autoload exists
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => 'Dependencies not installed. Run: composer install']);
    exit;
}

require $autoloadPath;

use App\Sandbox;
use App\RateLimiter;
use App\Utils;

$config = require __DIR__ . '/../config/config.php';

// Initialize logging
Utils::initLogging(
    $config['log_file'] ?? __DIR__ . '/../logs/docka.log',
    $config['logging']['level'] ?? 'INFO'
);

/**
 * Send JSON response and exit
 */
function respond(bool $ok, array $data = []): never
{
    // Clear any buffered output (PHP warnings, etc.)
    ob_end_clean();

    echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, ['error' => 'Method not allowed']);
    }

    // CSRF validation
    if ($config['csrf']['enabled'] ?? true) {
        $token = $_POST['_csrf'] ?? '';
        if (!Utils::validateCsrfToken($token, $config['csrf']['token_ttl'] ?? 3600)) {
            Utils::log('WARN', 'CSRF validation failed', ['ip' => Utils::getClientIp()]);
            respond(false, ['error' => 'Invalid or expired session. Please refresh the page.']);
        }
    }

    // Get client IP
    $clientIp = Utils::getClientIp();

    // Rate limiting
    $rateLimiter = new RateLimiter($config);
    $rateLimitErrors = $rateLimiter->checkBuildLimits($clientIp, $sessionId);

    if (!empty($rateLimitErrors)) {
        Utils::log('WARN', 'Rate limit exceeded', [
            'ip' => $clientIp,
            'session' => $sessionId,
            'errors' => $rateLimitErrors,
        ]);
        respond(false, ['error' => $rateLimitErrors[0]]);
    }

    // Validate inputs
    $repo = trim($_POST['repo'] ?? '');
    $ref = trim($_POST['ref'] ?? '') ?: null;
    $env = $_POST['env'] ?? null;

    if (empty($repo)) {
        respond(false, ['error' => 'Repository URL is required']);
    }

    // Validate URL format and security
    $urlErrors = Utils::validateRepoUrl($repo, $config);
    if (!empty($urlErrors)) {
        Utils::log('WARN', 'Invalid repository URL', [
            'url' => $repo,
            'errors' => $urlErrors,
        ]);
        respond(false, ['error' => $urlErrors[0]]);
    }

    // Record rate limit hit
    $rateLimiter->record($clientIp, 'build_minute');
    $rateLimiter->record($clientIp, 'build_hour');
    $rateLimiter->incrementConcurrent();

    try {
        // Clean up old sandboxes
        Sandbox::cleanupOld($config['build_root'], $config['ttl_minutes']);

        // Create and run sandbox
        $sandbox = new Sandbox($repo, $config, $sessionId, $ref, $env);
        $result = $sandbox->run();

        Utils::log('INFO', 'Build successful', [
            'ip' => $clientIp,
            'session' => $sessionId,
            'sandbox' => $sandbox->getId(),
            'repo' => $repo,
        ]);

        respond(true, [
            'sandboxId' => $sandbox->getId(),
            'ports' => $result['ports'],
            'containerIds' => $result['containerIds'],
            'ttl' => $config['container_ttl_seconds'] ?? 3600,
        ]);

    } finally {
        $rateLimiter->decrementConcurrent();
    }

} catch (\InvalidArgumentException $e) {
    Utils::log('WARN', 'Validation error', [
        'error' => $e->getMessage(),
        'ip' => Utils::getClientIp(),
    ]);
    respond(false, ['error' => $e->getMessage()]);

} catch (\RuntimeException $e) {
    Utils::log('ERROR', 'Build error', [
        'error' => $e->getMessage(),
        'ip' => Utils::getClientIp(),
    ]);
    respond(false, ['error' => $e->getMessage()]);

} catch (\Throwable $e) {
    Utils::log('ERROR', 'Unexpected error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    respond(false, ['error' => 'An unexpected error occurred. Please try again.']);
}
