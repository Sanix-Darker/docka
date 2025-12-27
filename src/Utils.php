<?php

declare(strict_types=1);

namespace App;

/**
 * Utility functions for Docka
 */
class Utils
{
    private static ?string $logFile = null;
    private static string $logLevel = 'INFO';

    private const LOG_LEVELS = [
        'DEBUG' => 0,
        'INFO'  => 1,
        'WARN'  => 2,
        'ERROR' => 3,
    ];

    /**
     * Initialize logging
     */
    public static function initLogging(string $logFile, string $level = 'INFO'): void
    {
        self::$logFile = $logFile;
        self::$logLevel = strtoupper($level);

        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Log a message
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        if (!self::$logFile) {
            return;
        }

        $level = strtoupper($level);
        if ((self::LOG_LEVELS[$level] ?? 0) < (self::LOG_LEVELS[self::$logLevel] ?? 0)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $line = "[$timestamp] [$level] $message$contextStr\n";

        // Rotate log if too large (10MB)
        if (is_file(self::$logFile) && filesize(self::$logFile) > 10485760) {
            rename(self::$logFile, self::$logFile . '.' . time());
        }

        file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Generate a RFC-4122 UUID v4
     */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    /**
     * Generate a short, URL-safe ID
     */
    public static function shortId(int $length = 12): string
    {
        // Use only lowercase alphanumeric (valid for Docker tags)
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $id = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $id .= $chars[ord($bytes[$i]) % strlen($chars)];
        }
        return $id;
    }

    /**
     * Run a shell command with timeout and proper escaping
     */
    public static function sh(
        string $cmd,
        ?string &$out = null,
        ?string $logFile = null,
        int $timeout = 600
    ): int {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Check if timeout command exists, otherwise run without it
        $hasTimeout = shell_exec('which timeout 2>/dev/null') !== null;

        if ($hasTimeout && $timeout > 0) {
            $wrappedCmd = sprintf('timeout %d bash -c %s', $timeout, escapeshellarg($cmd));
        } else {
            $wrappedCmd = $cmd;
        }

        $process = proc_open($wrappedCmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            $out = "Failed to execute command";
            self::log('ERROR', 'proc_open failed', ['cmd' => $cmd]);
            return 1;
        }

        fclose($pipes[0]);

        $out = '';
        $logHandle = $logFile ? fopen($logFile, 'ab') : null;

        // Non-blocking read with timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startTime = time();
        while (true) {
            $status = proc_get_status($process);

            // Read available output
            foreach ([1, 2] as $i) {
                while (($line = fgets($pipes[$i])) !== false) {
                    $out .= $line;
                    if ($logHandle) {
                        fwrite($logHandle, $line);
                        fflush($logHandle);
                    }
                }
            }

            if (!$status['running']) {
                break;
            }

            if (time() - $startTime > $timeout) {
                proc_terminate($process, 9);
                $out .= "\n[TIMEOUT after {$timeout}s]\n";
                if ($logHandle) {
                    fwrite($logHandle, "\n[TIMEOUT after {$timeout}s]\n");
                }
                break;
            }

            usleep(50000); // 50ms
        }

        if ($logHandle) {
            fclose($logHandle);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }

    /**
     * Validate a URL against security rules
     */
    public static function validateRepoUrl(string $url, array $config): array
    {
        $errors = [];

        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Invalid URL format';
            return $errors;
        }

        $parsed = parse_url($url);

        // Must be HTTPS
        if (($parsed['scheme'] ?? '') !== 'https') {
            $errors[] = 'Only HTTPS URLs are allowed';
        }

        // Check allowed hosts
        $allowedHosts = $config['allowed_hosts'] ?? [];
        if (!empty($allowedHosts)) {
            $host = $parsed['host'] ?? '';
            if (!in_array($host, $allowedHosts, true)) {
                $errors[] = 'Repository host not allowed. Allowed: ' . implode(', ', $allowedHosts);
            }
        }

        // Check blocked patterns
        $blockedPatterns = $config['blocked_url_patterns'] ?? [];
        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                $errors[] = 'URL contains blocked characters';
                break;
            }
        }

        // Check for suspicious paths
        if (preg_match('/\.(exe|bat|sh|ps1|cmd)$/i', $parsed['path'] ?? '')) {
            $errors[] = 'Direct script URLs not allowed';
        }

        return $errors;
    }

    /**
     * Sanitize a string for use as a filename/path component
     */
    public static function sanitizeId(string $input): string
    {
        return preg_replace('/[^a-zA-Z0-9.-]/', '', $input);
    }

    /**
     * Get client IP address (handles proxies)
     */
    public static function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Standard proxy
            'HTTP_X_REAL_IP',            // Nginx
            'REMOTE_ADDR',               // Direct
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Generate CSRF token - reuses existing valid token
     */
    public static function generateCsrfToken(int $ttl = 3600): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Reuse existing token if still valid
        $storedToken = $_SESSION['_csrf_token'] ?? '';
        $storedTime = $_SESSION['_csrf_time'] ?? 0;

        if (!empty($storedToken) && (time() - $storedTime) < $ttl) {
            return $storedToken;
        }

        // Generate new token
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_SESSION['_csrf_time'] = time();

        return $token;
    }

    /**
     * Validate CSRF token
     */
    public static function validateCsrfToken(string $token, int $ttl = 3600): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $storedToken = $_SESSION['_csrf_token'] ?? '';
        $storedTime = $_SESSION['_csrf_time'] ?? 0;

        if (empty($storedToken) || empty($token)) {
            return false;
        }

        if (time() - $storedTime > $ttl) {
            return false;
        }

        return hash_equals($storedToken, $token);
    }

    /**
     * Recursive directory removal
     */
    public static function rmrf(string $path): bool
    {
        if (!is_dir($path)) {
            return is_file($path) ? unlink($path) : false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        return rmdir($path);
    }

    /**
     * Format bytes to human readable
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Parse .env file content
     */
    public static function parseEnv(string $content): array
    {
        $result = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Remove surrounding quotes
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $m)) {
                $value = $m[2];
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
