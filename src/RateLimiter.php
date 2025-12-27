<?php

declare(strict_types=1);

namespace App;

/**
 * File-based rate limiter with sliding window algorithm
 */
class RateLimiter
{
    private string $storagePath;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->storagePath = rtrim($config['rate_limit']['storage_path'] ?? '/tmp/docka_ratelimit', '/');

        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * Check if an action is allowed for the given identifier
     */
    public function isAllowed(string $identifier, string $action, int $limit, int $windowSeconds): bool
    {
        $key = $this->getKey($identifier, $action);
        $now = time();
        $windowStart = $now - $windowSeconds;

        $timestamps = $this->getTimestamps($key);

        // Filter to only timestamps within the window
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $windowStart);

        return count($timestamps) < $limit;
    }

    /**
     * Record an action for the given identifier
     */
    public function record(string $identifier, string $action): void
    {
        $key = $this->getKey($identifier, $action);
        $now = time();

        $timestamps = $this->getTimestamps($key);
        $timestamps[] = $now;

        // Keep only last hour of data
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $now - 3600);

        $this->saveTimestamps($key, $timestamps);
    }

    /**
     * Check rate limits for a build request
     */
    public function checkBuildLimits(string $ip, string $sessionId): array
    {
        $errors = [];
        $config = $this->config['rate_limit'] ?? [];

        // Per-IP per-minute limit
        $perMinute = $config['builds_per_ip_per_minute'] ?? 3;
        if (!$this->isAllowed($ip, 'build_minute', $perMinute, 60)) {
            $errors[] = "Rate limit exceeded: max $perMinute builds per minute";
        }

        // Per-IP per-hour limit
        $perHour = $config['builds_per_ip_per_hour'] ?? 10;
        if (!$this->isAllowed($ip, 'build_hour', $perHour, 3600)) {
            $errors[] = "Rate limit exceeded: max $perHour builds per hour";
        }

        // Global concurrent builds
        $maxConcurrent = $config['max_concurrent_builds'] ?? 5;
        if ($this->getConcurrentBuilds() >= $maxConcurrent) {
            $errors[] = "Server busy: max $maxConcurrent concurrent builds";
        }

        return $errors;
    }

    /**
     * Get current number of concurrent builds
     */
    public function getConcurrentBuilds(): int
    {
        $lockFile = $this->storagePath . '/concurrent.lock';
        $countFile = $this->storagePath . '/concurrent.count';

        // Ensure directory exists
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        // Return 0 if count file doesn't exist yet
        if (!file_exists($countFile)) {
            return 0;
        }

        $fp = fopen($lockFile, 'c');
        if (!$fp || !flock($fp, LOCK_SH)) {
            if ($fp) fclose($fp);
            return 0;
        }

        $count = file_exists($countFile) ? (int) file_get_contents($countFile) : 0;

        flock($fp, LOCK_UN);
        fclose($fp);

        return max(0, $count);
    }

    /**
     * Increment concurrent build counter
     */
    public function incrementConcurrent(): void
    {
        $this->updateConcurrent(1);
    }

    /**
     * Decrement concurrent build counter
     */
    public function decrementConcurrent(): void
    {
        $this->updateConcurrent(-1);
    }

    private function updateConcurrent(int $delta): void
    {
        $lockFile = $this->storagePath . '/concurrent.lock';
        $countFile = $this->storagePath . '/concurrent.count';

        // Ensure directory exists
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $fp = fopen($lockFile, 'c');
        if (!$fp || !flock($fp, LOCK_EX)) {
            if ($fp) fclose($fp);
            return;
        }

        $count = file_exists($countFile) ? (int) file_get_contents($countFile) : 0;
        $count = max(0, $count + $delta);
        file_put_contents($countFile, (string) $count);

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Get remaining requests in window
     */
    public function getRemaining(string $identifier, string $action, int $limit, int $windowSeconds): int
    {
        $key = $this->getKey($identifier, $action);
        $windowStart = time() - $windowSeconds;

        $timestamps = $this->getTimestamps($key);
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $windowStart);

        return max(0, $limit - count($timestamps));
    }

    /**
     * Clean up old rate limit data
     */
    public function cleanup(): void
    {
        $now = time();
        $maxAge = 7200; // 2 hours

        foreach (glob($this->storagePath . '/*.json') as $file) {
            if (filemtime($file) < $now - $maxAge) {
                @unlink($file);
            }
        }
    }

    private function getKey(string $identifier, string $action): string
    {
        return hash('sha256', $identifier . ':' . $action);
    }

    private function getTimestamps(string $key): array
    {
        $file = $this->storagePath . '/' . $key . '.json';

        if (!is_file($file)) {
            return [];
        }

        $data = @file_get_contents($file);
        if ($data === false) {
            return [];
        }

        $timestamps = json_decode($data, true);
        return is_array($timestamps) ? $timestamps : [];
    }

    private function saveTimestamps(string $key, array $timestamps): void
    {
        $file = $this->storagePath . '/' . $key . '.json';
        file_put_contents($file, json_encode(array_values($timestamps)), LOCK_EX);
    }
}
