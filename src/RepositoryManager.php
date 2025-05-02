<?php

namespace App;

class RepositoryManager
{
    private string $workDir;
    private string $repoUrl;

    public function __construct(string $repoUrl, string $workDir)
    {
        $this->repoUrl = $repoUrl;
        $this->workDir = $workDir;
    }

    public function clone(): void
    {
        Utils::sh(sprintf(
            'git clone --depth 1 %s %s 2>&1',
            escapeshellarg($this->repoUrl),
            escapeshellarg($this->workDir)
        ), $out);

        if (!is_dir($this->workDir)) {
            throw new \RuntimeException("Clone failed:\n$out");
        }
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
