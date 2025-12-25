<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

require __DIR__ . '/../vendor/autoload.php';

use App\Utils;

/**
 * Get container stats from Docker
 */
function getContainerStats(string $cid): array
{
    $cmd = sprintf(
        'docker stats --no-stream --format "{{json .}}" %s 2>/dev/null',
        escapeshellarg($cid)
    );

    $output = shell_exec($cmd);

    if (!$output) {
        return ['cpu' => null, 'mem' => null, 'running' => false];
    }

    $data = json_decode(trim($output), true);

    if (!is_array($data)) {
        return ['cpu' => null, 'mem' => null, 'running' => false];
    }

    // Parse CPU percentage
    $cpu = null;
    if (isset($data['CPUPerc'])) {
        $cpu = (float) rtrim($data['CPUPerc'], '%');
    }

    // Parse memory usage (convert to bytes)
    $mem = null;
    if (isset($data['MemUsage'])) {
        $memRaw = strtok($data['MemUsage'], '/');
        $mem = parseMemoryValue($memRaw);
    }

    // Parse network I/O
    $netIn = null;
    $netOut = null;
    if (isset($data['NetIO'])) {
        $parts = explode('/', $data['NetIO']);
        if (count($parts) === 2) {
            $netIn = parseMemoryValue(trim($parts[0]));
            $netOut = parseMemoryValue(trim($parts[1]));
        }
    }

    return [
        'cpu' => $cpu,
        'mem' => $mem,
        'netIn' => $netIn,
        'netOut' => $netOut,
        'running' => true,
        'name' => $data['Name'] ?? null,
    ];
}

/**
 * Parse memory/size value with unit to bytes
 */
function parseMemoryValue(?string $value): ?int
{
    if (!$value || !preg_match('/([\d.]+)\s*([A-Za-z]+)?/', $value, $m)) {
        return null;
    }

    $val = (float) $m[1];
    $unit = strtolower($m[2] ?? 'b');

    $multipliers = [
        'b' => 1,
        'kb' => 1024,
        'kib' => 1024,
        'mb' => 1024 ** 2,
        'mib' => 1024 ** 2,
        'gb' => 1024 ** 3,
        'gib' => 1024 ** 3,
        'tb' => 1024 ** 4,
        'tib' => 1024 ** 4,
    ];

    return (int) ($val * ($multipliers[$unit] ?? 1));
}

// Validate container ID
$cid = Utils::sanitizeId($_GET['cid'] ?? '');

if (empty($cid) || strlen($cid) > 64) {
    echo json_encode(['error' => 'Invalid container ID']);
    exit;
}

// Get and return stats
$stats = getContainerStats($cid);
echo json_encode($stats, JSON_NUMERIC_CHECK);
