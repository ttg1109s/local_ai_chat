<?php
/** Build a compact task checkpoint from the current loop counters. */
function lac_agent_loop_task_snapshot(int $step,array $calls,int $completed,int $failed,float $started,array $available): array {
 return [
  'started_at'=>date(DATE_ATOM,(int)$started),
  'step_count'=>$step,
  'tool_call_count'=>count($calls),
  'completed_tool_count'=>$completed,
  'failed_tool_count'=>$failed,
  'available_tools'=>array_column($available,'name'),
  'last_tool'=>$calls?($calls[count($calls)-1]['tool']??null):null,
  'last_error_code'=>$calls?($calls[count($calls)-1]['error_code']??null):null,
  'elapsed_ms'=>(int)((microtime(true)-$started)*1000),
 ];
}


/**
 * Build authoritative, bounded execution provenance from Runtime calls and the durable ledger.
 * Model-authored text is never consulted. A call is successful only when the ledger
 * independently verifies a COMMITTED receipt for this exact run/call/tool tuple.
 */
function lac_agent_loop_execution_state(string $runId,array $calls,array $available): array {
 $ledger=function_exists('lac_invocation_list_by_run')?lac_invocation_list_by_run($runId):[];
 $byId=[];foreach($ledger as $record)$byId[(string)($record['tool_call_id']??'')]=$record;
 // Legacy/in-memory calls are included only when no durable ledger record exists.
 foreach($calls as $call){$id=(string)($call['tool_call_id']??'');if($id!==''&&!isset($byId[$id]))$byId[$id]=['tool_call_id'=>$id,'tool'=>$call['tool']??'unknown','state'=>!empty($call['ok'])?'UNVERIFIED':'FAILED','error_code'=>$call['error_code']??null,'execution_started'=>false];}
 $invocations=[];$successful=[];$failed=[];$rejected=[];$unknown=[];$successfulTools=[];$failedTools=[];
 foreach($byId as $callId=>$record){
  $tool=(string)($record['tool']??'unknown');$state=(string)($record['invocation_status']??$record['state']??'UNKNOWN');
  $verified=null;if(function_exists('lac_execution_verify_success')){try{$verified=lac_execution_verify_success($runId,$callId,$tool);}catch(Throwable $ignored){}}
  if($verified!==null)$state='COMMITTED';
  elseif(in_array($state,['REQUESTED','VALIDATED'],true))$state='PREFLIGHTING';
  elseif(in_array($state,['DISPATCHED','EXECUTING','RESULT_RECEIVED'],true))$state='UNKNOWN';
  $item=['tool'=>$tool,'tool_call_id'=>$callId,'status'=>$state,'phase'=>$record['current_phase']??null,'execution_started'=>(bool)($record['execution_started']??in_array($state,['EXECUTING','RESULT_RECEIVED','COMMITTED','FAILED'],true)),'execution_committed'=>$verified!==null,'execution_id'=>$verified['execution_id']??($record['execution_id']??null),'error_code'=>$record['error_code']??($record['validation']['code']??null)];
  if($state==='REJECTED'){$item['error']=$record['validation']??null;$rejected[]=$item;}
  elseif($state==='COMMITTED'){
   $dataRef=(string)($verified['result_reference']??'');$availableData=false;
   if($dataRef!==''&&function_exists('lac_data_pack_exists_for_run')){try{$availableData=lac_data_pack_exists_for_run($dataRef,$runId);}catch(Throwable $ignored){}}
   $item['data_ref']=$dataRef?:null;$item['result_hash']=$verified['result_hash']??null;$item['committed_at']=$verified['committed_at']??null;$item['result_available']=$availableData;
   $successful[]=$item;$successfulTools[$tool]=true;
  }elseif($state==='UNKNOWN'){$unknown[]=$item;$failedTools[$tool]=true;}
  elseif($state==='FAILED'){$failed[]=$item;$failedTools[$tool]=true;}
  $invocations[]=$item;
 }
 return ['schema_version'=>2,'run_id'=>$runId,'available_tools'=>array_values(array_unique(array_map('strval',array_column($available,'name')))),
  'tool_call_count'=>count($invocations),'committed_tool_count'=>count($successful),'failed_or_unverified_tool_count'=>count($failed)+count($unknown),
  'has_executed_tool'=>count($successful)>0,'successful_tools'=>array_values(array_keys($successfulTools)),'failed_tools'=>array_values(array_keys($failedTools)),
  'attempts'=>$invocations,'invocations'=>$invocations,'committed_executions'=>$successful,'failed_or_unverified_executions'=>array_merge($failed,$unknown),
  'summary'=>['received'=>count($invocations),'rejected'=>count($rejected),'committed'=>count($successful),'failed'=>count($failed),'unknown'=>count($unknown)]];
}
