<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Yaml\Yaml;

/**
 * Manages Docker build and run operations with security controls
 */
class DockerManager
{
    private string $id;
    private string $workDir;
    private int $ttl;
    private array $limits;
    private array $securityOpts;
    private string $fwChain;
    private ?string $sessionFlag;
    private ?string $envFile;
    private ?string $logFile;
    private array $portRange;

    private static ?bool $storageOptSupported = null;

    public function __construct(
        string $id,
        string $workDir,
        array $config,
        ?string $sessionFlag = null,
        ?string $envFile = null,
        ?string $logFile = null
    ) {
        $this->id = $id;
        $this->workDir = $workDir;
        $this->ttl = $config['container_ttl_seconds'] ?? 3600;
        $this->limits = $config['limits'] ?? [];
        $this->securityOpts = $config['docker_security'] ?? [];
        $this->fwChain = $config['firewall_chain'] ?? 'DOCKER-USER';
        $this->sessionFlag = $sessionFlag;
        $this->envFile = $envFile;
        $this->logFile = $logFile ?? $workDir . '/exec.log';
        $this->portRange = $config['port_range'] ?? ['min' => 32768, 'max' => 60999];
    }

    /**
     * Build and run containers
     */
    public function buildAndRun(array $buildInfo, ?callable $heartbeat = null): array
    {
        $this->writeLog("Starting build: {$buildInfo['type']}");

        if ($buildInfo['type'] === 'compose') {
            $result = $this->startCompose($buildInfo['path'], $heartbeat);
            $cids = $this->listComposeContainers();
        } else {
            $result = $this->startDockerfile($buildInfo['path'], $heartbeat);
            $cids = [$this->id];
        }

        if (empty($cids)) {
            throw new \RuntimeException('No containers started');
        }

        $this->applyLimits($cids);
        $this->openPorts($result['ports']);
        $this->scheduleCleanup($cids, $result['ports'], $buildInfo['type']);

        // Save metadata for stop.php
        $this->saveMeta($buildInfo['type'], $result['ports'], $cids);

        $this->writeLog("Build complete. Containers: " . implode(', ', $cids));

        return $result + ['containerIds' => $cids];
    }

