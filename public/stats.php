<?php

header('Content-Type: application/json');
$cid = preg_replace('/[^a-zA-Z0-9_.-]/','',$_GET['cid'] ?? '');
if (!$cid) { echo '{}'; exit; }

$cmd = sprintf('docker stats --no-stream --format "{{json .}}" %s 2>/dev/null', escapeshellarg($cid));
$out = shell_exec($cmd);
if (!$out) { echo '{}'; exit; }

$j = json_decode($out,true);
$cpu = rtrim($j['CPUPerc'],'%');
$mem = strtok($j['MemUsage'], '/');      // e.g. "42.3MiB / …"
echo json_encode(['cpu'=>$cpu,'mem'=>$mem]);
