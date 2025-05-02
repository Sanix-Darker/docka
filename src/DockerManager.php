<?php
namespace App;

class DockerManager
{
    private string $id;
    private string $workDir;
    private int    $ttl;
    private array  $limits;
    private string $fwChain;

    public function __construct(
        string $id,
        string $workDir,
        int    $ttl    = 3600,
        array  $limits = [],
        string $fwChain = 'DOCKER-USER'
    ) {
        $this->id       = $id;
        $this->workDir  = $workDir;
        $this->ttl      = $ttl;
        $this->limits   = $limits;
        $this->fwChain  = $fwChain;
    }

    public function buildAndRun(array $buildInfo): array
    {
        if ($buildInfo['type'] === 'compose') {
            $result = $this->startCompose($buildInfo['path']);
            $cids   = $this->listComposeContainers();
        } else {
            $result = $this->startDockerfile($buildInfo['path']);
            $cids   = [$this->id];
        }

        $this->applyLimits($cids);
        $this->openPorts($result['ports']);
        $this->scheduleCleanup($cids, $result['ports']);

        return $result;
    }

    // for LIMITS
    private function applyLimits(array $cids): void
    {
        $flags = [];
        if (!empty($this->limits['memory']))  $flags[] = '--memory='.escapeshellarg($this->limits['memory']);
        if (!empty($this->limits['cpus']))    $flags[] = '--cpus='.escapeshellarg($this->limits['cpus']);
        if (!empty($this->limits['storage'])) $flags[] = '--storage-opt size='.escapeshellarg($this->limits['storage']);
        if (!$flags) return;

        foreach ($cids as $cid) {
            shell_exec(sprintf('docker update %s %s', implode(' ', $flags), escapeshellarg($cid)));
        }
    }

    // for FIREWALL HOLES
    private function openPorts(array $ports): void
    {
        foreach ($ports as $p) {
            $host = (int)$p['hostPort'];
            $cmd  = sprintf('iptables -I %s -p tcp --dport %d -j ACCEPT', escapeshellarg($this->fwChain), $host);
            shell_exec($cmd);
        }
    }

    private function closePorts(array $ports): void
    {
        foreach ($ports as $p) {
            $host = (int)$p['hostPort'];
            $cmd  = sprintf('iptables -D %s -p tcp --dport %d -j ACCEPT', escapeshellarg($this->fwChain), $host);
            shell_exec($cmd);
        }
    }

    // for CLEAN‑UP TIMER
    private function scheduleCleanup(array $cids, array $ports): void
    {
        $sec = $this->ttl;

        // 1) stop & remove containers / stacks
        if (count($cids) > 1) {   // compose
            $down = 'docker compose -p '.escapeshellarg($this->id).' down -v --remove-orphans';
        } else {                  // single
            $down = 'docker rm -fv '.escapeshellarg($this->id);
        }

        // 2) close firewall holes
        $fw  = '';
        foreach ($ports as $p) {
            $fw .= sprintf('iptables -D %s -p tcp --dport %d -j ACCEPT;',
                           escapeshellarg($this->fwChain), (int)$p['hostPort']);
        }

        // 3) spawn the sleeper
        $cmd = sprintf('(sleep %d && %s %s) >/dev/null 2>&1 &', $sec, $down, $fw);
        shell_exec($cmd);
    }

    // for BUILD HELPERS
    private function listComposeContainers(): array
    {
        Utils::sh('docker compose -p '.escapeshellarg($this->id).' ps -q', $out);
        return array_filter(explode("\n", trim($out)));
    }

    private function startCompose(string $file): array
    {
        Utils::sh(sprintf(
            'cd %s && docker compose -p %s -f %s up -d --build 2>&1',
            escapeshellarg(dirname($file)),
            escapeshellarg($this->id),
            escapeshellarg($file)
        ), $log);

        Utils::sh('docker compose -p '.escapeshellarg($this->id).' ps --format "{{.Name}}|{{.Publishers}}"', $portsRaw);

        $ports=[];
        foreach (explode("\n",trim($portsRaw)) as $line){
            if(!$line)continue;
            [$svc,$pub]=explode('|',$line);
            if(preg_match('/:(\d+)->(\d+)/',$pub,$m))
                $ports[]=['service'=>$svc,'hostPort'=>$m[1],'containerPort'=>$m[2]];
        }
        return ['log'=>$log,'ports'=>$ports];
    }

    private function startDockerfile(string $dockerfile): array
    {
        $dir = dirname($dockerfile);
        $tag = $this->id.':latest';

        Utils::sh(sprintf('docker build -t %s -f %s %s 2>&1',
            escapeshellarg($tag), escapeshellarg($dockerfile), escapeshellarg($dir)
        ), $buildLog);

        Utils::sh(sprintf('docker run -d -P --name %s %s 2>&1',
            escapeshellarg($this->id), escapeshellarg($tag)
        ), $runLog);

        Utils::sh('docker port '.escapeshellarg($this->id), $portsRaw);

        $ports=[];
        foreach (explode("\n",trim($portsRaw)) as $line){
            if(!$line)continue;
            [$cnPort,$host]=array_map('trim',explode('->',$line));
            [, $hostPort]=explode(':',$host);
            $ports[]=['service'=>$this->id,'hostPort'=>$hostPort,'containerPort'=>$cnPort];
        }
        return ['log'=>$buildLog."\n".$runLog,'ports'=>$ports];
    }
}
