<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

use App\Utils;

$config = require __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

// Validate sandbox ID
$sid = Utils::sanitizeId($_GET['sid'] ?? '');
$pos = max(0, (int) ($_GET['pos'] ?? 0));

if (empty($sid) || strlen($sid) > 64) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid sandbox ID']);
    exit;
}

// Construct log file path
$root = rtrim($config['build_root'], '/');
$logFile = "$root/$sid/exec.log";

// Verify the sandbox directory exists and log file is within it
$realLogPath = realpath($logFile);
$realRoot = realpath($root);

if (!$realLogPath || !str_starts_with($realLogPath, $realRoot)) {
    http_response_code(404);
    echo json_encode(['error' => 'Log not found']);
    exit;
}

if (!is_file($logFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Log file not found']);
    exit;
}

// Get file size
clearstatcache(true, $logFile);
$size = filesize($logFile);

// No new content
if ($pos >= $size) {
    http_response_code(204);
    exit;
}

// Limit how much we read at once (prevent memory exhaustion)
$maxRead = 65536; // 64KB
$readSize = min($size - $pos, $maxRead);

// Read new content
$fp = fopen($logFile, 'r');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot open log file']);
    exit;
}

fseek($fp, $pos);
$content = fread($fp, $readSize);
fclose($fp);

// Split into lines
$lines = explode("\n", $content);

// If we didn't read to end of file, the last line might be incomplete
// Don't include it and adjust the position
$newPos = $pos + strlen($content);
if ($pos + $readSize < $size && !empty($lines)) {
    $lastLine = array_pop($lines);
    $newPos -= strlen($lastLine);
}

// Clean up lines
$lines = array_map(function ($line) {
    return rtrim($line, "\r\n");
}, $lines);

// Remove empty trailing lines
while (!empty($lines) && $lines[count($lines) - 1] === '') {
    array_pop($lines);
}

echo json_encode([
    'pos' => $newPos,
    'lines' => $lines,
    'hasMore' => $newPos < $size,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
