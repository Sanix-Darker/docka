<?php

namespace App;

class RepositoryManager
{
    private string $workDir;
    private string $repoUrl;
    private ?string $ref = null;

    public function __construct(
        string $repoUrl,
        string $workDir,
        ?string $ref = null
    ) {
        $this->repoUrl = $repoUrl;
        $this->workDir = $workDir;
        $this->ref     = $ref;
    }

    public function clone(): void
    {
        $cmd = [
            'git', 'clone',
            '--depth', '1',
            $this->ref ? '--branch '.escapeshellarg($this->ref) : '',
            escapeshellarg($this->repoUrl),
            escapeshellarg($this->workDir)
        ];
        Utils::sh(implode(' ', array_filter($cmd)), $out);
        if (!is_dir($this->workDir)) throw new \RuntimeException("Clone failed:\n$out");
    }

    public function locateBuildFile(): ?array
    {
        $compose = $this->recursiveFind('docker-compose.yml');
        if ($compose) {
            return ['type' => 'compose', 'path' => $compose];
        }
        $dockerfile = $this->recursiveFind('Dockerfile');
        if ($dockerfile) {
            return ['type' => 'dockerfile', 'path' => $dockerfile];
        }
        return null;
    }

    private function recursiveFind(string $name): ?string
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workDir)
        );
        foreach ($rii as $file) {
            if ($file->getFilename() === $name) {
                return $file->getPathname();
            }
        }
        return null;
    }
}
