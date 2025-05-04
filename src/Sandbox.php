<?php

namespace App;

class Sandbox
{
    private string $id;
    private string $root;
    private string $workDir;
    private string $sessionId;
    private string $sessionFile;
    private string $sessionLog;
    private array  $cfg;
    private RepositoryManager $repo;
    private DockerManager     $docker;

    private ?string $ref;
    private ?string $envRaw;

    public function __construct(
        string $repoUrl,
        array $cfg,
        string $sessionId,
        ?string $ref = null,
        ?string $envRaw = null,
    ) {
        $this->cfg       = $cfg;
        $this->sessionId = preg_replace('/[^a-zA-Z0-9]/', '_', $sessionId);
        $this->id        = Utils::uuid();
        $this->root      = rtrim($cfg['build_root'], '/');
        $this->workDir   = $this->root . '/' . $this->id;

        $sesDir = $this->root . '/sessions/' . $this->sessionId;
        if (!is_dir($sesDir)) mkdir($sesDir, 0777, true);

        $this->sessionLog = $sesDir . '/_general.log';
        if (!is_file($this->sessionLog)) file_put_contents($this->sessionLog, '');

        $active = glob("$sesDir/*");
        $max    = $cfg['max_per_session'] ?? 3;
        if (count($active) >= $max) {
            throw new \RuntimeException("Limit reached: max $max concurrent sandboxes for this session.");
        }
        $this->sessionFile = $sesDir . '/' . $this->id . '.flag';

        $this->envRaw = $envRaw;
        $this->repo   = new RepositoryManager($repoUrl, $this->workDir, $ref);
        $this->docker = new DockerManager(
            $this->id,
            $this->workDir,
            $cfg['container_ttl_seconds'] ?? 3600,
            $cfg['limits']               ?? [],
            $cfg['firewall_chain']       ?? 'DOCKER-USER',
            $this->sessionFile,
            $this->workDir.'/.env',
            $this->sessionLog
        );
    }

    public function run(): array
    {
        $this->repo->clone();
        $this->prepareEnvFile();
        $buildInfo = $this->repo->locateBuildFile();
        if (!$buildInfo) throw new \RuntimeException('No Dockerfile nor docker-compose.yml found');

        $out = $this->docker->buildAndRun($buildInfo);

        // mark this sandbox as “running” for the session
        file_put_contents($this->sessionFile, time());
        return $out;
    }

    public function getId(): string
    {
        return $this->id;
    }

    private function prepareEnvFile(): void
    {
        $envPath = $this->workDir.'/.env';

        if ($this->envRaw && trim($this->envRaw) !== '') {
            file_put_contents($envPath, $this->envRaw);
            return;
        }

        if (is_file($envPath)) return;

        foreach (['.env.example', '.env-example', '.env.sample'] as $tpl) {
            $src = $this->workDir.'/'.$tpl;
            if (is_file($src)) {
                copy($src, $envPath);
                return;
            }
        }
    }
    /** my custom garbage collector for old workdirs */
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
