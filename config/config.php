<?php
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
        'memory'  => '512m',   // e.g. '512m', '2g', null = unlimited
        'cpus'    => '0.50',   // 0.5 CPU ⇒ 50 % of 1 core
        'storage' => '1G',     // 1 GiB writable layer (needs devicemapper or fuse-overlayfs)
    ],

    // maybe INPUT only for --network host ?
    // not sure yet
    'firewall_chain' => 'DOCKER-USER',
];
