<?php

declare(strict_types=1);

set_time_limit(30);

while (ob_get_level()) {
    ob_end_clean();
}

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

session_start();
$sessionId = session_id();

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    echo json_encode(['ok' => false, 'error' => 'Dependencies not installed']);
    exit(1);
}

require $autoloadPath;

use App\RateLimiter;
use App\Utils;

$config = require __DIR__ . '/../config/config.php';

Utils::initLogging(
    $config['log_file'] ?? __DIR__ . '/../logs/docka.log',
    $config['logging']['level'] ?? 'INFO'
);

function respond(bool $ok, array $data = []): never
{
    $json = json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_SLASHES);
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit(0);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(false, ['error' => 'Method not allowed']);
    }

    if ($config['csrf']['enabled'] ?? true) {
        $token = $_POST['_csrf'] ?? '';
        if (!Utils::validateCsrfToken($token, $config['csrf']['token_ttl'] ?? 3600)) {
            respond(false, ['error' => 'Invalid or expired session. Please refresh the page.']);
        }
    }

    $clientIp = Utils::getClientIp();

    $rateLimiter = new RateLimiter($config);
    $rateLimitErrors = $rateLimiter->checkBuildLimits($clientIp, $sessionId);

    if (!empty($rateLimitErrors)) {
        respond(false, ['error' => $rateLimitErrors[0]]);
    }

    $repo = trim($_POST['repo'] ?? '');
    $ref = trim($_POST['ref'] ?? '') ?: null;
    $env = $_POST['env'] ?? null;

    if (empty($repo)) {
        respond(false, ['error' => 'Repository URL is required']);
    }

    $urlErrors = Utils::validateRepoUrl($repo, $config);
    if (!empty($urlErrors)) {
        respond(false, ['error' => $urlErrors[0]]);
    }

    if ($ref !== null && !preg_match('#^[a-zA-Z0-9./_-]+$#', $ref)) {
        respond(false, ['error' => 'Invalid branch/tag format']);
    }

    $buildToken = bin2hex(random_bytes(32));

    if (!isset($_SESSION['build_tokens'])) {
        $_SESSION['build_tokens'] = [];
    }

    $now = time();
    $_SESSION['build_tokens'] = array_filter(
        $_SESSION['build_tokens'],
        fn($data) => ($now - ($data['created'] ?? 0)) < 300
    );

    $_SESSION['build_tokens'][$buildToken] = [
        'repo' => $repo,
        'ref' => $ref,
        'env' => $env,
        'sessionId' => $sessionId,
        'clientIp' => $clientIp,
        'created' => $now,
    ];

    respond(true, [
        'token' => $buildToken,
        'streamUrl' => 'stream.php?token=' . $buildToken,
    ]);

} catch (\Throwable $e) {
    respond(false, ['error' => 'An unexpected error occurred']);
}
