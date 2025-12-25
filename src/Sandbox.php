<?php

declare(strict_types=1);

namespace App;

/**
 * Represents a sandboxed build environment
 */
class Sandbox
{
    private string $id;
    private string $root;
    private string $workDir;
    private string $sessionId;
    private string $sessionFile;
    private string $logFile;
    private array $config;
    private RepositoryManager $repo;
    private DockerManager $docker;
    private ?string $envRaw;

    public function __construct(
        string $repoUrl,
        array $config,
        string $sessionId,
        ?string $ref = null,
        ?string $envRaw = null
    ) {
        $this->config = $config;
        $this->sessionId = Utils::sanitizeId($sessionId);
        $this->id = Utils::shortId(16);
        $this->root = rtrim($config['build_root'], '/');
        $this->workDir = $this->root . '/' . $this->id;
        $this->envRaw = $envRaw;

        // Initialize logging
        Utils::initLogging(
            $config['log_file'] ?? $this->root . '/docka.log',
            $config['logging']['level'] ?? 'INFO'
        );

        // Set up session directory
        $sesDir = $this->root . '/sessions/' . $this->sessionId;
        if (!is_dir($sesDir)) {
            mkdir($sesDir, 0755, true);
        }

        // Check session limits
        $this->checkSessionLimits($sesDir);

        $this->sessionFile = $sesDir . '/' . $this->id . '.flag';
        $this->logFile = $this->workDir . '/exec.log';

        // Initialize repository manager
        $this->repo = new RepositoryManager($repoUrl, $this->workDir, $config, $ref);

        // Initialize Docker manager
        $this->docker = new DockerManager(
            $this->id,
            $this->workDir,
            $config,
            $this->sessionFile,
            $this->workDir . '/.env',
            $this->logFile
        );

        Utils::log('INFO', 'Sandbox created', [
            'id' => $this->id,
            'session' => $this->sessionId,
            'repo' => $repoUrl,
        ]);
    }

    /**
     * Check if session has reached sandbox limit
     */
    private function checkSessionLimits(string $sesDir): void
    {
        $maxPerSession = $this->config['rate_limit']['max_per_session'] ?? 3;
        $activeFlags = glob("$sesDir/*.flag");

        // Clean up stale flags (older than 2x TTL)
        $maxAge = ($this->config['container_ttl_seconds'] ?? 3600) * 2;
        foreach ($activeFlags as $flag) {
            if (filemtime($flag) < time() - $maxAge) {
                @unlink($flag);
            }
        }

        $activeFlags = glob("$sesDir/*.flag");
        if (count($activeFlags) >= $maxPerSession) {
            throw new \RuntimeException(
                "Limit reached: max $maxPerSession concurrent sandboxes per session"
            );
        }
    }

