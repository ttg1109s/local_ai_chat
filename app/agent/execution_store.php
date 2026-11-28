<?php
/** Durable execution ledger: identity is tool_call_id, never tool+arguments alone. */
function lac_execution_dir(): string { return dirname(__DIR__,2).'/storage/agent-executions'; }
function lac_execution_key(string $runId,string $toolCallId): string {
 return hash('sha256',$runId."\0".$toolCallId);
}
function lac_execution_read(string $runId,string $toolCallId): ?array {
 $path=lac_execution_dir().'/'.lac_execution_key($runId,$toolCallId).'.json';
 if(!is_file($path))return null;
 $v=json_decode((string)@file_get_contents($path),true);
 return is_array($v)&&($v['run_id']??null)===$runId&&($v['tool_call_id']??null)===$toolCallId?$v:null;
}

/** Invocation ledger APIs. Rejections are durable even when no execution attempt exists. */
function lac_invocation_get(string $runId,string $toolCallId): ?array { return lac_execution_read($runId,$toolCallId); }
function lac_invocation_list_by_run(string $runId): array {
 $dir=lac_execution_dir();if(!is_dir($dir))return [];$out=[];
 foreach(glob($dir.'/*.json')?:[] as $path){
  $v=json_decode((string)@file_get_contents($path),true);
  if(is_array($v)&&($v['run_id']??null)===$runId&&isset($v['tool_call_id']))$out[]=$v;
 }
 usort($out,static fn($a,$b)=>strcmp((string)($a['created_at']??''),(string)($b['created_at']??'')));
 return $out;
}
function lac_invocation_reject(string $runId,string $toolCallId,string $tool,array $arguments,array $diagnostic): array {
 $dir=lac_execution_dir();if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Could not create the execution ledger.');
 $lock=@fopen($dir.'/'.lac_execution_key($runId,$toolCallId).'.lock','c');
 if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Could not lock invocation ledger record.');
 try{
  $existing=lac_execution_read($runId,$toolCallId);
  $hash=hash('sha256',(string)json_encode($arguments,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
  if($existing && (($existing['tool']??$tool)!==$tool || ($existing['arguments_hash']??$hash)!==$hash))return ['conflict'=>true,'record'=>$existing];
  if($existing && in_array($existing['state']??'', ['DISPATCHED','EXECUTING','RESULT_RECEIVED','COMMITTED'],true))return ['conflict'=>false,'record'=>$existing];
  $history=$existing['history']??[];
  if(($existing['state']??'')!=='REJECTED')$history[]=['state'=>'REJECTED','at'=>date(DATE_ATOM)];
  $record=array_merge($existing??[],[
   'schema_version'=>2,'tool'=>$tool,'arguments_hash'=>$hash,'state'=>'REJECTED',
   'invocation_status'=>'REJECTED','current_phase'=>(string)($diagnostic['stage']??'preflight'),
   'execution_started'=>false,'execution_committed'=>false,'validation'=>$diagnostic,
   'attempts'=>$existing['attempts']??[],'history'=>$history,
   'created_at'=>$existing['created_at']??date(DATE_ATOM),'rejected_at'=>date(DATE_ATOM)
  ]);
  lac_execution_write($runId,$toolCallId,$record);
  return ['conflict'=>false,'record'=>$record];
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
/** Reconcile non-terminal attempts after a runtime interruption; never infer success. */
function lac_invocation_reconcile(string $runId): array {
 require_once __DIR__.'/result_store.php';
 $changed=[];
 foreach(lac_invocation_list_by_run($runId) as $snapshot){
  $callId=(string)($snapshot['tool_call_id']??'');$tool=(string)($snapshot['tool']??'');
  if($callId===''||$tool==='')continue;
  $dir=lac_execution_dir();$lock=@fopen($dir.'/'.lac_execution_key($runId,$callId).'.lock','c');
  if(!$lock||!flock($lock,LOCK_EX)){if(is_resource($lock))fclose($lock);continue;}
  try{
   $record=lac_execution_read($runId,$callId);$state=(string)($record['state']??'');
   if(!in_array($state,['DISPATCHED','EXECUTING','RESULT_RECEIVED','UNKNOWN'],true))continue;
   if(lac_execution_verify_success($runId,$callId,$tool)!==null)continue;
   // Reconcile the crash window after Data Pack commit but before ledger commit.
   // Result references are deterministic from execution_id, so a missing ledger
   // pointer does not prevent recovery when the immutable result record exists.
   $executionId=(string)($record['execution_id']??'');
   $reference=(string)($record['result_reference']??'');
   if($reference===''&&preg_match('/^[a-f0-9]{8,64}$/i',$executionId))$reference='agent-results/'.$executionId.'.json';
   if($reference!==''&&function_exists('lac_data_pack_get_for_run')){
    try{$stored=lac_data_pack_get_for_run($reference,$runId);}catch(Throwable $readError){$stored=null;}
    if(is_array($stored)&&!empty($stored['ok'])&&($stored['tool']??null)===$tool&&($stored['execution_id']??null)===$executionId&&($stored['tool_call_id']??null)===$callId){
     $record['result_reference']=$reference;
     $stored['result_reference']=$reference;
     lac_execution_write($runId,$callId,$record);
     lac_execution_result_store($runId,$callId,$stored);
     $record=lac_execution_transition($runId,$callId,'COMMITTED',['result_reference'=>$reference,'reconciled'=>true,'reconciliation_reason'=>'verified_data_pack_after_interruption']);
     $changed[]=$callId;
     continue;
    }
   }
   $record['state']='UNKNOWN';$record['invocation_status']='UNKNOWN';$record['outcome_unknown']=true;
   $record['current_phase']='reconciliation';$record['execution_started']=true;$record['execution_committed']=false;
   $record['history']=array_merge($record['history']??[],[['state'=>'UNKNOWN','at'=>date(DATE_ATOM),'reason'=>'runtime_interruption_reconciliation']]);
   lac_execution_write($runId,$callId,$record);$changed[]=$callId;
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 return ['run_id'=>$runId,'unknown_invocations'=>$changed,'count'=>count($changed)];
}
function lac_execution_write(string $runId,string $toolCallId,array $record): void {
 $dir=lac_execution_dir();if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Could not create the execution ledger.');
 $path=$dir.'/'.lac_execution_key($runId,$toolCallId).'.json';$record['run_id']=$runId;$record['tool_call_id']=$toolCallId;
 $record['updated_at']=date(DATE_ATOM);$tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
 $json=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
 if($json===false||file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not write the execution ledger.');}
}
function lac_execution_transition(string $runId,string $toolCallId,string $state,array $extra=[]): array {
 require_once __DIR__.'/tool_logger.php';
 $allowed=['REQUESTED'=>['VALIDATED','FAILED'],'VALIDATED'=>['DISPATCHED','FAILED'],
  'RECEIVED'=>['PREFLIGHTING','REJECTED','CANCELLED'],'PREFLIGHTING'=>['READY','REJECTED','CANCELLED'],
  'READY'=>['DISPATCHED','REJECTED','CANCELLED'],'DISPATCHED'=>['EXECUTING','FAILED','UNKNOWN','CANCELLED'],
  'EXECUTING'=>['RESULT_RECEIVED','FAILED','REJECTED','UNKNOWN','CANCELLED'],'RESULT_RECEIVED'=>['COMMITTED','FAILED','UNKNOWN'],
  'COMMITTED'=>[],'REJECTED'=>['RECEIVED'],'FAILED'=>['REQUESTED','RECEIVED'],
  'UNKNOWN'=>['COMMITTED','FAILED','CANCELLED'],'CANCELLED'=>[]];
 $current=lac_execution_read($runId,$toolCallId);$from=$current['state']??null;
 if($from!==null&&!in_array($state,$allowed[$from]??[],true))throw new RuntimeException("Invalid execution state transition: $from -> $state");
 $record=array_merge($current??[],$extra,['schema_version'=>2,'state'=>$state,'invocation_status'=>$state,
  'execution_started'=>($current['execution_started']??false)||$state==='EXECUTING'||$state==='RESULT_RECEIVED'||$state==='COMMITTED',
  'execution_committed'=>$state==='COMMITTED','history'=>array_merge($current['history']??[],[['state'=>$state,'at'=>date(DATE_ATOM)]])]);
 if(!empty($record['execution_id'])){
  $attempts=$record['attempts']??[];$lastIndex=count($attempts)-1;
  if($state==='RECEIVED'||$lastIndex<0||($attempts[$lastIndex]['execution_id']??null)!==$record['execution_id']){
   $attempts[]=['execution_id'=>$record['execution_id'],'attempt'=>(int)($record['attempt']??1),'state'=>$state,'started_at'=>date(DATE_ATOM),'execution_started'=>false];
   $lastIndex=count($attempts)-1;
  }
  $attempts[$lastIndex]['state']=$state;$attempts[$lastIndex]['updated_at']=date(DATE_ATOM);
  if($state==='EXECUTING'){$attempts[$lastIndex]['execution_started']=true;$attempts[$lastIndex]['started_at']=date(DATE_ATOM);}
  if($state==='COMMITTED')$attempts[$lastIndex]['committed_at']=date(DATE_ATOM);
  if($state==='FAILED'){$attempts[$lastIndex]['error_code']=$extra['error_code']??null;$attempts[$lastIndex]['error']=$extra['error']??null;}
  $record['attempts']=$attempts;
 }
 lac_execution_write($runId,$toolCallId,$record);
 lac_agent_log('execution_state_transition',['run_id'=>$runId,'request_id'=>($GLOBALS['lac_unified_trace_context']['request_id']??null),'tool_call_id'=>$toolCallId,'execution_id'=>$record['execution_id']??null,'tool'=>$record['tool']??null,'from_state'=>$from,'to_state'=>$state,'attempt'=>$record['attempt']??null,'error_code'=>$extra['error_code']??null]);
 return $record;
}
/** Explicit invocation-level transition facade over the durable ledger. */
function lac_invocation_transition(string $runId,string $toolCallId,string $state,array $extra=[]): array {
 $states=['RECEIVED','PREFLIGHTING','READY','DISPATCHED','EXECUTING','RESULT_RECEIVED','COMMITTED','FAILED','REJECTED','UNKNOWN','CANCELLED'];
 if(!in_array($state,$states,true))throw new InvalidArgumentException('Unsupported invocation state.');
 return lac_execution_transition($runId,$toolCallId,$state,$extra);
}
function lac_execution_result_store(string $runId,string $toolCallId,array $result): void {
 $r=lac_execution_read($runId,$toolCallId)??[];
 $r['result']=['ok'=>(bool)($result['ok']??false),'tool'=>$result['tool']??null,'execution_id'=>$result['execution_id']??null,'result_reference'=>$result['result_reference']??null,'error_code'=>$result['error_code']??null];$r['result_hash']=hash('sha256',(string)json_encode($r['result'],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
 $r['committed_at']=date(DATE_ATOM);lac_execution_write($runId,$toolCallId,$r);
}
/** Execute once per call identity. Same arguments with a different ID remain a new request. */
function lac_tool_execute_managed(PDO $pdo,string $name,array $args,array $permissions,array $context=[],string $toolCallId=''): array {
 if($toolCallId===''||strlen($toolCallId)>128)$toolCallId='call_'.bin2hex(random_bytes(12));
 $runId=(string)($context['run_id']??'standalone');$dir=lac_execution_dir();
 if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))return ['ok'=>false,'tool'=>$name,'error_code'=>'EXECUTION_STORE_UNAVAILABLE','error'=>'Execution Store is unavailable.'];
 $lock=@fopen($dir.'/'.lac_execution_key($runId,$toolCallId).'.lock','c');
 if(!$lock||!flock($lock,LOCK_EX))return ['ok'=>false,'tool'=>$name,'error_code'=>'EXECUTION_LOCK_FAILED','error'=>'Could not lock tool_call_id.'];
 try{return lac_tool_execute_managed_locked($pdo,$name,$args,$permissions,$context,$toolCallId);}
 finally{flock($lock,LOCK_UN);fclose($lock);}
}
function lac_tool_execute_managed_locked(PDO $pdo,string $name,array $args,array $permissions,array $context,string $toolCallId): array {
 require_once __DIR__.'/tool_executor.php';
 require_once __DIR__.'/result_store.php';
 $runId=(string)($context['run_id']??'standalone');$meta=lac_tool_catalog()[$name]??[];
 if(function_exists('lac_tool_apply_argument_defaults'))$args=lac_tool_apply_argument_defaults($args,$meta);
 $existing=lac_execution_read($runId,$toolCallId);
 $argumentsHash=hash('sha256',(string)json_encode($args,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
 // Identity conflicts must be checked before the committed-result cache path.
 // Otherwise a reused call ID could replay a result for different arguments.
 if($existing && (($existing['tool']??$name)!==$name || ($existing['arguments_hash']??$argumentsHash)!==$argumentsHash)){
  return ['ok'=>false,'tool'=>$name,'tool_call_id'=>$toolCallId,'error_code'=>'CALL_ID_CONFLICT','error'=>'tool_call_id was already used with a different tool or arguments.','retryable'=>false];
 }
 if(($existing['state']??'')==='COMMITTED'&&is_array($existing['result']??null)){
  $cached=$existing['result'];$ref=$cached['result_reference']??null;
  $raw=is_string($ref)?lac_data_pack_get($ref):null;
  if(is_array($raw)){$raw['result_reference']=$ref;$raw['execution_id']=$cached['execution_id']??null;$raw['tool_call_id']=$toolCallId;$raw['cached']=true;return $raw;}
  return ['ok'=>false,'tool'=>$name,'tool_call_id'=>$toolCallId,'error_code'=>'RESULT_EXPIRED','error'=>'The Job result has been released; the Data Pack cannot be replayed.','retryable'=>false];
 }
 if(in_array($existing['state']??'', ['DISPATCHED','EXECUTING','RESULT_RECEIVED'],true)){
  return ['ok'=>false,'tool'=>$name,'tool_call_id'=>$toolCallId,'error_code'=>'EXECUTION_OUTCOME_UNKNOWN','error'=>'A command with this tool_call_id has an unknown state. Reconcile it before retrying.','retryable'=>false];
 }
 if(($existing['state']??'')==='FAILED' && (empty($meta['idempotent']) || empty($existing['retryable']) || (int)($meta['retry_policy']['max_attempts']??1)<=(int)($existing['attempt']??1))){
  return ['ok'=>false,'tool'=>$name,'tool_call_id'=>$toolCallId,'error_code'=>'RETRY_NOT_ALLOWED','error'=>'The failed task must not be retried automatically under the safety policy of this tool.','retryable'=>false];
 }
 $validation=function_exists('lac_tool_validate_arguments_result')?lac_tool_validate_arguments_result($name,$args,$meta):['valid'=>true,'stage'=>'preflight_complete','violations'=>[]];
 if(empty($validation['valid'])){
  lac_invocation_reject($runId,$toolCallId,$name,$args,$validation);
  return ['ok'=>false,'tool'=>$name,'tool_call_id'=>$toolCallId,'error_code'=>'ARGUMENT_CONSTRAINT_FAILED','error'=>'Tool arguments failed preflight validation.','validation'=>$validation,'retryable'=>true,'execution_started'=>false];
 }
  $maxAttempts=max(1,min(5,(int)($meta['retry_policy']['max_attempts']??1)));
 $base=['tool'=>$name,'arguments_hash'=>$argumentsHash,'execution_id'=>bin2hex(random_bytes(12)),'attempt'=>(int)($existing['attempt']??0)+1];
 try {
  while(true){
   lac_execution_transition($runId,$toolCallId,'RECEIVED',$base+['created_at'=>date(DATE_ATOM)]);
   lac_execution_transition($runId,$toolCallId,'PREFLIGHTING');
   lac_execution_transition($runId,$toolCallId,'READY',['preflight_valid'=>true]);
   lac_execution_transition($runId,$toolCallId,'DISPATCHED');
   lac_execution_transition($runId,$toolCallId,'EXECUTING');
   $executionContext=$context;$executionContext['tool_call_id']=$toolCallId;$executionContext['execution_id']=$base['execution_id'];
   $result=lac_tool_execute($pdo,$name,$args,$permissions,$executionContext);
   $taskState=function_exists('lac_agent_task_load')?(lac_agent_task_load($runId)['state']??''):'';
   if($taskState==='cancelled')return ['ok'=>false,'tool'=>$name,'tool_call_id'=>$toolCallId,'error_code'=>'JOB_CANCELLED','error'=>'Job was cancelled; the tool result was discarded.','retryable'=>false];
   if(empty($result['ok'])){
    $canRetry=!empty($meta['idempotent'])&&!empty($meta['retryable'])&&!empty($result['retryable'])&&$base['attempt']<$maxAttempts;
    lac_execution_transition($runId,$toolCallId,'FAILED',['error'=>$result['error']??'Tool failed','error_code'=>$result['error_code']??'EXECUTION_FAILED','retryable'=>(bool)($result['retryable']??false)]);
    if($canRetry){usleep(min(1000000,200000*(int)$base['attempt']));$base['attempt']++;$base['execution_id']=bin2hex(random_bytes(12));continue;}
    $result['tool_call_id']=$toolCallId;$result['execution_id']=$base['execution_id'];
    $pack=lac_data_pack_put($runId,$name,$base['execution_id'],$result);$reference=$pack['data_ref'];$result['result_id']=$pack['result_id']??null;$result['result_reference']=$reference;
    if(function_exists('lac_agent_task_load')&&(lac_agent_task_load($runId)['state']??'')==='cancelled'){return ['ok'=>false,'tool'=>$name,'tool_call_id'=>$toolCallId,'error_code'=>'JOB_CANCELLED','error'=>'Job was cancelled; the current result is not committed to the invocation ledger.','retryable'=>false];}
    lac_execution_result_store($runId,$toolCallId,$result);
    $record=lac_execution_read($runId,$toolCallId)??[];$record['result_reference']=$reference;
    lac_execution_write($runId,$toolCallId,$record);
    return $result;
   }
   $result['tool_call_id']=$toolCallId;$result['execution_id']=$base['execution_id'];
   $pack=lac_data_pack_put($runId,$name,$base['execution_id'],$result);$reference=$pack['data_ref'];$result['result_id']=$pack['result_id']??null;$result['result_reference']=$reference;
   if(function_exists('lac_agent_task_load')&&(lac_agent_task_load($runId)['state']??'')==='cancelled'){return ['ok'=>false,'tool'=>$name,'tool_call_id'=>$toolCallId,'error_code'=>'JOB_CANCELLED','error'=>'The current result is not committed to the invocation ledger because the Job was cancelled.','retryable'=>false];}
   lac_execution_transition($runId,$toolCallId,'RESULT_RECEIVED',['result_status'=>'success','result_reference'=>$reference]);
   lac_execution_result_store($runId,$toolCallId,$result);
   lac_execution_transition($runId,$toolCallId,'COMMITTED');
   return $result;
  }
 } catch(Throwable $e) {
  $current=lac_execution_read($runId,$toolCallId);
  if(($current['state']??'')!=='COMMITTED'&&($current['state']??'')!=='FAILED') {
   // Once dispatch/execution began, a PHP exception does not prove the external
   // side effect failed. Preserve uncertainty and require reconciliation.
   $target=!empty($current['execution_started'])?'UNKNOWN':'FAILED';
   try{lac_execution_transition($runId,$toolCallId,$target,['error'=>$e->getMessage(),'error_code'=>'RUNTIME_LIFECYCLE_ERROR','outcome_unknown'=>$target==='UNKNOWN']);}catch(Throwable $ignored){}
  }
  return ['ok'=>false,'tool'=>$name,'tool_call_id'=>$toolCallId,'execution_started'=>!empty($current['execution_started']),'error_code'=>!empty($current['execution_started'])?'EXECUTION_OUTCOME_UNKNOWN':'RUNTIME_LIFECYCLE_ERROR','error'=>$e->getMessage(),'retryable'=>false];
 }
}

/** Return a success receipt only when the durable ledger proves a committed execution. */
function lac_execution_verify_success(string $runId,string $toolCallId,string $tool): ?array {
 if($runId===''||$toolCallId==='')return null;
 $record=lac_execution_read($runId,$toolCallId);
 if(!is_array($record)||($record['state']??'')!=='COMMITTED')return null;
 $result=$record['result']??null;
 if(!is_array($result)||empty($result['ok'])||($result['tool']??null)!==$tool||empty($result['execution_id'])||empty($result['result_reference'])||empty($record['result_hash'])||empty($record['committed_at']))return null;
 return [
  'execution_id'=>(string)($result['execution_id']??''),
  'tool_call_id'=>$toolCallId,
  'tool'=>$tool,
  'state'=>'COMMITTED',
  'result_reference'=>$result['result_reference']??null,
  'result_hash'=>$record['result_hash']??null,
  'committed_at'=>$record['committed_at']??null,
 ];
}
