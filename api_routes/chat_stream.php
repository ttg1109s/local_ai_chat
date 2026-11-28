<?php
/** API route family: chat stream */
if($action==='chat_stream'){
 require_once LAC_ROOT.'/app/agent/artifact_manager.php';
 require_once LAC_ROOT.'/app/agent/attachment_manager.php';
 require_csrf();$unlocked=array_map('intval',$_SESSION['conversation_unlocks']??[]);session_write_close();ignore_user_abort(true);@set_time_limit(0);
 $id=(int)($_POST['id']??0);$message=trim((string)($_POST['message']??''));$mode=(string)($_POST['mode']??'send');$sourceAssistantId=0;
 if(!$id)out(['error'=>'Conversation ID is missing'],422);
 $q=$pdo->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$id]);$conv=$q->fetch();if(!$conv)out(['error'=>'Conversation does not exist'],404);if(!empty($conv['password_hash'])&&!in_array($id,$unlocked,true))out(['error'=>'Conversation is locked. Unlock it first.','locked'=>true],423);$lockName='lac_gen_'.$id;$lockQ=$pdo->prepare('SELECT GET_LOCK(?,0)');$lockQ->execute([$lockName]);if((int)$lockQ->fetchColumn()!==1)out(['error'=>'Another generation is active in this conversation. Wait for it to finish.'],409);
 $requestNow=new DateTimeImmutable('now',new DateTimeZone($config['timezone']));$requestTimestamp=$requestNow->format(DateTimeInterface::ATOM);$generationStarted=microtime(true);if($mode==='send'){if($message==='')out(['error'=>'Message content is empty'],422);$pdo->prepare("INSERT INTO messages(conversation_id,role,content,request_timestamp) VALUES(?,'user',?,?)")->execute([$id,$message,$requestTimestamp]);$title=trim((string)preg_replace('/\\s+/u',' ',strip_tags($message)));$title=mb_substr($title,0,65);$pdo->prepare("UPDATE conversations SET title=IF(title IN ('New chat','New Conversation','New chat','New sandbox'),?,title) WHERE id=?")->execute([$title,$id]);}
 elseif($mode==='regenerate'){$q=$pdo->prepare("SELECT id,content FROM messages WHERE conversation_id=? AND role='assistant' ORDER BY id DESC LIMIT 1");$q->execute([$id]);$last=$q->fetch();if($last){$sourceAssistantId=(int)$last['id'];$oldChartFiles=lac_python_chart_files((string)$last['content']);lac_artifact_cleanup_message($sourceAssistantId);lac_attachment_cleanup_message((string)$id,(string)$sourceAssistantId);$pdo->prepare('DELETE FROM messages WHERE id=?')->execute([$sourceAssistantId]);lac_cleanup_unreferenced_python_charts($pdo,$oldChartFiles);}}
 elseif($mode!=='continue')out(['error'=>'Invalid chat mode'],422);
 $continueId=null;if($mode==='continue'){$z=$pdo->prepare("SELECT id FROM messages WHERE conversation_id=? AND role='assistant' ORDER BY id DESC LIMIT 1");$z->execute([$id]);$continueId=(int)$z->fetchColumn();$sourceAssistantId=$continueId;}
 $q=$pdo->prepare('SELECT role,content FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT 30');$q->execute([$id]);$history=array_reverse($q->fetchAll());
 // Persist an assistant placeholder before potentially slow RAG/model preparation.
 // This makes the in-flight turn recoverable after F5 without changing the DB schema.
 $baseText='';$assistantMessageId=0;
 if($mode==='continue'&&$continueId){
   $bq=$pdo->prepare("SELECT content FROM messages WHERE id=? AND conversation_id=? AND role='assistant'");$bq->execute([$continueId,$id]);$baseText=(string)($bq->fetchColumn()?:'');$assistantMessageId=$continueId;
   $pdo->prepare("UPDATE messages SET interrupted=1,status_label='preparing',request_timestamp=? WHERE id=? AND conversation_id=?")->execute([$requestTimestamp,$assistantMessageId,$id]);
 }else{
   $pdo->prepare("INSERT INTO messages(conversation_id,role,content,interrupted,request_timestamp,status_label) VALUES(?,'assistant','',1,?,'preparing')")->execute([$id,$requestTimestamp]);$assistantMessageId=(int)$pdo->lastInsertId();
 }

 // Open SSE immediately after placeholder creation so F5 and Stop know this message id.
 $runDir=LAC_ROOT.'/storage/runs';if(!is_dir($runDir))mkdir($runDir,0775,true);
 $runId=bin2hex(random_bytes(10));$stopFile=$runDir.'/'.$runId.'.stop';$messageStopFile=$runDir.'/'.$assistantMessageId.'.stop';@unlink($messageStopFile);@file_put_contents($runDir.'/'.$runId.'.json',json_encode(['conversation_id'=>$id,'message_id'=>$assistantMessageId,'created_at'=>time()],JSON_UNESCAPED_SLASHES),LOCK_EX);
 while(ob_get_level()>0){@ob_end_flush();}ob_implicit_flush(true);
 header('Content-Type: text/event-stream; charset=utf-8');header('Cache-Control: no-cache, no-transform');header('X-Accel-Buffering: no');header('Connection: keep-alive');
 require_once LAC_ROOT.'/app/agent/tool_logger.php';
 $traceDir=LAC_ROOT.'/storage/traces';if(!is_dir($traceDir))@mkdir($traceDir,0775,true);
 $retentionResult=lac_unified_trace_cleanup($traceDir,7);
 $traceFile=$traceDir.'/'.$assistantMessageId.'.jsonl';
 $traceStarted=hrtime(true);
 lac_unified_trace_set_context(['trace_file'=>$traceFile,'trace_id'=>'trace_'.$assistantMessageId,'run_id'=>$runId,'message_id'=>$assistantMessageId,'conversation_id'=>$id,'started_ns'=>$traceStarted,'sequence'=>0]);
 $sendEvent=static function(string $event,array $data)use($traceStarted):void{
  if($event!=='delta'){
   $source='system';
   if(str_starts_with($event,'model_')||str_starts_with($event,'native_'))$source='model';
   elseif(str_starts_with($event,'agent_'))$source='agent';
   elseif(str_starts_with($event,'tool_'))$source='tool';
   $level=($event==='error'||str_contains($event,'failed')||str_contains($event,'error')?'error':'info');
   lac_unified_trace_write($event,$data,$source,$level);
  }
  echo "event: ".$event."\ndata: ".json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";@ob_flush();flush();
 };
 $sendEvent('meta',['run_id'=>$runId,'message_id'=>$assistantMessageId,'model_label'=>'Model','agent'=>false,'preparing'=>true]);
 $sendEvent('trace_configuration',['detail_level'=>'debug','retention_days'=>7,'delta_persistence'=>false,'storage'=>'unified_jsonl','cleanup'=>$retentionResult]);
 $sendEvent('trace_stage',['stage'=>'preparing','label'=>'Preparing the chat turn','detail'=>'Message and task record created; loading context.']);
 $stopDuringPreparation=static function()use($stopFile,$messageStopFile,$runDir,$runId,$pdo,$assistantMessageId,$id,$lockName,$sendEvent,$generationStarted):void{
  if(!file_exists($stopFile)&&!file_exists($messageStopFile))return;
  @unlink($stopFile);@unlink($messageStopFile);@unlink($runDir.'/'.$runId.'.json');
  $elapsed=max(0,(int)round(microtime(true)-$generationStarted));
  $pdo->prepare("UPDATE messages SET interrupted=1,status_label='stopped',elapsed_seconds=elapsed_seconds+? WHERE id=? AND conversation_id=?")->execute([$elapsed,$assistantMessageId,$id]);
  $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);
  $sendEvent('finish',['reason'=>'stop']);
  echo "event: done\ndata: ".json_encode(['stopped'=>true,'message_id'=>$assistantMessageId])."\n\n";@ob_flush();flush();exit;
 };

 $system='You are a helpful and accurate AI assistant. Respond in English only. State clearly when you are uncertain.';

