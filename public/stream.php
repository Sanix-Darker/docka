<?php

declare(strict_types=1);
set_time_limit(0);                 // keep the script alive
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');
ob_implicit_flush(1);

$config = require __DIR__.'/../config/config.php';
$root   = rtrim($config['build_root'], '/').'/';

$sid  = preg_replace('/[^a-zA-Z0-9_.-]/', '', $_GET['sid'] ?? '');
$sess = preg_replace('/[^a-zA-Z0-9]/',   '_', session_id());

$logFile = $sid
    ? "{$root}{$sid}/exec.log"
    : "{$root}sessions/{$sess}/_general.log";

writeToLog("sid: $sid");
writeToLog("logFile: $logFile");

/* ----------  HEADERS  ---------- */
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

/* ----------  WAIT UNTIL THE FILE EXISTS  ---------- */
while (!is_file($logFile)) {
    echo ": Waiting for log file\n\n";
    flush();
    if (connection_aborted()) exit;
    usleep(500_000);                    // 0.5 s
}

/* ---------- basic TAIL ‑F  ---------- */
$fp = fopen($logFile, 'r');
fseek($fp, 0, SEEK_END);               // start at EOF – only new lines

$pingTimer = time();
while (!connection_aborted()) {

    /* send any freshly‑written lines */
    while (($line = fgets($fp)) !== false) {
        echo 'data: '.rtrim($line)."\n\n";
        flush();
    }

    /* heartbeat every 15 s so that proxies don’t time us out */
    if (time() - $pingTimer > 15) {
        echo ": ping\n\n";
        flush();
        $pingTimer = time();
    }

    clearstatcache();
    usleep(200_000);
}
