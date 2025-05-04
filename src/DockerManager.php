<?php
namespace App;

use Symfony\Component\Yaml\Yaml;
use FilesystemIterator as FI;

/**
 * Handle building / running Docker or Docker‑Compose projects
 * with optional resource limits & firewall hole‑punching.
 */
class DockerManager
{
    private string  $id;
    private string  $workDir;
    private int     $ttl;
    private array   $limits;
    private string  $fwChain;
    private ?string $sessionFlag;
    private ?string $envFile;

    /* cache for storage‑driver detection */
    private static ?bool $storageOptSupported = null;

    public function __construct(
        string  $id,
        string  $workDir,
        int     $ttl         = 3600,
        array   $limits      = [],
        string  $fwChain     = 'DOCKER-USER',
        ?string $sessionFlag = null,
        ?string $envFile     = null
    ) {
        $this->id          = $id;
        $this->workDir     = $workDir;
        $this->ttl         = $ttl;
        $this->limits      = $limits;
        $this->fwChain     = $fwChain;
        $this->sessionFlag = $sessionFlag;
        $this->envFile     = $envFile;
    }

    /* ───────────────────────────────────────── core orchestration ───────────────────────────────────────── */

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

        /* metadata for /stop.php */
        file_put_contents($this->workDir . '/meta.json', json_encode([
            'mode'  => $buildInfo['type'],   // 'compose' | 'single'
            'ports' => $result['ports']
        ]));