$customSystemPrompt=trim(setting($pdo,'system_prompt',''));if($customSystemPrompt!==''){$system.="\n\nCUSTOM SYSTEM INSTRUCTIONS (administrator-provided):\n".$customSystemPrompt;}
 // Inject a fresh server-side clock into every model request. This is separate
 // from metadata.timestamp and is visible to the model as system context.
 $system.="\n\nSYSTEM TIME (generated by the server when the request was received; use this as the current time reference):"
   ."\nCurrent date: ".$requestNow->format('d/m/Y')
   ."\nCurrent time: ".$requestNow->format('H:i:s')
   ."\nTime zone: ".$requestNow->getTimezone()->getName()
   ."\nTimestamp: ".$requestTimestamp
   ."\nFor questions about the current date or time, use this reference. Do not infer the current time from training data.";
 if($mode==='continue'){$system.='

CONTINUE MODE (MANDATORY): This is not a new question. Continue directly after the final character of the previous assistant response. Do not add an introduction, greeting, summary, heading, or repeated content. Complete any unfinished sentence. Return only the new text to append to the previous response.';}
  $selectedDocIds=[];
  $decodedDocIds=json_decode((string)($_POST['selected_doc_ids']??'[]'),true);
  if(is_array($decodedDocIds)){
    $selectedDocIds=array_values(array_unique(array_filter(array_map('intval',$decodedDocIds),static fn($v)=>$v>0)));
    if(count($selectedDocIds)>100)$selectedDocIds=array_slice($selectedDocIds,0,100);
  }
  $toolRag=!empty($selectedDocIds);
  $requestedTools=json_decode(setting($pdo,'agent_tool_permissions','[]'),true);
  if(!is_array($requestedTools))$requestedTools=[];
  require_once LAC_ROOT.'/app/agent/tool_registry.php';require_once LAC_ROOT.'/app/agent/tool_permissions.php';
  $enabledToolIds=lac_agent_permissions(array_values(array_unique($requestedTools)),(bool)$conv['sandbox']);
  $queryText=$message;
  if($mode==='continue'){$uq=$pdo->prepare("SELECT content FROM messages WHERE conversation_id=? AND role='user' ORDER BY id DESC LIMIT 1");$uq->execute([$id]);$queryText=(string)($uq->fetchColumn()?:'');}
  if($queryText==='')$queryText=$history[count($history)-1]['content']??'';
  $ragContext=$toolRag?"\n\nFiles were selected, but no matching knowledge chunks are available or the documents have not been indexed.":"\n\nNo RAG files are attached to this chat turn. No internal documents were retrieved.";
  $stopDuringPreparation();
  if(!(int)$conv['sandbox'] && $toolRag){
    require_once LAC_ROOT.'/app/rag_engine.php';
    $docMarks=implode(',',array_fill(0,count($selectedDocIds),'?'));
    $docChunkQ=$pdo->prepare("SELECT COUNT(DISTINCT canonical_chunk_id) FROM rag_document_chunk_map WHERE document_id IN ($docMarks)");
    $docChunkQ->execute($selectedDocIds);
    if((int)$docChunkQ->fetchColumn()>0){
      $leaseKey='chat:'.bin2hex(random_bytes(16));rag_lease_acquire($pdo,$leaseKey,'retrieval',900);
      try{lac_bge_ensure_for_rag($pdo,240);$ragResult=rag_retrieve_result($pdo,$queryText,5,$selectedDocIds);$ragContext=rag_prompt_context($ragResult);}
      finally{rag_lease_release($pdo,$leaseKey);}
    }else{
      $ragContext="\n\nThe selected files have no indexed knowledge chunks. No RAG content was retrieved.";
    }
    $stopDuringPreparation();
    $system.=$ragContext;
  }elseif((int)$conv['sandbox']){$ragContext="\n\nSandbox: RAG and website access are disabled.";}
  $stopDuringPreparation();
  $model=trim((string)($_POST['model']??setting($pdo,'model',$config['ai']['model'])));
  // Website and Internet access are now invoked on demand through the internal Agent tool protocol.


if($model==='')out(['error'=>'No response model selected.'],422);if(preg_match('/bge[-_]?m3|embedding/i',$model))out(['error'=>'BGE-M3 is an embedding model and cannot be used for chat responses.'],422);$modelConfig=lac_get_model_config($pdo,$model);$modelAlias=(string)$modelConfig['alias'];$generation=$modelConfig['generation_params'];$displayModel=$modelAlias!==''?$modelAlias:$model;
 $promptHistory=$history;
 if($mode==='continue'){
   // Treat the previous assistant answer as completed context, then ask only for its continuation.
   // The generated delta is appended to the existing database/UI message, never shown as a replacement.
   $lastAssistantText='';
   for($i=count($history)-1;$i>=0;$i--){if(($history[$i]['role']??'')==='assistant'){$lastAssistantText=(string)$history[$i]['content'];break;}}
   // Bound continuation context by replacing the previous assistant turn with its
   // trailing 1,200 characters. The full response remains in $baseText and is
   // appended to the newly generated continuation after the model returns.
   for($historyIndex=count($promptHistory)-1;$historyIndex>=0;$historyIndex--){
     if(($promptHistory[$historyIndex]['role']??'')==='assistant'){
       $promptHistory[$historyIndex]['content']=mb_substr($lastAssistantText,-1200);
       break;
     }
   }
   // Do not append a synthetic user turn: the truncated assistant answer stays final.
 }
 $configuredMaxTokens=(int)$generation['max_tokens'];
 $effectiveMaxTokens=$configuredMaxTokens;
 $payload=['model'=>$model,'metadata'=>['timestamp'=>$requestTimestamp],'messages'=>array_merge([['role'=>'system','content'=>$system]],$promptHistory),'temperature'=>(float)$generation['temperature'],'top_p'=>(float)$generation['top_p'],'max_tokens'=>$effectiveMaxTokens,'stream'=>false];
 $endpoint=setting($pdo,'endpoint',$config['ai']['endpoint']);$usage=null;$finishReason=null;$full='';$agentError=null;$agentCalls=[];$lastHeartbeat=microtime(true);
 $pdo->prepare("UPDATE messages SET interrupted=1,model_id=?,request_timestamp=?,status_label='preparing' WHERE id=? AND conversation_id=? AND role='assistant'")->execute([$model,$requestTimestamp,$assistantMessageId,$id]);
 $sendEvent('meta',['run_id'=>$runId,'message_id'=>$assistantMessageId,'model_label'=>$displayModel,'agent'=>false,'tool_dispatch'=>true]);
 $sendEvent('trace_stage',['stage'=>'model_ready','label'=>'Llama request prepared','detail'=>'Model: '.$displayModel.' · Endpoint: '.(parse_url($endpoint,PHP_URL_HOST)?:'local endpoint')]);
 $roundMaxTokens=(int)$payload['max_tokens'];
 $sendEvent('model_request',['model'=>$displayModel,'endpoint_host'=>parse_url($endpoint,PHP_URL_HOST)?:'local endpoint','stream'=>true,'transport'=>'agent_managed_openai_compatible_sse','message_count'=>count($payload['messages']),'temperature'=>$payload['temperature'],'configured_max_tokens'=>$payload['max_tokens'],'agent_round_max_tokens_ceiling'=>$roundMaxTokens,'prompt_characters'=>array_sum(array_map(static fn($m)=>strlen((string)($m['content']??'')),$payload['messages'])),'messages'=>array_map(static fn($m)=>['role'=>$m['role']??'unknown','characters'=>strlen((string)($m['content']??'')),'content_preview'=>mb_substr((string)($m['content']??''),0,8000)],$payload['messages'])]);
 try{
  if(file_exists($stopFile)||file_exists($messageStopFile)){throw new RuntimeException('LAC_AGENT_STOPPED');}
  require_once LAC_ROOT.'/app/chat/orchestrator.php';
  $generationContext=['conversation_id'=>$id,'message_id'=>$assistantMessageId,'source_message_id'=>$sourceAssistantId?:null,'action'=>$mode,'model_id'=>$model,'run_id'=>$runId];
  require_once LAC_ROOT.'/app/agent/tool_logger.php';
  lac_agent_log('generation_requested',$generationContext);
  $sendEvent('generation_context',$generationContext);
  $autonomyPolicy=json_decode((string)setting($pdo,'agent_autonomy_policy','{}'),true);if(!is_array($autonomyPolicy))$autonomyPolicy=[];
  $intentUserRequest='';
  for($intentHistoryIndex=count($promptHistory)-1;$intentHistoryIndex>=0;$intentHistoryIndex--){
   if(($promptHistory[$intentHistoryIndex]['role']??'')==='user'){
    $intentUserRequest=(string)($promptHistory[$intentHistoryIndex]['content']??'');
    break;
   }
  }
  $agentContext=array_merge($generationContext,['user_request'=>$intentUserRequest,'autonomy_policy'=>$autonomyPolicy,'sandbox'=>(bool)$conv['sandbox'],'python'=>getenv('LOCAL_AI_PYTHON')?:(PHP_OS_FAMILY==='Windows'?'python':'python3'),'endpoint'=>$endpoint,'timeout'=>(int)$generation['timeout'],'stop_files'=>[$stopFile,$messageStopFile]]);
  $streamedText='';
  $sendEvent('trace_stage',['stage'=>'model_dispatch','label'=>'Chat model decision','detail'=>'Round 0 is handled by the Chat Orchestrator. Agent Runtime starts only if the model requests handoff.']);
  $agentEmit=function(string $event,array $data)use($sendEvent,$stopFile,$messageStopFile,&$streamedText,$pdo,$assistantMessageId,$mode,$baseText){
   if(file_exists($stopFile)||file_exists($messageStopFile))return;
   if($event==='clear_delta'){
    $streamedText='';$pdo->prepare("UPDATE messages SET content=?,status_label='preparing' WHERE id=?")->execute([$mode==='continue'?$baseText:'',$assistantMessageId]);
    $sendEvent('clear_delta',$data);return;
   }
   if($event==='delta'){$delta=(string)($data['text']??'');if($delta!==''){$streamedText.=$delta;$persist=$pdo->prepare("UPDATE messages SET content=CONCAT(content,?),status_label='typing' WHERE id=?");$persist->execute([$delta,$assistantMessageId]);}}
   $sendEvent($event,$data);
  };
  try{
   $agentResult=lac_chat_orchestrate($pdo,$endpoint,$payload,$enabledToolIds,$agentContext,$agentEmit);
  }catch(Throwable $runtimeCrash){
   if($runtimeCrash->getMessage()==='LAC_AGENT_STOPPED')throw $runtimeCrash;
   // A Runtime exception must not automatically cancel the user task. Restart
   // orchestration once from the original prompt with tools disabled to prevent
   // duplicate external side effects after an uncertain execution boundary.
   if(function_exists('lac_agent_log'))lac_agent_log('agent_runtime_recovery_started',['run_id'=>$runId,'message_id'=>$assistantMessageId,'exception_class'=>get_class($runtimeCrash),'exception_message'=>substr($runtimeCrash->getMessage(),0,500),'recovery'=>'fresh_model_round_without_tools']);
   if($streamedText!=='')$agentEmit('clear_delta',['reason'=>'runtime_recovery_started']);
   $recoveryPayload=$payload;
   $recoveryPayload['messages'][]=['role'=>'user','content'=>'RUNTIME RECOVERY: The previous Agent runtime crashed before it could safely complete orchestration. Do not assume any tool action succeeded. Tools are unavailable for this recovery attempt. Answer the original user request as fully as possible using only the conversation context and your own capabilities. Clearly disclose any part that cannot be verified or completed. Return the final answer directly as plain user-facing text or Markdown. Do not wrap final answers in JSON.'];
   $recoveryContext=$agentContext;$recoveryContext['run_id']=$runId.'_recovery';
   try{$agentResult=lac_chat_orchestrate($pdo,$endpoint,$recoveryPayload,[],$recoveryContext,$agentEmit);}
   catch(Throwable $recoveryCrash){
    if($recoveryCrash->getMessage()==='LAC_AGENT_STOPPED')throw $recoveryCrash;
    if(function_exists('lac_agent_log'))lac_agent_log('agent_runtime_recovery_failed',['run_id'=>$runId,'message_id'=>$assistantMessageId,'exception_class'=>get_class($recoveryCrash),'exception_message'=>substr($recoveryCrash->getMessage(),0,500)]);
    throw new RuntimeException('Agent runtime recovery failed: '.substr($recoveryCrash->getMessage(),0,300),0,$recoveryCrash);
   }
  }
  // A bounded protocol failure is recoverable at the request level too. Do not
  // surface the Agent's protocol diagnostic as the answer; make one isolated
  // no-tools synthesis attempt so the user still gets a useful final response.
  if(!empty($agentResult['protocol_error'])&&is_array($agentResult['protocol_error'])){
   $protocolFailure=$agentResult['protocol_error'];
   if(function_exists('lac_agent_log'))lac_agent_log('agent_protocol_recovery_started',['run_id'=>$runId,'message_id'=>$assistantMessageId,'protocol_error'=>$protocolFailure,'recovery'=>'fresh_model_round_without_tools']);
   if($streamedText!=='')$agentEmit('clear_delta',['reason'=>'protocol_recovery_started']);
   $protocolRecoveryPayload=$payload;
   $protocolRecoveryPayload['messages'][]=['role'=>'user','content'=>'RUNTIME PROTOCOL RECOVERY: The previous Agent response could not be validated as a safe executable protocol message. Do not repeat the invalid protocol. Tools are unavailable for this recovery attempt. Answer the original user request as fully as possible using the conversation context and your own capabilities. Do not claim that any tool ran or that an external action occurred. Return the final answer directly as plain user-facing text or Markdown. Do not wrap final answers in JSON.'];
   $protocolRecoveryContext=$agentContext;$protocolRecoveryContext['run_id']=$runId.'_protocol_recovery';
   try{$agentResult=lac_chat_orchestrate($pdo,$endpoint,$protocolRecoveryPayload,[],$protocolRecoveryContext,$agentEmit);}
   catch(Throwable $protocolRecoveryCrash){
    if($protocolRecoveryCrash->getMessage()==='LAC_AGENT_STOPPED')throw $protocolRecoveryCrash;
    if(function_exists('lac_agent_log'))lac_agent_log('agent_protocol_recovery_failed',['run_id'=>$runId,'message_id'=>$assistantMessageId,'exception_class'=>get_class($protocolRecoveryCrash),'exception_message'=>substr($protocolRecoveryCrash->getMessage(),0,500)]);
    throw new RuntimeException('AGENT_PROTOCOL_RECOVERY_FAILED',0,$protocolRecoveryCrash);
   }
  }
  // Tool failures are handled as non-terminal tool_result messages inside Agent Loop.
  // They must never set the overall chat request's $agentError state.
  if(!empty($agentResult['tool_error'])&&is_array($agentResult['tool_error'])){
   if(function_exists('lac_agent_log'))lac_agent_log('agent_nonterminal_tool_error_returned',['run_id'=>$runId,'tool'=>$agentResult['tool_error']['tool']??null,'error_code'=>$agentResult['tool_error']['error_code']??null]);
  }
  $executionState=is_array($agentResult['tool_execution_state']??null)?$agentResult['tool_execution_state']:[];
  $sendEvent('model_final',['model'=>$displayModel,'finish_reason'=>$agentResult['finish_reason']??null,'tool_call_count'=>count($agentResult['calls']??[]),'response_characters'=>strlen((string)($agentResult['content']??'')),'task_state'=>$agentResult['task_state']??null,'tool_execution_summary'=>['has_executed_tool'=>(bool)($executionState['has_executed_tool']??false),'committed_tool_count'=>(int)($executionState['committed_tool_count']??0),'failed_or_unverified_tool_count'=>(int)($executionState['failed_or_unverified_tool_count']??0),'successful_tools'=>$executionState['successful_tools']??[],'failed_tools'=>$executionState['failed_tools']??[]]]);
  $full=(string)($agentResult['content']??'');$usage=$agentResult['usage']??null;$finishReason=$agentResult['finish_reason']??null;$agentCalls=$agentResult['calls']??[];
  if($full===''){$full='The model returned no valid content. No tool action is confirmed as executed. Please retry.';}
  $persistedFull=($mode==='continue'?$baseText:'').$full;$pdo->prepare("UPDATE messages SET content=?,status_label='typing' WHERE id=?")->execute([$persistedFull,$assistantMessageId]);
  // Token deltas have already reached the browser. Send only a fallback or missing suffix.
  if($streamedText==='')$sendEvent('delta',['text'=>$full]);
  elseif(str_starts_with($full,$streamedText)&&strlen($full)>strlen($streamedText))$sendEvent('delta',['text'=>substr($full,strlen($streamedText))]);
 }catch(Throwable $e){if($e->getMessage()==='LAC_AGENT_STOPPED'){if(function_exists('lac_agent_log'))lac_agent_log('agent_terminal',['run_id'=>$runId,'task_state'=>'cancelled','outcome'=>'cancelled','terminal_source'=>'request_stop']);$agentError=null;$q=$pdo->prepare('SELECT content FROM messages WHERE id=?');$q->execute([$assistantMessageId]);$full=(string)($q->fetchColumn()?:'');}else{
  if(function_exists('lac_agent_log'))lac_agent_log('agent_terminal',['run_id'=>$runId,'task_state'=>'completed_with_limitations','outcome'=>'partial','terminal_source'=>'request_recovery_exhausted','error_code'=>'AGENT_RUNTIME_FAILURE']);
  $agentError='AGENT_RUNTIME_FAILURE';$partial=(string)($streamedText??'');
  // Never expose PHP exception details, receipts, or trace diagnostics in chat.
  // Retract any unvalidated streamed draft if all recovery attempts failed.
  $fallback='The request could not be completed due to an internal error. Please try again.';
  if($partial!=='')$agentEmit('clear_delta',['reason'=>'runtime_recovery_exhausted']);
  $full=($mode==='continue'?$baseText:'').$fallback;
  if(function_exists('lac_agent_log'))lac_agent_log('agent_request_recovered_from_runtime_exception',['run_id'=>$runId,'message_id'=>$assistantMessageId,'exception_class'=>get_class($e),'exception_message'=>substr($e->getMessage(),0,500),'partial_characters'=>mb_strlen($partial),'user_facing_error_suppressed'=>true]);
  $pdo->prepare("UPDATE messages SET content=?,status_label='completed_with_limitations' WHERE id=?")->execute([$full,$assistantMessageId]);
  $sendEvent('delta',['text'=>$fallback]);
 }}
 $stopped=file_exists($stopFile)||file_exists($messageStopFile);@unlink($stopFile);@unlink($messageStopFile);@unlink($runDir.'/'.$runId.'.json');
 $elapsed=max(0,(int)round(microtime(true)-$generationStarted));$incomplete=$stopped||in_array($finishReason,['length','max_tokens'],true)?1:0;$statusLabel=$stopped?'stopped':($agentError?'completed_with_limitations':($incomplete?'limit reached':'complete'));$tokenCount=isset($usage['total_tokens'])?(int)$usage['total_tokens']:null;
 $pdo->prepare('UPDATE messages SET interrupted=?,model_id=?,request_timestamp=?,elapsed_seconds=elapsed_seconds+?,status_label=?,token_count=? WHERE id=? AND conversation_id=?')->execute([$incomplete,$model,$requestTimestamp,$elapsed,$statusLabel,$tokenCount,$assistantMessageId,$id]);
 $sendEvent('finish',['reason'=>$finishReason??'stop']);if($usage)$sendEvent('usage',$usage);
 $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);
 echo "event: done\ndata: ".json_encode(['stopped'=>$stopped,'usage'=>$usage,'finish_reason'=>$finishReason,'characters'=>mb_strlen($full),'message_id'=>$assistantMessageId,'agent_calls'=>$agentCalls??[]])."\n\n";@ob_flush();flush();exit;
}
