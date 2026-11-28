<?php
declare(strict_types=1);

$source=file_get_contents(__DIR__.'/../api_routes/system_runtime.php');
if(!is_string($source))throw new RuntimeException('Could not read system runtime route.');
$start=strpos($source,'function lac_cleanup_unreferenced_python_charts');
$end=strpos($source,"\n\n// Serve generated JPG charts",$start);
if($start===false||$end===false)throw new RuntimeException('Python chart cleanup helper missing.');
$helper=substr($source,$start,$end-$start);
if(!str_contains($helper,'SELECT 1 FROM messages WHERE LOCATE(?,content)>0 LIMIT 1'))throw new RuntimeException('Cleanup must check remaining references.');
if(!str_contains($helper,'if($check->fetchColumn()){$kept[]=$file;continue;}'))throw new RuntimeException('Referenced charts must be retained.');
if(!str_contains($helper,'if(is_file($path)&&!is_link($path)){if(@unlink($path))$deleted[]=$file;'))throw new RuntimeException('Unreferenced charts must be deleted without following symlinks.');
if(str_contains($helper,'http_response_code(404);exit;')||str_contains($helper,'http_response_code(423);exit;'))throw new RuntimeException('Internal orphan cleanup must not abort or require owner unlock after reference removal.');
echo "PASS: Python chart cleanup retains referenced files and deletes unreferenced files without aborting\n";
