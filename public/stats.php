<?php

// for debuguing purpose only
function writeToLog($message) {
    $logFile = "log.txt";
    $logMessage = date("Y-m-d H:i:s") . " - " . $message . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

header('Content-Type: application/json');

$cid = preg_replace('/[^a-zA-Z0-9_.-]/', '', $_GET['cid'] ?? '');
if (!$cid) { echo '{}'; exit; }

writeToLog("cid: $cid");

$cmd  = sprintf(
    'docker stats --no-stream --format "{{json .}}" %s 2>/dev/null',
    escapeshellarg($cid)
);

writeToLog("cmd: $cmd");
$raw  = shell_exec($cmd);

writeToLog("raw: $raw");
if (!$raw) { echo '{}'; exit; }

$j   = json_decode($raw, true);

$cpu = isset($j['CPUPerc']) ? (float) rtrim($j['CPUPerc'], '%') : null;

writeToLog("cpu: $cpu");
/* “MemUsage” looks like “42.3MiB / 1.9GiB” – we convert the first part to bytes */
$memRaw = strtok($j['MemUsage'] ?? '', '/');
$memB   = null;
if (preg_match('/([\d.]+)\s*([A-Za-z]+)/', $memRaw, $m)) {
    $val  = (float) $m[1];
    $unit = strtolower($m[2]);
    $factor = match ($unit) {
        'b'           => 1,
        'kb', 'kib'   => 1024,
        'mb', 'mib'   => 1024 ** 2,
        'gb', 'gib'   => 1024 ** 3,
        'tb', 'tib'   => 1024 ** 4,
        default       => 1,
    };
    $memB = $val * $factor;
}

echo json_encode(['cpu' => $cpu, 'mem' => $memB]);