    /**
     * Run the sandbox: clone, build, and start containers
     */
    public function run(): array
    {
        try {
            // Create work directory
            if (!mkdir($this->workDir, 0755, true)) {
                throw new \RuntimeException('Failed to create work directory');
            }

            // Initialize log file
            file_put_contents($this->logFile, "=== Build started at " . date('Y-m-d H:i:s') . " ===\n");

            // Clone repository
            $this->repo->clone();

            // Prepare environment file
            $this->prepareEnvFile();

            // Locate build file
            $buildInfo = $this->repo->locateBuildFile();
            if (!$buildInfo) {
                throw new \RuntimeException(
                    'No Dockerfile or docker-compose.yml found in repository'
                );
            }

            file_put_contents(
                $this->logFile,
                "Found {$buildInfo['type']}: {$buildInfo['path']}\n",
                FILE_APPEND
            );

            // Build and run containers
            $result = $this->docker->buildAndRun($buildInfo);

            // Mark sandbox as running
            file_put_contents($this->sessionFile, json_encode([
                'started' => time(),
                'id' => $this->id,
            ]));

            file_put_contents(
                $this->logFile,
                "=== Build completed successfully ===\n",
                FILE_APPEND
            );

            Utils::log('INFO', 'Sandbox started', [
                'id' => $this->id,
                'ports' => $result['ports'],
                'containers' => $result['containerIds'],
            ]);

            return $result;

        } catch (\Throwable $e) {
            Utils::log('ERROR', 'Sandbox failed', [
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            file_put_contents(
                $this->logFile,
                "=== ERROR: {$e->getMessage()} ===\n",
                FILE_APPEND
            );

            // Cleanup on failure
            $this->cleanup();

            throw $e;
        }
    }

    /**
     * Get sandbox ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get work directory
     */
    public function getWorkDir(): string
    {
        return $this->workDir;
    }

    /**
     * Prepare .env file from user input or template
     */
    private function prepareEnvFile(): void
    {
        $envPath = $this->workDir . '/.env';

        // User-provided env content takes priority
        if ($this->envRaw && trim($this->envRaw) !== '') {
            // Validate env content
            $this->validateEnvContent($this->envRaw);
            file_put_contents($envPath, $this->envRaw);
            return;
        }

        // If .env exists, use it
        if (is_file($envPath)) {
            return;
        }

        // Try to copy from template files
        $templates = ['.env.example', '.env-example', '.env.sample', '.env.dist'];
        foreach ($templates as $tpl) {
            $src = $this->workDir . '/' . $tpl;
            if (is_file($src)) {
                copy($src, $envPath);
                return;
            }
        }
    }

    /**
     * Validate .env content for security
     */
    private function validateEnvContent(string $content): void
    {
        // Check for command injection attempts
        $dangerous = ['$(', '`', '${', '&&', '||', ';', '|'];
        foreach ($dangerous as $pattern) {
            if (str_contains($content, $pattern)) {
                throw new \InvalidArgumentException(
                    'Environment content contains potentially dangerous characters'
                );
            }
        }

        // Limit size
        if (strlen($content) > 65536) {
            throw new \InvalidArgumentException('Environment content too large (max 64KB)');
        }
    }

    /**
     * Cleanup sandbox resources
     */
    private function cleanup(): void
    {
        // Remove session flag
        if ($this->sessionFile && is_file($this->sessionFile)) {
            @unlink($this->sessionFile);
        }

        // Remove work directory (after a delay to allow log viewing)
        // This is handled by the scheduled cleanup in DockerManager
    }

    /**
     * Static cleanup for old sandboxes
     */
    public static function cleanupOld(string $root, int $ttlMinutes): void
    {
        $cutoff = time() - ($ttlMinutes * 60);

        // Clean up sandbox directories
        foreach (glob("$root/*") as $dir) {
            if (!is_dir($dir)) continue;

            $basename = basename($dir);
            if ($basename === 'sessions' || $basename === '.ratelimit') continue;

            // Check if directory is old enough
            if (filemtime($dir) < $cutoff) {
                // Try to stop any running containers
                $metaFile = "$dir/meta.json";
                if (is_file($metaFile)) {
                    $meta = json_decode(file_get_contents($metaFile), true);
                    $sandboxId = $basename;

                    if (($meta['mode'] ?? '') === 'compose') {
                        shell_exec("docker compose -p " . escapeshellarg($sandboxId) . " down -v --remove-orphans 2>/dev/null");
                    } else {
                        shell_exec("docker rm -fv " . escapeshellarg($sandboxId) . " 2>/dev/null");
                    }
                }

                // Remove directory
                Utils::rmrf($dir);
                Utils::log('INFO', 'Cleaned up old sandbox', ['dir' => $dir]);
            }
        }

        // Clean up stale session flags
        foreach (glob("$root/sessions/*") as $sesDir) {
            if (!is_dir($sesDir)) continue;

            foreach (glob("$sesDir/*.flag") as $flag) {
                if (filemtime($flag) < $cutoff) {
                    @unlink($flag);
                }
            }

            // Remove empty session directories
            $files = glob("$sesDir/*");
            if (empty($files)) {
                @rmdir($sesDir);
            }
        }
    }

    /**
     * Stop a running sandbox
     */
    public static function stop(string $sandboxId, array $config): bool
    {
        $root = rtrim($config['build_root'], '/');
        $sandboxId = Utils::sanitizeId($sandboxId);

        if (empty($sandboxId)) {
            return false;
        }

        $metaFile = "$root/$sandboxId/meta.json";
        $meta = is_file($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];

        $mode = $meta['mode'] ?? 'single';
        $ports = $meta['ports'] ?? [];

        // Stop containers
        if ($mode === 'compose') {
            shell_exec('docker compose -p ' . escapeshellarg($sandboxId) . ' down -v --remove-orphans 2>/dev/null');
        } else {
            shell_exec('docker rm -fv ' . escapeshellarg($sandboxId) . ' 2>/dev/null');
        }

        // Close firewall ports
        $fwChain = $config['firewall_chain'] ?? 'DOCKER-USER';
        foreach ($ports as $p) {
            $hostPort = (int) ($p['hostPort'] ?? 0);
            if ($hostPort > 0) {
                shell_exec(sprintf(
                    'iptables -D %s -p tcp --dport %d -j ACCEPT 2>/dev/null',
                    escapeshellarg($fwChain),
                    $hostPort
                ));
            }
        }

        // Remove session flag
        $flagPattern = "$root/sessions/*/$sandboxId.flag";
        foreach (glob($flagPattern) as $flag) {
            @unlink($flag);
        }

        Utils::log('INFO', 'Sandbox stopped', ['id' => $sandboxId]);

        return true;
    }
}
