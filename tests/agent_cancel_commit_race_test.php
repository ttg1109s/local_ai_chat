<?php
declare(strict_types=1);

$taskStore=file_get_contents(__DIR__.'/../app/agent/task_store.php');
$executionStore=file_get_contents(__DIR__.'/../app/agent/execution_store.php');
foreach([$taskStore,$executionStore] as $source){if(!is_string($source))throw new RuntimeException('Source read failed.');}
if(!str_contains($taskStore,'function lac_agent_task_with_commit_lock(string $runId, callable $callback): mixed'))throw new RuntimeException('Run-wide commit lock helper missing.');
if(!str_contains($taskStore,"if(\$state==='cancelled')"))throw new RuntimeException('Cancellation transition must acquire the run-wide commit lock.');
if(!str_contains($executionStore,'lac_agent_task_with_commit_lock($runId,function()use($runId,$toolCallId,$reference,$result)'))throw new RuntimeException('Managed execution result commit must use the run-wide commit lock.');
$guardPos=strpos($executionStore,'lac_agent_task_with_commit_lock($runId,function()use($runId,$toolCallId,$reference,$result)');
$cancelCheck=strpos($executionStore,"if((lac_agent_task_load(\$runId)['state']??'')==='cancelled')return false;",$guardPos);
$resultCommit=strpos($executionStore,"lac_execution_transition(\$runId,\$toolCallId,'COMMITTED');",$guardPos);
if($guardPos===false||$cancelCheck===false||$resultCommit===false||$cancelCheck>$resultCommit)throw new RuntimeException('Commit must check cancellation under the same lock before writing COMMITTED.');
echo "PASS: task cancellation and managed execution commit are serialized\n";
