<?php

namespace App;

class Sandbox
{
    private string $id;
    private string $root;
    private string $workDir;
    private string $sessionId;
    private string $sessionFile;
    private array  $cfg;
    private RepositoryManager $repo;
    private DockerManager     $docker;

    public function __construct(string $repoUrl, array $cfg, string $sessionId)
    {
        $this->cfg       = $cfg;
        $this->sessionId = preg_replace('/[^a-zA-Z0-9]/', '_', $sessionId);
        $this->id        = Utils::uuid();
        $this->root      = rtrim($cfg['build_root'], '/');
        $this->workDir   = $this->root . '/' . $this->id;

        $sesDir = $this->root . '/sessions/' . $this->sessionId;
        if (!is_dir($sesDir)) mkdir($sesDir, 0777, true);
        $active = glob("$sesDir/*");
        $max    = $cfg['max_per_session'] ?? 3;
        if (count($active) >= $max) {
            throw new \RuntimeException("Limit reached: max $max concurrent sandboxes for this session.");
        }
        $this->sessionFile = $sesDir . '/' . $this->id . '.flag';

        $this->repo   = new RepositoryManager($repoUrl, $this->workDir);
        $this->docker = new DockerManager(
            $this->id,
            $this->workDir,
            $cfg['container_ttl_seconds'] ?? 3600,
            $cfg['limits']               ?? [],
            $cfg['firewall_chain']       ?? 'DOCKER-USER',
            $this->sessionFile
        );
    }

    public function run(): array
    {
        $this->repo->clone();
        $buildInfo = $this->repo->locateBuildFile();
        if (!$buildInfo) throw new \RuntimeException('No Dockerfile nor docker-compose.yml found');

        $out = $this->docker->buildAndRun($buildInfo);

        // mark this sandbox as “running” for the session
        file_put_contents($this->sessionFile, time());
        return $out;
    }

    /** my custom garbage collector for old workdirs – unchanged */
    public static function cleanup(string $root, int $ttlMinutes): void
    {
        foreach (glob("$root/*") as $dir) {
            if (!is_dir($dir) || basename($dir) === 'sessions') continue;
            if (filemtime($dir) < time() - $ttlMinutes * 60) {
                shell_exec("rm -rf " . escapeshellarg($dir));
            }
        }
    }
}
