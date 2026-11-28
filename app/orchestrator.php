<?php
/**
 * Chat-level model orchestration. Round 0 belongs to the Chat Orchestrator,
 * not the Agent Runtime. The enabled-tool catalog is visible to the model,
 * while detailed schemas remain on-demand. Agent Runtime is entered only
 * after the model emits a valid handoff intent.
 */
require_once __DIR__.'/agent/loop/runner.php';

function lac_chat_orchestrate(PDO $pdo,string $endpoint,array $payload,array $enabledToolIds,array $context,callable $emit): array {
 $catalog=lac_tool_catalog();
 $userEnabledToolIds=[];
 foreach($enabledToolIds as $toolId){$toolId=strtolower(trim((string)$toolId));if($toolId!==''&&isset($catalog[$toolId])&&empty($catalog[$toolId]['internal']))$userEnabledToolIds[]=$toolId;}
 $userEnabledToolIds=array_values(array_unique($userEnabledToolIds));
 $enabledToolMap=lac_agent_loop_enabled_tool_map($userEnabledToolIds,$catalog);
 $available=array_values($enabledToolMap);
 unset($payload['tools'],$payload['tool_choice'],$payload['functions'],$payload['function_call']);

 // Round 0: model sees the user request, conversation, system prompt, and
 // only the compact catalog of tools explicitly enabled by this user.
 $dispatchPayload=$payload;
 if($available&&isset($dispatchPayload['messages'][0])&&is_array($dispatchPayload['messages'][0])){
  $compiled=lac_agent_compile_protocol($available,(string)($context['user_request']??''));
  $dispatchPayload['messages'][0]['content'].=$compiled['system_text'];
 }elseif(isset($dispatchPayload['messages'][0])&&is_array($dispatchPayload['messages'][0])){
  $dispatchPayload['messages'][0]['content'].="\n\nNo tools are enabled for this turn. Answer the user's request directly using the conversation context and your own capabilities. Do not emit tool calls or claim that a tool was used.";
 }
 $dispatchResponse=lac_agent_model_request($endpoint,$dispatchPayload,0,$context['stop_files']??[],$emit,['round'=>0,'phase'=>'chat_model_decision']);
 $dispatchContent=(string)($dispatchResponse['content']??'');
 $dispatchMessage=lac_agent_loop_decode_protocol($dispatchContent);
 $dispatchType=is_array($dispatchMessage)?(string)($dispatchMessage['type']??''):'';
 if(!empty($dispatchResponse['unsupported_native_tool_output'])&&trim($dispatchContent)===''){
  $dispatchMessage=['type'=>'invalid','error_code'=>'PROVIDER_NATIVE_TOOL_OUTPUT_UNSUPPORTED','message'=>'Provider-native tool calls are unsupported. Use the project Unified Agent JSON protocol.'];
  $dispatchType='invalid';
 }
 $dispatchFinalValid=$dispatchType==='final'&&count($dispatchMessage)===2&&array_key_exists('type',$dispatchMessage)&&array_key_exists('content',$dispatchMessage)&&is_string($dispatchMessage['content']??null)&&trim((string)$dispatchMessage['content'])!=='';
 // Handoff is model-initiated. Runtime permission/schema validation remains authoritative.
 $handoff=(bool)$available&&in_array($dispatchType,['tool_help_request','tool_call'],true);
 if(!$handoff){
  if(($dispatchType!==''&&$dispatchType!=='final')||($dispatchType==='final'&&!$dispatchFinalValid)||!empty($dispatchResponse['unsupported_native_tool_output'])){
   $directPayload=$payload;
   $directPayload['messages'][]=['role'=>'assistant','content'=>$dispatchContent];
   $directPayload['messages'][]=['role'=>'user','content'=>'DIRECT RESPONSE RECOVERY: The previous response was not a supported tool handoff or a valid final answer. Do not emit JSON protocol messages, tool calls, or provider-native calls. Answer the original user request directly in ordinary user-facing text or Markdown. No tools are available for this recovery. Do not claim that any tool was used.'];
   $dispatchResponse=lac_agent_model_request($endpoint,$directPayload,0,$context['stop_files']??[],$emit,['round'=>1,'phase'=>'direct_response_recovery']);
   $dispatchContent=(string)($dispatchResponse['content']??'');
   $dispatchMessage=lac_agent_loop_decode_protocol($dispatchContent);
   $dispatchType=is_array($dispatchMessage)?(string)($dispatchMessage['type']??''):'';
  }
  $directContent=($dispatchType==='final')?(string)($dispatchMessage['content']??''):$dispatchContent;
  if($directContent!==''&&empty($dispatchResponse['streamed']))$emit('delta',['text'=>$directContent]);
  return ['content'=>$directContent,'usage'=>$dispatchResponse['usage']??null,'calls'=>[],'steps'=>0,'finish_reason'=>$dispatchResponse['finish_reason']??null,'run_id'=>null,'task_state'=>'direct_completed','tool_execution_state'=>['schema_version'=>2,'available_tools'=>array_column($available,'name'),'tool_call_count'=>0,'committed_tool_count'=>0,'failed_or_unverified_tool_count'=>0,'has_executed_tool'=>false,'successful_tools'=>[],'failed_tools'=>[],'attempts'=>[],'invocations'=>[],'committed_executions'=>[],'failed_or_unverified_executions'=>[],'summary'=>['received'=>0,'rejected'=>0,'committed'=>0,'failed'=>0,'unknown'=>0]]];
 }

 // Only this branch enters Agent Runtime; reuse the already completed Round 0 response.
 $context['dispatch_response']=$dispatchResponse;
 $emit('trace_stage',['stage'=>'agent_handoff','label'=>'Model requested Agent handoff','detail'=>'The model selected an enabled tool capability. Starting Agent Runtime.']);
 return lac_agent_run($pdo,$endpoint,$dispatchPayload,$userEnabledToolIds,$context,$emit);
}
