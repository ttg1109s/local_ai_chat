<?php
/** Durable Agent task snapshots. Sensitive prompts are excluded unless explicitly checkpointed by caller. */
function lac_agent_task_dir(): string {return dirname(__DIR__,2).'/storage/agent-tasks';}
function lac_agent_task_path(string $runId): string {return lac_agent_task_dir().'/'.preg_replace('/[^a-f0-9]/','',$runId).'.json';}
function lac_agent_task_save(string $runId,string $state,array $data=[],?string $expectedFrom=null): void {
 require_once __DIR__.'/result_store.php';
 $dir=lac_agent_task_dir();if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Could not create the task-store directory.');
 $path=lac_agent_task_path($runId);$lock=@fopen($path.'.lock','c');if(!$lock)throw new RuntimeException('Could not lock the task checkpoint.');
 try{
  if(!flock($lock,LOCK_EX))throw new RuntimeException('Could not acquire the task-checkpoint lock.');
  $old=is_file($path)?json_decode((string)@file_get_contents($path),true):null;
  $old=is_array($old)?$old:[];
  $from=(string)($old['state']??'');
  if($expectedFrom!==null&&$from!==$expectedFrom)throw new RuntimeException('Task state changed before transition: expected '.$expectedFrom.', found '.$from);
  // Keep the persisted state machine aligned with states emitted by the active Agent loop.
  // Older state names remain accepted for tasks written by previous versions.
  $allowed=[
   'planning'=>['planning','executing','waiting','waiting_approval','validating','limited','running','recovering','failed','cancelled','completed','completed_with_limitations','completed_with_tool_rejection'],
   'executing'=>['executing','planning','waiting','waiting_approval','validating','limited','running','recovering','failed','cancelled','completed','completed_with_limitations','completed_with_tool_rejection'],
   'waiting'=>['waiting','planning','executing','waiting_approval','validating','limited','running','recovering','failed','cancelled','completed','completed_with_limitations'],
   'waiting_approval'=>['waiting_approval','executing','cancelled','failed','completed','completed_with_limitations'],
   'validating'=>['validating','planning','executing','limited','running','recovering','failed','cancelled','completed','completed_with_limitations','completed_with_tool_rejection'],
   'limited'=>['limited','planning','executing','validating','running','recovering','cancelled','completed','completed_with_limitations'],
   'running'=>['running','recovering','executing','validating','completed','completed_with_limitations','completed_with_tool_rejection','failed','cancelled'],
   'recovering'=>['recovering','running','executing','validating','completed','completed_with_limitations','completed_with_tool_rejection','failed','cancelled'],
   'completed_with_limitations'=>['completed_with_limitations'],
   'completed_with_tool_rejection'=>['completed_with_tool_rejection'],
   'failed'=>['failed'],'cancelled'=>['cancelled'],'completed'=>['completed']
  ];
  if($from!==''&&!in_array($state,$allowed[$from]??[$from],true))throw new RuntimeException("Invalid task state transition: $from -> $state");
  $history=$old['state_history']??[];
  if($from!==$state)$history[]=['state'=>$state,'at'=>date(DATE_ATOM)];
  $safe=['task_id'=>$runId,'run_id'=>$runId,'state'=>$state,'state_history'=>$history,'created_at'=>$old['created_at']??date(DATE_ATOM),'updated_at'=>date(DATE_ATOM),'started_at'=>$data['started_at']??$old['started_at']??null,
   'step_count'=>(int)($data['step_count']??0),'tool_call_count'=>(int)($data['tool_call_count']??0),
   'completed_tool_count'=>(int)($data['completed_tool_count']??0),'failed_tool_count'=>(int)($data['failed_tool_count']??0),
   'available_tools'=>$data['available_tools']??[],'last_tool'=>$data['last_tool']??null,'last_error_code'=>$data['last_error_code']??null,
   'elapsed_ms'=>(int)($data['elapsed_ms']??0),'limit'=>$data['limit']??null,
   'failure_phase'=>$data['failure_phase']??$old['failure_phase']??null,'failure_message'=>$data['failure_message']??$old['failure_message']??null,
   // Persist receipt metadata and Data Pack availability, never raw tool output.
   'tool_execution_state'=>$data['tool_execution_state']??$old['tool_execution_state']??null,
   'result_data_retained'=>($state==='failed'&&(int)($data['completed_tool_count']??$old['completed_tool_count']??0)>0),
   'plan'=>$data['plan']??null,'node_results'=>$data['node_results']??[],'pending_approval'=>$data['pending_approval']??$old['pending_approval']??null,'checkpoint_version'=>3];
  // Terminal cleanup releases Data Pack bytes. Keep the immutable receipt/hash, but
  // make the persisted availability flag reflect the post-cleanup reality.
  $willReleasePacks=function_exists('lac_data_pack_cleanup_enabled')&&lac_data_pack_cleanup_enabled()&&in_array($state,['completed','failed','cancelled'],true) && !($state==='failed'&&(int)($data['completed_tool_count']??$old['completed_tool_count']??0)>0);
  if($willReleasePacks&&is_array($safe['tool_execution_state']??null)){
   $safe['tool_execution_state']['data_retention']='released_after_terminal_state';
   if(is_array($safe['tool_execution_state']['committed_executions']??null)){
    foreach($safe['tool_execution_state']['committed_executions'] as &$execution){
     $execution['result_was_available_during_run']=(bool)($execution['result_available']??false);
     $execution['result_available']=false;
    }unset($execution);
   }
  }
  $tmp=$path.'.'.bin2hex(random_bytes(5)).'.tmp';$json=json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT);
  if($json===false||@file_put_contents($tmp,$json,LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not write the task checkpoint.');}
 } finally {flock($lock,LOCK_UN);fclose($lock);}
 // Keep committed Data Packs after a failed synthesis/transport if tools already
 // completed. This allows diagnosis and avoids deleting successful work.
 $preserveCommittedResults=$state==='failed'&&(int)($safe['completed_tool_count']??0)>0;
 if(in_array($state,['completed','failed','cancelled'],true)&&!$preserveCommittedResults&&function_exists('lac_data_pack_cleanup_enabled')&&lac_data_pack_cleanup_enabled()){
  $researchLedger = dirname(__DIR__, 2) . '/storage/agent-tasks/web-research-' . preg_replace('/[^a-f0-9]/i', '', $runId) . '.json';
  if (is_file($researchLedger)) {
   $researchLock=@fopen($researchLedger.'.lock','c');
   if($researchLock){
    if(@flock($researchLock,LOCK_EX)){if(is_file($researchLedger))@unlink($researchLedger);@flock($researchLock,LOCK_UN);}
    @fclose($researchLock);
   }
  }
  // Keep the lock inode stable: unlinking lock files can split concurrent lockers across different inodes.
  try{lac_data_pack_cleanup_run($runId);}catch(Throwable $cleanupError){
   if(function_exists('lac_agent_log'))lac_agent_log('data_pack_cleanup_failed',['run_id'=>$runId,'error'=>$cleanupError->getMessage()]);
  }
 }
}
function lac_agent_task_load(string $runId): ?array {
 $path=lac_agent_task_path($runId);if(!is_file($path))return null;$raw=@file_get_contents($path);$v=json_decode((string)$raw,true);
 return is_array($v)&&($v['run_id']??'')===$runId?$v:null;
}
function lac_agent_task_list(int $limit=50): array {
 $files=glob(lac_agent_task_dir().'/*.json')?:[];usort($files,static fn($a,$b)=>filemtime($b)<=>filemtime($a));$out=[];
 foreach(array_slice($files,0,max(1,min(200,$limit))) as $f){$v=json_decode((string)@file_get_contents($f),true);if(is_array($v))$out[]=$v;}
 return $out;
}
function lac_agent_task_recoverable(bool $reconcile=false): array {
 require_once __DIR__.'/execution_store.php';
 $tasks=array_values(array_filter(lac_agent_task_list(200),static fn($t)=>in_array($t['state']??'', ['planning','executing','waiting','waiting_approval','validating','limited','running','recovering'],true)));
 foreach($tasks as &$task){
  $runId=(string)($task['run_id']??$task['task_id']??'');
  if($reconcile&&$runId!=='')try{$task['invocation_reconciliation']=lac_invocation_reconcile($runId);}catch(Throwable $e){$task['invocation_reconciliation']=['error'=>'RECONCILIATION_FAILED'];}
 }unset($task);
 return $tasks;
}
