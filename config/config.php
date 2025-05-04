<?php

// for debuguing purpose only
function writeToLog($message) {
    $logFile = "log.txt";
    $logMessage = date("Y-m-d H:i:s") . " - " . $message . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// Am not yet sure about this tho...
return [
    // absolute path where cloned repos & containers live
    'build_root' => __DIR__ . '/../builds',
    // how long before we auto-clean old sandboxes
    'ttl_minutes' => 86400,
    // running containers G
    'container_ttl_seconds' => 3600,

    // resources allowed per container...
    'limits' => [
        'memory'  => '1g',   // e.g. '512m', '2g', null = unlimited
        'cpus'    => '1',   // 0.5 CPU ⇒ 50 % of 1 core
        // FIXME: will still investigate why this does no work properly
        //'storage' => '1G',     // 1 GiB writable layer (needs devicemapper or fuse-overlayfs)
    ],

    // maybe INPUT only for --network host ?
    'firewall_chain' => 'DOCKER-USER',

    // max container per on the php session opened
    'max_per_session'       => 30,
];
