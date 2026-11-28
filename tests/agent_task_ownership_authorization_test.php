<?php
declare(strict_types=1);

$store=file_get_contents(__DIR__.'/../app/agent/task_store.php');
$runner=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
$route=file_get_contents(__DIR__.'/../api_routes/agent.php');
foreach([$store,$runner,$route] as $source){if(!is_string($source))throw new RuntimeException('Source read failed.');}
if(!str_contains($store,"'conversation_id'=>isset($data['conversation_id'])")||!str_contains($runner,"\$snapshot['conversation_id']=(int)(\$context['conversation_id']??0)"))throw new RuntimeException('Task ownership must be persisted from the generation context.');
if(!str_contains($route,'function lac_agent_task_authorized(PDO $pdo,array $task): bool'))throw new RuntimeException('Task authorization helper missing.');
foreach(['agent_task_status','agent_task_reject','agent_task_approve'] as $action){
 $pos=strpos($route,"if(\$action==='$action')");
 if($pos===false)throw new RuntimeException('Route action missing: '.$action);
 $next=strpos($route,"\nif(\$action===", $pos+1);$chunk=substr($route,$pos,$next===false?strlen($route)-$pos:$next-$pos);
 if(!str_contains($chunk,'lac_agent_task_authorized($pdo,$task)'))throw new RuntimeException('Ownership authorization missing from '.$action);
}
if(!str_contains($route,"array_filter(\$tasks,static fn(\$task)=>lac_agent_task_authorized(\$pdo,\$task))"))throw new RuntimeException('Recoverable tasks must be ownership-filtered.');
$approvePos=strpos($route,"if(\$action==='agent_task_approve')");
if($approvePos===false)throw new RuntimeException('Approval route missing.');
$approveChunk=substr($route,$approvePos);
$executionStoreRequire=strpos($approveChunk,"require_once LAC_ROOT.'/app/agent/execution_store.php';");
$managedCall=strpos($approveChunk,'lac_tool_execute_managed(');
if($executionStoreRequire===false||$managedCall===false||$executionStoreRequire>$managedCall)throw new RuntimeException('Approval route must load execution_store before managed tool execution.');

if(!str_contains($approveChunk,"\$toolCallId=(string)(\$pending['tool_call_id']??'')")||!str_contains($approveChunk,"\$toolCallId===''||strlen(\$toolCallId)>128")||str_contains($approveChunk,"approved_'.bin2hex(random_bytes(8))"))throw new RuntimeException('Approval must require a stable tool_call_id and never synthesize a new identity.');
echo "PASS: Agent task ownership and approval execution dependency/identity are guarded\\n";
