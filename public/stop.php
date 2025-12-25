<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

require __DIR__ . '/../vendor/autoload.php';

use App\Sandbox;
use App\Utils;

$config = require __DIR__ . '/../config/config.php';

// Initialize logging
Utils::initLogging(
    $config['log_file'] ?? __DIR__ . '/../logs/docka.log',
    $config['logging']['level'] ?? 'INFO'
);

try {
    // Validate sandbox ID
    $sid = Utils::sanitizeId($_GET['sid'] ?? '');

    if (empty($sid) || strlen($sid) > 64) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid sandbox ID']);
        exit;
    }

    // Verify sandbox exists
    $root = rtrim($config['build_root'], '/');
    $sandboxDir = "$root/$sid";

    if (!is_dir($sandboxDir)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Sandbox not found']);
        exit;
    }

    // Stop the sandbox
    $stopped = Sandbox::stop($sid, $config);

    if ($stopped) {
        Utils::log('INFO', 'Sandbox stopped via API', [
            'sid' => $sid,
            'ip' => Utils::getClientIp(),
        ]);
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to stop sandbox']);
    }

} catch (\Throwable $e) {
    Utils::log('ERROR', 'Stop failed', [
        'error' => $e->getMessage(),
        'ip' => Utils::getClientIp(),
    ]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal error']);
}
