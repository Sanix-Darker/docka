<?php
namespace App;

class Utils
{
    /**
     * Generate a RFC‑4122 UUID v4 (36‑char canonical string).
     */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);

        // Set version to 0100
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // Set variant to 10xxxxxx
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    /**
     * Run a shell command, capture stdout + stderr
     * and (optionally) tee lines into $logFile.
     */
    public static function sh(string $cmd, string &$out = null, ?string $logFile = null): int
    {
        $desp = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p    = proc_open($cmd, $desp, $pipes);
        $out  = '';

        $logHandle = $logFile ? fopen($logFile, 'ab') : null;

        foreach ([1, 2] as $i) {
            while (($line = fgets($pipes[$i])) !== false) {
                $out .= $line;
                if ($logHandle) {
                    fwrite($logHandle, $line);
                }
            }
        }

        if ($logHandle) {
            fclose($logHandle);
        }

        return proc_close($p);
    }
}
