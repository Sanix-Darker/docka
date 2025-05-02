<?php
namespace App;

class DockerManager
{
    private string $id;
    private string $workDir;
    private int    $ttl;
    private array  $limits;        // ['memory'=>'…','cpus'=>'…','storage'=>'…']

    public function __construct(string $id, string $workDir, int $ttl = 3600, array $limits = [])
    {
        $this->id     = $id;
        $this->workDir= $workDir;
        $this->ttl    = $ttl;
        $this->limits = $limits;
    }

    public function buildAndRun(array $buildInfo): array
    {
        if ($buildInfo['type'] === 'compose') {
            $result = $this->startCompose($buildInfo['path']);
            $cids   = $this->listComposeContainers();
            $this->applyLimits($cids);
            $this->scheduleCleanup('compose');
        } else {
            $result = $this->startDockerfile($buildInfo['path']);
            $this->applyLimits([$this->id]);
            $this->scheduleCleanup('single');
        }
        return $result;
    }

    /** Return container‑IDs of the current compose project */
    private function listComposeContainers(): array
    {
        Utils::sh(
            sprintf('docker compose -p %s ps -q', escapeshellarg($this->id)),
            $out
        );
        return array_filter(explode("\n", trim($out)));
    }

    /** docker update --memory … --cpus … --storage-opt size=… */
    private function applyLimits(array $containerIds): void
    {
        $flags = [];
        if (!empty($this->limits['memory']))  $flags[] = '--memory='.escapeshellarg($this->limits['memory']);
        if (!empty($this->limits['cpus']))    $flags[] = '--cpus='.escapeshellarg($this->limits['cpus']);
        if (!empty($this->limits['storage'])) $flags[] = '--storage-opt size='.escapeshellarg($this->limits['storage']);

        if (!$flags) return;   // nothing to apply

        foreach ($containerIds as $cid) {
            $cmd = sprintf('docker update %s %s 2>&1',
                implode(' ', $flags),
                escapeshellarg($cid)
            );
            shell_exec($cmd);
        }
    }

    private function startCompose(string $composePath): array
    {
        Utils::sh(sprintf(
            'cd %s && docker compose -p %s -f %s up -d --build 2>&1',
            escapeshellarg(dirname($composePath)),
            escapeshellarg($this->id),
            escapeshellarg($composePath)
        ), $log);

        Utils::sh(sprintf(
            'docker compose -p %s ps --format "{{.Name}}|{{.Publishers}}"',
            escapeshellarg($this->id)
        ), $portsRaw);

        $ports=[];
        foreach (explode("\n", trim($portsRaw)) as $line) {
            if (!$line) continue;
            [$svc,$pub]=explode('|',$line);
            if (preg_match('/:(\d+)->(\d+)/',$pub,$m))
                $ports[]=['service'=>$svc,'hostPort'=>$m[1],'containerPort'=>$m[2]];
        }
        return ['log'=>$log,'ports'=>$ports];
    }

    private function startDockerfile(string $dockerfilePath): array
    {
        $dir = dirname($dockerfilePath);
        $tag = $this->id . ':latest';

        Utils::sh(sprintf(
            'docker build -t %s -f %s %s 2>&1',
            escapeshellarg($tag),
            escapeshellarg($dockerfilePath),
            escapeshellarg($dir)
        ), $buildLog);

        Utils::sh(sprintf(
            'docker run -d -P --name %s %s 2>&1',
            escapeshellarg($this->id),
            escapeshellarg($tag)
        ), $runLog);

        Utils::sh(sprintf('docker port %s', escapeshellarg($this->id)), $portsRaw);

        $ports=[];
        foreach (explode("\n", trim($portsRaw)) as $line) {
            if (!$line) continue;
            [$cnPort,$host]=array_map('trim',explode('->',$line));
            [, $hostPort]=explode(':',$host);
            $ports[]=['service'=>$this->id,'hostPort'=>$hostPort,'containerPort'=>$cnPort];
        }
        return ['log'=>$buildLog."\n".$runLog,'ports'=>$ports];
    }

    // identical to previous version
    private function scheduleCleanup(string $mode): void
    {
        $sec = $this->ttl;
        $cmd = $mode === 'compose'
            ? "(sleep $sec && docker compose -p ".escapeshellarg($this->id)." down -v --remove-orphans) >/dev/null 2>&1 &"
            : "(sleep $sec && docker rm -fv ".escapeshellarg($this->id).") >/dev/null 2>&1 &";
        shell_exec($cmd);
    }
}
