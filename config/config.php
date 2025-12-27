<?php

declare(strict_types=1);

/**
 * Docka Configuration
 *
 * Security-hardened configuration for the container sandbox service.
 */

return [
    // PATHS & STORAGE
    // Absolute path where cloned repos & containers live
    'build_root' => __DIR__ . '/../builds',

    // Log file location
    'log_file' => __DIR__ . '/../logs/docka.log',

    // TIMEOUTS & TTL
    // How long (minutes) before we auto-clean old sandbox directories
    'ttl_minutes' => 120,

    // How long (seconds) containers run before auto-termination
    'container_ttl_seconds' => 3600,

    // Git clone timeout in seconds
    'git_timeout_seconds' => 120,

    // Docker build timeout in seconds
    'build_timeout_seconds' => 600,

    // RESOURCE LIMITS (per container)
    'limits' => [
        'memory'      => '512m',    // e.g. '512m', '1g', null = unlimited
        'cpus'        => '0.5',     // 0.5 CPU ⇒ 50% of 1 core
        'pids'        => 100,       // Max processes inside container
        'network_mode'=> 'bridge',  // bridge, none, host (host is dangerous!)
    ],

    // RATE LIMITING
    'rate_limit' => [
        // Per-IP rate limiting
        'builds_per_ip_per_hour'    => 4, // 10 for dev
        'builds_per_ip_per_minute'  => 1, // 3 for dev

        // Global rate limiting
        'max_concurrent_builds'     => 3, // 5 for dev

        // Session-based limits
        'max_per_session'           => 1, // 3 for dev

        // Rate limit storage (file-based for simplicity)
        'storage_path'              => __DIR__ . '/../builds/.ratelimit',
    ],

    // SECURITY
    // Firewall chain for port rules
    'firewall_chain' => 'DOCKER-USER',

    // Allowed git hosts (empty array = allow all)
    'allowed_hosts' => [
        'github.com',
        'gitlab.com',
        'bitbucket.org',
    ],

    // Blocked patterns in repository URLs
    'blocked_url_patterns' => [
        '/\.\./',           // Path traversal
        '/[;&|`$]/',        // Shell injection chars
        '/\s/',             // Whitespace
    ],

    // Docker security options
    'docker_security' => [
        'no_new_privileges' => true,
        'read_only_root'    => false,  // Some apps need writable root
        'drop_capabilities' => ['ALL'],
        'add_capabilities'  => ['CHOWN', 'SETUID', 'SETGID', 'NET_BIND_SERVICE'],
    ],

    // Blocked Dockerfile instructions (security risk)
    'blocked_dockerfile_patterns' => [
        '/--privileged/i',
        '/--cap-add\s+ALL/i',
        '/--network\s+host/i',
    ],

    // PORT ALLOCATION
    'port_range' => [
        'min' => 32768,
        'max' => 60999,
    ],

    // LOGGING
    'logging' => [
        'enabled'       => true,
        'level'         => 'DEBUG',  // DEBUG, INFO, WARN, ERROR
        'max_file_size' => 10485760, // 10MB
        'max_files'     => 5,
    ],

    // CSRF PROTECTION
    'csrf' => [
        'enabled'     => true,
        'token_name'  => '_csrf_token',
        'token_ttl'   => 3600,
    ],
];