    /**
     * Run a command with periodic heartbeat callbacks
     * Streams output to log file and calls heartbeat every few seconds
     */
    private function runWithHeartbeat(string $cmd, ?callable $heartbeat, int $timeout = 600): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            return -1;
        }

        fclose($pipes[0]); // Close stdin

        // Set non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $startTime = time();
        $lastHeartbeat = time();
        $output = '';

        while (true) {
            $status = proc_get_status($process);

            // Read available output
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            if ($stdout) {
                $output .= $stdout;
                $this->writeLog(trim($stdout));
            }
            if ($stderr) {
                $output .= $stderr;
                $this->writeLog(trim($stderr));
            }

            // Send heartbeat every 2 seconds
            if ($heartbeat && (time() - $lastHeartbeat) >= 2) {
                $heartbeat();
                $lastHeartbeat = time();
            }

            // Check if process finished
            if (!$status['running']) {
                break;
            }

            // Check timeout
            if ((time() - $startTime) > $timeout) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return -1;
            }

            // Small sleep to avoid busy loop
            usleep(100000); // 100ms
        }

        // Read any remaining output
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        if ($stdout) $this->writeLog(trim($stdout));
        if ($stderr) $this->writeLog(trim($stderr));

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return $exitCode;
    }

    /**
     * Build security flags for docker run
     */
    private function getSecurityFlags(): string
    {
        $flags = [];

        // No new privileges
        if ($this->securityOpts['no_new_privileges'] ?? true) {
            $flags[] = '--security-opt=no-new-privileges:true';
        }

        // Read-only root filesystem
        if ($this->securityOpts['read_only_root'] ?? false) {
            $flags[] = '--read-only';
            $flags[] = '--tmpfs /tmp:rw,noexec,nosuid,size=100m';
        }

        // Drop capabilities
        $dropCaps = $this->securityOpts['drop_capabilities'] ?? ['ALL'];
        foreach ($dropCaps as $cap) {
            $flags[] = '--cap-drop=' . escapeshellarg($cap);
        }

        // Add specific capabilities
        $addCaps = $this->securityOpts['add_capabilities'] ?? [];
        foreach ($addCaps as $cap) {
            $flags[] = '--cap-add=' . escapeshellarg($cap);
        }

        // PID limit
        if (!empty($this->limits['pids'])) {
            $flags[] = '--pids-limit=' . (int) $this->limits['pids'];
        }

        return implode(' ', $flags);
    }

    /**
     * Apply resource limits to running containers
     */
    private function applyLimits(array $cids): void
    {
        $flags = [];

        if (!empty($this->limits['memory'])) {
            $flags[] = '--memory=' . escapeshellarg($this->limits['memory']);
            $flags[] = '--memory-swap=' . escapeshellarg($this->limits['memory']); // No swap
        }

        if (!empty($this->limits['cpus'])) {
            $flags[] = '--cpus=' . escapeshellarg((string) $this->limits['cpus']);
        }

        if (!$flags) {
            return;
        }

        $flagStr = implode(' ', $flags);
        foreach ($cids as $cid) {
            $cmd = sprintf('docker update %s %s 2>&1', $flagStr, escapeshellarg($cid));
            Utils::sh($cmd, $out);
            if (str_contains($out, 'Error')) {
                Utils::log('WARN', 'Failed to apply limits', ['cid' => $cid, 'output' => $out]);
            }
        }
    }

    /**
     * Start containers from docker-compose file
     */
    private function startCompose(string $composePath, ?callable $heartbeat = null): array
    {
        $dirPath = dirname($composePath);
        $project = $this->id;

        // Patch ports to avoid conflicts
        $patchedPath = $this->patchComposePorts($composePath);

        // Apply security constraints to compose file
        $patchedPath = $this->patchComposeSecurity($patchedPath);

        $this->writeLog("Pulling images...");
        if ($heartbeat) $heartbeat();

        // Pull images (with timeout)
        $cmd = sprintf(
            'cd %s && COMPOSE_HTTP_TIMEOUT=300 docker compose -p %s -f %s pull --quiet 2>&1',
            escapeshellarg($dirPath),
            escapeshellarg($project),
            escapeshellarg($patchedPath)
        );
        $this->runWithHeartbeat($cmd, $heartbeat, 300);

        $this->writeLog("Building and starting containers...");
        if ($heartbeat) $heartbeat();

        // Build and start
        $cmd = sprintf(
            'cd %s && COMPOSE_HTTP_TIMEOUT=600 docker compose -p %s -f %s up -d --build --remove-orphans 2>&1',
            escapeshellarg($dirPath),
            escapeshellarg($project),
            escapeshellarg($patchedPath)
        );
        $this->runWithHeartbeat($cmd, $heartbeat, 600);

        // Wait for containers to be ready
        $this->waitForContainers($project);
        if ($heartbeat) $heartbeat();

        // Brief delay to ensure ports are fully assigned
        sleep(2);

        $this->writeLog("Collecting port mappings for project: $project");

        // Collect port mappings
        $ports = $this->collectComposePorts($project);

        if (empty($ports)) {
            $this->writeLog("Warning: No ports collected for compose project");
        }

        return ['ports' => $ports];
    }

    /**
     * Wait for compose containers to be running
     */
    private function waitForContainers(string $project, int $timeout = 120): void
    {
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            $cmd = sprintf(
                'docker compose -p %s ps --format "{{.State}}|{{.Health}}" 2>/dev/null',
                escapeshellarg($project)
            );
            Utils::sh($cmd, $out);

            $lines = array_filter(explode("\n", trim($out)));
            if (empty($lines)) {
                sleep(2);
                continue;
            }

            $allReady = true;
            foreach ($lines as $line) {
                $parts = explode('|', $line);
                $state = $parts[0] ?? '';
                $health = $parts[1] ?? '';

                if ($state !== 'running') {
                    $allReady = false;
                    break;
                }

                if ($health && !in_array($health, ['healthy', '-', ''], true)) {
                    $allReady = false;
                    break;
                }
            }

            if ($allReady) {
                $this->writeLog("All containers running");
                return;
            }

            sleep(2);
        }

        $this->writeLog("Warning: Timeout waiting for containers");
    }

    /**
     * Collect port mappings from compose project
     */
    private function collectComposePorts(string $project): array
    {
        // Method 1: Try docker compose ps
        $cmd = sprintf(
            'docker compose -p %s ps --format "{{.Name}}|{{.Publishers}}" 2>&1',
            escapeshellarg($project)
        );
        Utils::sh($cmd, $out);
        $this->writeLog("Compose ps output: " . trim($out));

        $ports = [];
        foreach (array_filter(explode("\n", trim($out))) as $line) {
            $parts = explode('|', $line, 2);
            if (count($parts) !== 2) continue;

            [$service, $publishers] = $parts;

            // Match patterns like: 0.0.0.0:32768->80/tcp or :::32768->80/tcp
            foreach (preg_split('/,\s*/', $publishers) as $chunk) {
                if (preg_match('/(\d+)->(\d+)/', $chunk, $m)) {
                    $ports[] = [
                        'service' => $service,
                        'hostPort' => (int) $m[1],
                        'containerPort' => (int) $m[2],
                    ];
                }
            }
        }

        // Method 2: Fallback - use docker ps to get ports for project containers
        if (empty($ports)) {
            $this->writeLog("No ports from compose ps, trying docker ps fallback...");

            $cmd = sprintf(
                'docker ps --filter "label=com.docker.compose.project=%s" --format "{{.Names}}|{{.Ports}}" 2>&1',
                escapeshellarg($project)
            );
            Utils::sh($cmd, $out);
            $this->writeLog("Docker ps output: " . trim($out));

            foreach (array_filter(explode("\n", trim($out))) as $line) {
                $parts = explode('|', $line, 2);
                if (count($parts) !== 2) continue;

                [$service, $portStr] = $parts;

                // Match patterns like: 0.0.0.0:32768->80/tcp
                if (preg_match_all('/(\d+)->(\d+)/', $portStr, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $ports[] = [
                            'service' => $service,
                            'hostPort' => (int) $m[1],
                            'containerPort' => (int) $m[2],
                        ];
                    }
                }
            }
        }

        // Method 3: Last resort - use docker port for each container
        if (empty($ports)) {
            $this->writeLog("Trying docker port fallback...");
            $cids = $this->listComposeContainers();

            foreach ($cids as $cid) {
                $cmd = sprintf('docker port %s 2>&1', escapeshellarg($cid));
                Utils::sh($cmd, $out);
                $this->writeLog("Docker port for $cid: " . trim($out));

                // Get container name
                $nameCmd = sprintf('docker inspect --format "{{.Name}}" %s 2>/dev/null', escapeshellarg($cid));
                Utils::sh($nameCmd, $name);
                $serviceName = ltrim(trim($name), '/');

                // Parse output like: 80/tcp -> 0.0.0.0:32768
                foreach (explode("\n", trim($out)) as $line) {
                    if (preg_match('/(\d+)\/\w+\s*->\s*[\d.:]+:(\d+)/', $line, $m)) {
                        $ports[] = [
                            'service' => $serviceName,
                            'hostPort' => (int) $m[2],
                            'containerPort' => (int) $m[1],
                        ];
                    }
                }
            }
        }

        $this->writeLog("Collected ports: " . json_encode($ports));

        return $ports;
    }

    /**
     * Patch compose file to use available ports
     */
    private function patchComposePorts(string $composePath): string
    {
        $data = Yaml::parseFile($composePath);
        if (!isset($data['services'])) {
            $this->writeLog("Warning: No services found in compose file");
            return $composePath;
        }

        $this->writeLog("Services found: " . implode(', ', array_keys($data['services'])));

        $changed = false;

        foreach ($data['services'] as $svcName => &$svc) {
            if (!isset($svc['ports'])) {
                $this->writeLog("Service $svcName has no ports defined");
                continue;
            }

            $this->writeLog("Service $svcName ports: " . json_encode($svc['ports']));

            $newPorts = [];
            foreach ($svc['ports'] as $mapping) {
                $newPort = $this->normalizePortMapping($mapping, $svcName, $changed);
                $newPorts[] = $newPort;
                $this->writeLog("Port mapping: $mapping -> $newPort");
            }
            $svc['ports'] = $newPorts;
        }
        unset($svc);

        if (!$changed) {
            $this->writeLog("No port changes needed");
            return $composePath;
        }

        $patchedPath = $this->workDir . '/_compose.patched.yml';
        file_put_contents($patchedPath, Yaml::dump($data, 99, 2));
        $this->writeLog("Patched compose file written to: $patchedPath");

        return $patchedPath;
    }

    /**
     * Normalize a port mapping and allocate free ports
     */
    private function normalizePortMapping($mapping, string $svcName, bool &$changed): string
    {
        // Handle different formats
        if (is_int($mapping) || is_numeric($mapping)) {
            // Just container port - let Docker assign host port
            return (string) $mapping;
        }

        $mapping = (string) $mapping;

        // Parse host:container or just container
        if (str_contains($mapping, ':')) {
            $parts = explode(':', $mapping, 2);
            $host = $parts[0];
            $container = $parts[1];

            // If host port specified and not available, get a free one
            if (is_numeric($host)) {
                $hostPort = (int) $host;
                if (!$this->isPortFree($hostPort)) {
                    $hostPort = $this->getFreePort();
                    $changed = true;
                }
                $this->patchEnvPort($svcName, $hostPort);
                return "$hostPort:$container";
            }
        }

        return $mapping;
    }

    /**
     * Patch compose file with security constraints
     */
    private function patchComposeSecurity(string $composePath): string
    {
        $data = Yaml::parseFile($composePath);
        if (!isset($data['services'])) {
            return $composePath;
        }

        foreach ($data['services'] as $svcName => &$svc) {
            // Force bridge network
            unset($svc['network_mode']);

            // Remove privileged mode
            unset($svc['privileged']);

            // Remove dangerous capabilities
            unset($svc['cap_add']);

            // Add resource limits if not present
            if (!isset($svc['deploy'])) {
                $svc['deploy'] = [];
            }
            if (!isset($svc['deploy']['resources'])) {
                $svc['deploy']['resources'] = [];
            }
            if (!isset($svc['deploy']['resources']['limits'])) {
                $svc['deploy']['resources']['limits'] = [
                    'memory' => $this->limits['memory'] ?? '512m',
                    'cpus' => (string) ($this->limits['cpus'] ?? '0.5'),
                ];
            }
        }
        unset($svc);

        $patchedPath = str_replace('.yml', '.secure.yml', $composePath);
        file_put_contents($patchedPath, Yaml::dump($data, 99, 2));

        return $patchedPath;
    }

    /**
     * Start container from Dockerfile
     */
    private function startDockerfile(string $dockerfilePath, ?callable $heartbeat = null): array
    {
        $dir = dirname($dockerfilePath);
        $tag = strtolower($this->id) . ':latest';

        $this->writeLog("Building image from Dockerfile...");

        // Build image with streaming output
        $buildCmd = sprintf(
            'docker build --no-cache -t %s -f %s %s 2>&1',
            escapeshellarg($tag),
            escapeshellarg($dockerfilePath),
            escapeshellarg($dir)
        );

        $exitCode = $this->runWithHeartbeat($buildCmd, $heartbeat, 600);
        if ($exitCode !== 0) {
            $this->writeLog("Docker build failed with exit code: $exitCode");
            Utils::log('ERROR', 'Docker build failed', [
                'exitCode' => $exitCode,
                'dockerfile' => $dockerfilePath,
            ]);
            throw new \RuntimeException('Docker build failed. Check logs for details.');
        }

        $this->writeLog("Starting container...");

        if ($heartbeat) $heartbeat();

        // Build run command
        $runParts = ['docker run -d'];

        // Publish all exposed ports
        $runParts[] = '-P';

        // Container name
        $runParts[] = '--name ' . escapeshellarg($this->id);

        // Resource limits
        if (!empty($this->limits['memory'])) {
            $runParts[] = '--memory=' . escapeshellarg($this->limits['memory']);
            $runParts[] = '--memory-swap=' . escapeshellarg($this->limits['memory']);
        }
        if (!empty($this->limits['cpus'])) {
            $runParts[] = '--cpus=' . escapeshellarg((string) $this->limits['cpus']);
        }

        // Security options
        $runParts[] = $this->getSecurityFlags();

        // Restart policy
        $runParts[] = '--restart=no';

        // Environment file
        if ($this->envFile && is_file($this->envFile)) {
            $runParts[] = '--env-file ' . escapeshellarg($this->envFile);
        }

        // Image
        $runParts[] = escapeshellarg($tag);

        $runCmd = implode(' ', array_filter($runParts)) . ' 2>&1';
        $exitCode = Utils::sh($runCmd, $out, $this->logFile, 60);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Failed to start container: ' . trim($out));
        }

        // Wait for container to start
        sleep(2);

        // Get port mappings
        $ports = $this->getContainerPorts($this->id);

        return ['ports' => $ports];
    }

    /**
     * Get port mappings for a container
     */
    private function getContainerPorts(string $containerId): array
    {
        $cmd = 'docker port ' . escapeshellarg($containerId) . ' 2>/dev/null';
        Utils::sh($cmd, $out);

        $ports = [];
        foreach (array_filter(explode("\n", trim($out))) as $line) {
            // Format: "80/tcp -> 0.0.0.0:32768"
            if (preg_match('/^(\d+)\/\w+\s*->\s*[\d.]+:(\d+)/', $line, $m)) {
                $ports[] = [
                    'service' => $containerId,
                    'hostPort' => (int) $m[2],
                    'containerPort' => (int) $m[1],
                ];
            }
        }

        return $ports;
    }

    /**
     * List containers in a compose project
     */
    private function listComposeContainers(): array
    {
        $cmd = sprintf('docker compose -p %s ps -q 2>/dev/null', escapeshellarg($this->id));
        Utils::sh($cmd, $out);

        return array_filter(explode("\n", trim($out)));
    }

    /**
     * Open firewall ports
     */
    private function openPorts(array $ports): void
    {
        foreach ($ports as $p) {
            $hostPort = (int) ($p['hostPort'] ?? 0);
            if ($hostPort <= 0) continue;

            $cmd = sprintf(
                'iptables -I %s -p tcp --dport %d -j ACCEPT 2>/dev/null',
                escapeshellarg($this->fwChain),
                $hostPort
            );
            shell_exec($cmd);
        }
    }

    /**
     * Schedule cleanup after TTL expires
     */
    private function scheduleCleanup(array $cids, array $ports, string $mode): void
    {
        $sec = $this->ttl;

        // Stop command
        $stopCmd = $mode === 'compose'
            ? 'docker compose -p ' . escapeshellarg($this->id) . ' down -v --remove-orphans'
            : 'docker rm -fv ' . escapeshellarg($this->id);

        // Firewall cleanup
        $fwCmds = '';
        foreach ($ports as $p) {
            $fwCmds .= sprintf(
                'iptables -D %s -p tcp --dport %d -j ACCEPT 2>/dev/null;',
                escapeshellarg($this->fwChain),
                (int) ($p['hostPort'] ?? 0)
            );
        }

        // Session flag cleanup
        $flagCmd = $this->sessionFlag ? 'rm -f ' . escapeshellarg($this->sessionFlag) : '';

        // Directory cleanup
        $dirCmd = 'rm -rf ' . escapeshellarg($this->workDir);

        $fullCmd = sprintf(
            '(sleep %d && %s; %s %s; %s) >/dev/null 2>&1 &',
            $sec,
            $stopCmd,
            $fwCmds,
            $flagCmd,
            $dirCmd
        );

        shell_exec($fullCmd);

        $this->writeLog("Cleanup scheduled in {$sec}s");
    }

    /**
     * Save metadata for stop.php
     */
    private function saveMeta(string $mode, array $ports, array $cids): void
    {
        $meta = [
            'mode' => $mode,
            'ports' => $ports,
            'containerIds' => $cids,
            'created' => time(),
            'ttl' => $this->ttl,
        ];

        file_put_contents($this->workDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));
    }

    /**
     * Check if a port is free
     */
    private function isPortFree(int $port): bool
    {
        if ($port < $this->portRange['min'] || $port > $this->portRange['max']) {
            return false;
        }

        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$socket) return false;

        $result = @socket_bind($socket, '0.0.0.0', $port);
        socket_close($socket);

        return $result !== false;
    }

    /**
     * Get a free port
     */
    private function getFreePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '0.0.0.0', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }

    /**
     * Update .env file with port mapping
     */
    private function patchEnvPort(string $svc, int $hostPort): void
    {
        if (!$this->envFile) return;

        $key = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $svc)) . '_PORT';

        $lines = [];
        if (is_file($this->envFile)) {
            $lines = file($this->envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }

        $found = false;
        foreach ($lines as &$line) {
            if (str_starts_with($line, "$key=")) {
                $line = "$key=$hostPort";
                $found = true;
                break;
            }
        }
        unset($line);

        if (!$found) {
            $lines[] = "$key=$hostPort";
        }

        file_put_contents($this->envFile, implode("\n", $lines) . "\n");
    }

    /**
     * Write to log file
     */
    private function writeLog(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] $message\n";
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
