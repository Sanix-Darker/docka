<?php

namespace App;

class Sandbox
{
    private string $id;
    private string $root;
    private string $workDir;
    private RepositoryManager $repo;
    private DockerManager $docker;

    public function __construct(string $repoUrl, array $cfg)
    {
        $this->id      = Utils::uuid();
        $this->root    = rtrim($cfg['build_root'], '/');
        $this->workDir = $this->root . '/' . $this->id;
        $this->repo    = new RepositoryManager($repoUrl, $this->workDir);
        $this->docker  = new DockerManager(
            $this->id,
            $this->workDir,
            $cfg['container_ttl_seconds'] ?? 3600, // 1heure max
            $cfg['limits'] ?? [],  // for specs per containers
            $cfg['firewall_chain'] ?? 'DOCKER-USER' // still not sure on using INPUT instead
        );
    }

    public function run(): array
    {
        $this->repo->clone();
        $buildInfo = $this->repo->locateBuildFile();

        if (!$buildInfo) {
            throw new \RuntimeException('No Dockerfile nor docker-compose.yml found !');
        }
        return $this->docker->buildAndRun($buildInfo);
    }

    /** simple GC run manually */
    public static function cleanup(string $root, int $ttlMinutes): void
    {
        foreach (glob("$root/*") as $dir) {
            if (!is_dir($dir)) continue;
            if (filemtime($dir) < time() - $ttlMinutes * 60) {
                shell_exec("rm -rf " . escapeshellarg($dir));
            }
        }
    }
}
