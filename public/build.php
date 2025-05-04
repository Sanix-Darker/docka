<?php

use App\Sandbox;

header('Content-Type: application/json');

session_start();
$sessId = session_id();
// does not need this for now
// session_write_close();

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config/config.php';


try {
    $repo = $_POST['repo'] ?? '';
    $ref  = $_POST['ref']  ?? null;
    $env  = $_POST['env']  ?? null;

    if (!filter_var($repo, FILTER_VALIDATE_URL)) {
        throw new Exception('Invalid URL');
    }
    Sandbox::cleanup($config['build_root'], $config['ttl_minutes']);

    $sb  = new Sandbox($repo, $config, $sessId, $ref, $env);
    $out = $sb->run();

    echo json_encode([
        'ok'          => true,
        'session_id' => $sessId,
        'sandboxId'   => $sb->getId(),
        'ports'       => $out['ports'],
        'containerIds'=> $out['containerIds']
    ]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage()
    ]);
}
