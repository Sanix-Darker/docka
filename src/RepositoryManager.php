<?php

declare(strict_types=1);

namespace App;

/**
 * Manages git repository operations with security validations
 */
class RepositoryManager
{
    private string $workDir;
    private string $repoUrl;
    private ?string $ref;
    private array $config;

    public function __construct(
        string $repoUrl,
        string $workDir,
        array $config,
        ?string $ref = null
    ) {
        $this->repoUrl = $repoUrl;
        $this->workDir = $workDir;
        $this->config = $config;
        $this->ref = $this->sanitizeRef($ref);
    }

    /**
     * Sanitize git ref (branch/tag name)
     */
    private function sanitizeRef(?string $ref): ?string
    {
        if ($ref === null || trim($ref) === '') {
            return null;
        }

        // Only allow safe characters in refs
        $ref = trim($ref);
        if (!preg_match('/^[a-zA-Z0-9._\/-]+$/', $ref)) {
            throw new \InvalidArgumentException('Invalid git ref format');
        }

        // Block dangerous refs
        $blocked = ['--', ';', '&', '|', '$', '`', '>', '<', '..'];
        foreach ($blocked as $pattern) {
            if (str_contains($ref, $pattern)) {
                throw new \InvalidArgumentException('Invalid characters in git ref');
            }
        }

        return $ref;
    }

    /**
     * Clone the repository with security measures
     */
    public function clone(): void
    {
        Utils::log('INFO', 'Cloning repository', [
            'url' => $this->repoUrl,
            'ref' => $this->ref,
            'workDir' => $this->workDir,
        ]);

        // Ensure work directory doesn't exist yet
        if (is_dir($this->workDir)) {
            throw new \RuntimeException('Work directory already exists');
        }

        // Create parent directory
        $parent = dirname($this->workDir);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        // Build clone command with security options
        $timeout = $this->config['git_timeout_seconds'] ?? 120;

        $cmd = sprintf(
            'GIT_TERMINAL_PROMPT=0 timeout %d git clone --depth 1 --single-branch',
            $timeout
        );

        if ($this->ref) {
            $cmd .= ' --branch ' . escapeshellarg($this->ref);
        }

        $cmd .= sprintf(
            ' %s %s 2>&1',
            escapeshellarg($this->repoUrl),
            escapeshellarg($this->workDir)
        );

        Utils::sh($cmd, $out, null, $timeout + 10);

        if (!is_dir($this->workDir)) {
            Utils::log('ERROR', 'Clone failed', ['output' => $out]);
            throw new \RuntimeException("Clone failed: " . $this->truncateOutput($out));
        }

        // Security: Remove .git directory to save space and prevent issues
        $gitDir = $this->workDir . '/.git';
        if (is_dir($gitDir)) {
            Utils::rmrf($gitDir);
        }

        // Validate cloned content
        $this->validateClonedContent();

        Utils::log('INFO', 'Clone successful', ['workDir' => $this->workDir]);
    }

    /**
     * Validate cloned repository content for security
     */
    private function validateClonedContent(): void
    {
        // Check for suspiciously large repos
        $size = $this->getDirectorySize($this->workDir);
        $maxSize = 500 * 1024 * 1024; // 500MB

        if ($size > $maxSize) {
            Utils::rmrf($this->workDir);
            throw new \RuntimeException('Repository too large (max 500MB)');
        }

        // Check for dangerous symlinks
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (is_link($file->getPathname())) {
                $target = readlink($file->getPathname());
                // Block absolute symlinks and parent traversal
                if (str_starts_with($target, '/') || str_contains($target, '..')) {
                    Utils::rmrf($this->workDir);
                    throw new \RuntimeException('Repository contains dangerous symlinks');
                }
            }
        }
    }

    /**
     * Get directory size recursively
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Locate Docker build file (compose or Dockerfile)
     */
    public function locateBuildFile(): ?array
    {
        // Priority order for compose files
        $composeNames = [
            'docker-compose.yml',
            'docker-compose.yaml',
            'compose.yml',
            'compose.yaml',
        ];

        // First check root directory
        foreach ($composeNames as $name) {
            $path = $this->workDir . '/' . $name;
            if (is_file($path)) {
                $this->validateComposeFile($path);
                return ['type' => 'compose', 'path' => $path];
            }
        }

        $rootDockerfile = $this->workDir . '/Dockerfile';
        if (is_file($rootDockerfile)) {
            $this->validateDockerfile($rootDockerfile);
            return ['type' => 'dockerfile', 'path' => $rootDockerfile];
        }

        // Recursive search (max 3 levels deep)
        $found = $this->recursiveFind($composeNames, 3);
        if ($found) {
            $this->validateComposeFile($found);
            return ['type' => 'compose', 'path' => $found];
        }

        $found = $this->recursiveFind(['Dockerfile'], 3);
        if ($found) {
            $this->validateDockerfile($found);
            return ['type' => 'dockerfile', 'path' => $found];
        }

        return null;
    }

    /**
     * Validate Dockerfile for security issues
     */
    private function validateDockerfile(string $path): void
    {
        $content = file_get_contents($path);
        $blockedPatterns = $this->config['blocked_dockerfile_patterns'] ?? [];

        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new \RuntimeException('Dockerfile contains blocked instructions');
            }
        }

        // Check for suspicious base images
        if (preg_match('/FROM\s+.*(:latest|scratch)\s*$/mi', $content)) {
            Utils::log('WARN', 'Dockerfile uses :latest or scratch base image', ['path' => $path]);
        }
    }

    /**
     * Validate compose file for security issues
     */
    private function validateComposeFile(string $path): void
    {
        $content = file_get_contents($path);

        // Check for privileged mode
        if (preg_match('/privileged\s*:\s*true/i', $content)) {
            throw new \RuntimeException('Compose file requests privileged mode (not allowed)');
        }

        // Check for host network
        if (preg_match('/network_mode\s*:\s*["\']?host["\']?/i', $content)) {
            throw new \RuntimeException('Compose file requests host network (not allowed)');
        }

        // Check for dangerous volume mounts
        $dangerousMounts = ['/var/run/docker.sock', '/etc', '/root', '/home'];
        foreach ($dangerousMounts as $mount) {
            if (str_contains($content, $mount)) {
                Utils::log('WARN', 'Compose file mounts sensitive path', ['path' => $mount]);
            }
        }
    }

    /**
     * Recursive file search with depth limit
     */
    private function recursiveFind(array $names, int $maxDepth): ?string
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $iterator->setMaxDepth($maxDepth);

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getFilename(), $names, true)) {
                return $file->getPathname();
            }
        }

        return null;
    }

    /**
     * Truncate output for error messages
     */
    private function truncateOutput(string $output, int $maxLength = 500): string
    {
        $output = trim($output);
        if (strlen($output) > $maxLength) {
            return substr($output, 0, $maxLength) . '...';
        }
        return $output;
    }

    /**
     * Get repository info
     */
    public function getInfo(): array
    {
        return [
            'url' => $this->repoUrl,
            'ref' => $this->ref,
            'workDir' => $this->workDir,
        ];
    }
}
