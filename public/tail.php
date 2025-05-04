<?php

declare(strict_types=1);
ini_set('display_errors', 0);
error_reporting(E_ALL);

$config = require __DIR__.'/../config/config.php';
$root   = rtrim($config['build_root'], '/');

$sid  = preg_replace('/[^a-zA-Z0-9_.-]/', '', $_GET['sid'] ?? '');
$pos  = (int)($_GET['pos'] ?? 0);

session_start();
if ($sid) {
    $logFile = "$root/$sid/exec.log";
} else {
    $sess    = preg_replace('/[^a-zA-Z0-9]/', '_', session_id());
    $logFile = "$root/sessions/$sess/_general.log";
}

if (!is_file($logFile)) {
    http_response_code(404);
    exit;
}

$size = filesize($logFile);
if ($pos >= $size) {
    http_response_code(204);
    exit;
}

/* read only the new part */
$fp = fopen($logFile, 'r');
fseek($fp, $pos);
$lines = [];
while (($line = fgets($fp)) !== false) {
    $lines[] = rtrim($line, "\r\n");
}
fclose($fp);

/* send JSON with the new byte offset */
header('Content-Type: application/json');
echo json_encode([
    'pos'   => $size,   // where the next poll should start
    'lines' => $lines,  // freshly‑appended lines
]);
