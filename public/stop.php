<?php
require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config/config.php';

$sid = preg_replace('/[^a-zA-Z0-9_.-]/', '', $_GET['sid'] ?? '');
if (!$sid) {
    http_response_code(400);
    exit;
}

$root     = rtrim($config['build_root'], '/');
$metaFile = "$root/$sid/meta.json";
$meta     = is_file($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];

$mode  = $meta['mode']  ?? 'single';
$ports = $meta['ports'] ?? [];

/* stop containers */
if ($mode === 'compose') {
    shell_exec('docker compose -p '.escapeshellarg($sid).' down -v --remove-orphans');
} else {
    shell_exec('docker rm -fv '.escapeshellarg($sid));
}

/* close firewall holes */
foreach ($ports as $p) {
    shell_exec(sprintf(
        'iptables -D %s -p tcp --dport %d -j ACCEPT',
        escapeshellarg($config['firewall_chain'] ?? 'DOCKER-USER'),
        (int) $p['hostPort']
    ));
}

/* drop session flag (so the quota decrements) */
$flag = glob("$root/sessions/*/$sid.flag")[0] ?? null;
if ($flag) unlink($flag);

echo json_encode(['ok' => true]);
