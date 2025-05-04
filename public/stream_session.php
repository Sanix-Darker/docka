<?php
// NOT USED AT THE MOMENT, MAYBE ON FUTURE VERSIONS

/**
 * Aggregate every exec.log belonging to the current PHP session and
 * expose them as a single Server‑Sent‑Events (SSE) stream.
 *
 * Each event’s data payload is JSON:
 *   { "sid": "<sandbox‑id>", "msg": "<single log line>" }
 */
session_start(['read_and_close' => true]);   // ← no lock held afterwards
$config = require __DIR__ . '/../config/config.php';

$root      = rtrim($config['build_root'], '/');
$sessionId = preg_replace('/[^a-zA-Z0-9_]/', '_', session_id());
$sesDir    = "$root/sessions/$sessionId";

if (!is_dir($sesDir)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$handles = [];

/* open any new exec.log files for this session */
$openLogs = function () use (&$handles, $sesDir, $root): void {
    foreach (glob("$sesDir/*.flag") as $flag) {
        $sid = basename($flag, '.flag');
        if (isset($handles[$sid])) {
            continue;
        }
        $logFile = "$root/$sid/exec.log";
        if (!is_file($logFile)) {
            continue;
        }
        if ($fp = fopen($logFile, 'r')) {
            fseek($fp, 0, SEEK_END);            // don’t replay history
            $handles[$sid] = $fp;
        }
    }
};

$openLogs();
$ticker = 0;

while (true) {
    foreach ($handles as $sid => $fp) {
        while (($line = fgets($fp)) !== false) {
            $payload = json_encode(
                ['sid' => $sid, 'msg' => rtrim($line)],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            echo "data: $payload\n\n";
            @ob_flush();
            @flush();
        }
        /* sandbox cleaned up? */
        if (!is_file("$root/$sid/exec.log")) {
            fclose($fp);
            unset($handles[$sid]);
        }
    }

    /* every second, look for brand‑new sandboxes */
    if (++$ticker === 5) {
        $openLogs();
        $ticker = 0;
    }

    clearstatcache();
    usleep(200_000);   // 200 ms
}
