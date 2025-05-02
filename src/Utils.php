<?php

namespace App;

class Utils
{
    /** Return a short random id like "a3f9c1b2e8" */
    public static function uuid(): string
    {
        return bin2hex(random_bytes(5));
    }

    /** Run a shell command and stream output */
    public static function sh(string $cmd, string &$out = null): int
    {
        $descriptorspec = [
            1 => ['pipe','w'],
            2 => ['pipe','w'],
        ];
        $proc = proc_open($cmd, $descriptorspec, $pipes);
        $out = '';
        foreach ([1,2] as $i) {
            while (($line = fgets($pipes[$i])) !== false) {
                $out .= $line;
            }
        }
        return proc_close($proc);
    }
}
