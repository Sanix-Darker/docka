<?php

use App\Sandbox;

header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config/config.php';


try {
    $repo  = $_POST['repo'] ?? '';
    if (!filter_var($repo, FILTER_VALIDATE_URL)) {
        throw new Exception('Invalid URL');
    }
    Sandbox::cleanup($config['build_root'], $config['ttl_minutes']);

    $sb   = new Sandbox($repo, $config);
    $out  = $sb->run();          // ['log'=>..., 'ports'=>[]]

    echo json_encode([
        'ok'    => true,
        'log'   => $out['log'],
        'ports' => $out['ports'],
    ]);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage()
    ]);
}