        /* ensure caller receives container IDs */
        return $result + ['containerIds' => $cids];
    }

    /* ───────────────────────────────────────── resource limits ───────────────────────────────────────── */

    private function applyLimits(array $cids): void
    {
        $flags = [];

        if (!empty($this->limits['memory'])) {
            $flags[] = '--memory=' . escapeshellarg($this->limits['memory']);
        }

        if (!empty($this->limits['cpus'])) {
            $flags[] = '--cpus=' . escapeshellarg($this->limits['cpus']);
        }

        if (!empty($this->limits['storage']) && $this->storageOptIsSupported()) {
            $flags[] = '--storage-opt size=' . escapeshellarg($this->limits['storage']);
        }

        if (!$flags) {
            return;
        }

        foreach ($cids as $cid) {
            shell_exec(sprintf('docker update %s %s', implode(' ', $flags), escapeshellarg($cid)));
        }
    }

    /** Check once per PHP process whether the Docker storage‑driver supports `--storage-opt size=` */
    private function storageOptIsSupported(): bool
    {
        if (self::$storageOptSupported !== null) {
            return self::$storageOptSupported;
        }

        $driver = trim(shell_exec('docker info --format "{{.Driver}}" 2>/dev/null') ?: '');
        self::$storageOptSupported = in_array(
            $driver,
            ['overlay2', 'overlay', 'devicemapper', 'fuse-overlayfs'],
            true
        );

        return self::$storageOptSupported;
    }

    /* ───────────────────────────────────────── firewall helpers ───────────────────────────────────────── */

    private function openPorts(array $ports): void
    {
        foreach ($ports as $p) {
            $host = (int) $p['hostPort'];
            shell_exec(sprintf(
                'iptables -I %s -p tcp --dport %d -j ACCEPT',
                escapeshellarg($this->fwChain),
                $host
            ));
        }
    }

    private function closePorts(array $ports): void
    {
        foreach ($ports as $p) {
            $host = (int) $p['hostPort'];
            shell_exec(sprintf(
                'iptables -D %s -p tcp --dport %d -j ACCEPT',
                escapeshellarg($this->fwChain),
                $host
            ));
        }
    }

    /* ───────────────────────────────────────── cleanup timer ───────────────────────────────────────── */

    private function scheduleCleanup(array $cids, array $ports): void
    {
        $sec = $this->ttl;

        $downCmd = count($cids) > 1
            ? 'docker compose -p ' . escapeshellarg($this->id) . ' down -v --remove-orphans'
            : 'docker rm -fv ' . escapeshellarg($this->id);

        $fw  = '';
        foreach ($ports as $p) {
            $fw .= sprintf(
                'iptables -D %s -p tcp --dport %d -j ACCEPT;',
                escapeshellarg($this->fwChain),
                (int) $p['hostPort']
            );
        }

        $flag = $this->sessionFlag ? 'rm -f ' . escapeshellarg($this->sessionFlag) : '';

        $cmd = sprintf('(sleep %d && %s %s %s) >/dev/null 2>&1 &', $sec, $downCmd, $fw, $flag);
        shell_exec($cmd);
    }

    /* ───────────────────────────────────────── helpers (ports / compose) ───────────────────────────────────────── */
    private function cx(string $cmd): string
    {
        return 'COMPOSE_HTTP_TIMEOUT=1200 DOCKER_CLIENT_TIMEOUT=1200 ' . $cmd;
    }

    private function listComposeContainers(): array
    {
        Utils::sh('docker compose -p ' . escapeshellarg($this->id) . ' ps -q', $out);
        return array_filter(explode("\n", trim($out)));
    }

    private function patchComposePorts(string $in): string
    {
        $data = Yaml::parseFile($in);
        if (!isset($data['services'])) return $in;

        $changed = false;
        foreach ($data['services'] as $svcName => &$svc) {
            if (!isset($svc['ports'])) continue;
            $newPorts = [];
            foreach ($svc['ports'] as $map) {
                if (is_int($map) || is_numeric($map)) {   // format: "80"
                    $newPorts[] = $map;                   // random host port
                    continue;
                }
                [$host,$cont] = explode(':',$map,2);
                if (!$this->isPortFree((int)$host)) {
                    $host = $this->getFreePort();
                    $changed = true;
                }
                $newPorts[] = "$host:$cont";

                /* propagate to .env:   PORT_db=54321  etc. */
                $this->patchEnvPort($svcName, $host);
            }
            $svc['ports'] = $newPorts;
        }
        unset($svc);

        if (!$changed) return $in;

        $patched = $this->workDir.'/_compose.ports.yml';
        file_put_contents($patched, Yaml::dump($data, 99, 2));
        return $patched;
    }

    private function patchEnvPort(string $svc, int $hostPort): void
    {
        if (!$this->envFile) return;

        $key = strtoupper($svc).'_PORT';
        $lines = file_exists($this->envFile)
            ? file($this->envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            : [];

        $found = false;
        foreach ($lines as &$line) {
            if (str_starts_with($line, "$key=")) {
                $line  = "$key=$hostPort";
                $found = true;
                break;
            }
        }
        if (!$found) $lines[] = "$key=$hostPort";

        file_put_contents($this->envFile, implode("\n", $lines) . "\n");
    }

    private function getFreePort(): int
    {
        $s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($s, '0.0.0.0', 0);
        socket_getsockname($s, $addr, $port);
        socket_close($s);
        return $port;
    }

    private function isPortFree(int $port): bool
    {
        if (!$port) return false;
        $t = @fsockopen('127.0.0.1', $port);
        if ($t) { fclose($t); return false; }
        return true;
    }

    private function startCompose(string $composePath): array
    {
        $dirPath     = dirname($composePath);
        $project     = $this->id;
        $patchedPath = $this->patchComposePorts($composePath);
        $logFile     = $this->workDir . '/exec.log';

        /* 1 ─ pull images that have no build context */
        Utils::sh(
            sprintf('cd %s && docker compose -p %s -f %s pull --quiet 2>&1',
                escapeshellarg($dirPath), escapeshellarg($project), escapeshellarg($patchedPath)),
            $pullOut,
            $logFile
        );

        /* 2 ─ build + up */
        Utils::sh(
            sprintf('cd %s && docker compose -p %s -f %s up -d --build 2>&1',
                escapeshellarg($dirPath), escapeshellarg($project), escapeshellarg($patchedPath)),
            $upOut,
            $logFile
        );

        /* 3 ─ wait until every container is “running” (ignore health if blank) */
        $deadline = time() + 120;
        do {
            Utils::sh(
                sprintf('docker compose -p %s ps --format "{{.State}}|{{.Health}}"', escapeshellarg($project)),
                $stateOut
            );
            $all = array_filter(explode("\n", trim($stateOut)));
            $ready = true;
            foreach ($all as $l) {
                [$state, $health] = explode('|', $l);
                if ($state !== 'running') { $ready = false; break; }
                if ($health && !in_array($health, ['healthy', '-', 'starting'])) { $ready = false; break; }
            }
            if ($ready) break;
            sleep(2);
        } while (time() < $deadline);

        /* 4 ─ collect host‑ports */
        Utils::sh(
            sprintf('docker compose -p %s ps --format "{{.Name}}|{{.Publishers}}"', escapeshellarg($project)),
            $portLines
        );
        $ports = [];
        foreach (array_filter(explode("\n", trim($portLines))) as $line) {
            [$svc, $pub] = explode('|', $line);
            foreach (preg_split('/,\s*/', $pub) as $chunk) {
                if (preg_match('/:(\d+)->(\d+)/', $chunk, $m)) {
                    $ports[] = ['service'=>$svc,'hostPort'=>$m[1],'containerPort'=>$m[2]];
                }
            }
        }

        /* 5 ─ container IDs (for metrics) */
        Utils::sh(
            sprintf('docker compose -p %s ps -q', escapeshellarg($project)),
            $cidRaw
        );
        $cids = array_filter(explode("\n", trim($cidRaw)));

        /* 6 ─ store meta so /stop.php can cleanly tear down */
        file_put_contents($this->workDir.'/meta.json', json_encode([
            'mode'  => 'compose',
            'ports' => $ports
        ]));

        return [
            'ports'        => $ports,
            'containerIds' => $cids
        ];
    }

    private function collect(string $s): \Illuminate\Support\Collection
    {
        return new \Illuminate\Support\Collection(
            array_filter(explode("\n", trim($s)))
        );
    }

    private function startDockerfile(string $dockerfilePath): array
    {
        $dir     = dirname($dockerfilePath);
        $tag     = $this->id . ':latest';
        $logFile = $this->workDir . '/exec.log';

        // build image
        Utils::sh(
            sprintf('docker build -t %s -f %s %s 2>&1',
                escapeshellarg($tag),
                escapeshellarg($dockerfilePath),
                escapeshellarg($dir)),
            $tmp,
            $logFile
        );

        // run container with optional .env
        $envOpt = $this->envFile && is_file($this->envFile)
            ? '--env-file ' . escapeshellarg($this->envFile)
            : '';

        Utils::sh(
            sprintf('docker run -d -P %s --name %s %s 2>&1',
                $envOpt,
                escapeshellarg($this->id),
                escapeshellarg($tag)),
            $tmp,
            $logFile
        );

        // host‑port discovery
        Utils::sh('docker port ' . escapeshellarg($this->id), $portRaw);
        $ports = [];
        foreach (array_filter(explode("\n", trim($portRaw))) as $l) {
            [$cnPort, $host] = array_map('trim', explode('->', $l));
            [, $hPort] = explode(':', $host);
            $ports[] = ['service'=>$this->id,'hostPort'=>$hPort,'containerPort'=>$cnPort];
        }

        // save meta for /stop.php
        file_put_contents($this->workDir.'/meta.json', json_encode([
            'mode'  => 'single',
            'ports' => $ports
        ]));

        return [
            'ports'        => $ports,
            'containerIds' => [$this->id]
        ];
    }
}
