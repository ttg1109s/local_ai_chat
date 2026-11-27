<?php
require_once __DIR__.'/model_adapter.php';
require_once __DIR__.'/loop/protocol.php';
/** Normalize one OpenAI-compatible choice through the provider adapter boundary. */
function lac_agent_choice_content(array $choice): array {
 $payload=$choice['delta']??$choice['message']??[];
 if(!is_array($payload))$payload=[];
 $content=$payload['content']??'';
 $content=lac_agent_adapter_normalize_content($content);
 return ['content'=>$content,'finish_reason'=>$choice['finish_reason']??null];
}
/** Detect provider-native call metadata without parsing or executing it. */
function lac_agent_choice_has_native_tool_output(array $choice): bool {
 $payload=$choice['delta']??$choice['message']??[];
 return is_array($payload)&&(isset($payload['tool_calls'])||isset($payload['function_call']));
}
/** Extract the standard OpenAI-compatible completion envelope. */
function lac_agent_completion_payload(array $decoded): array {
  $choice=$decoded['choices'][0]??null;
  if(!is_array($choice))return ['content'=>'','finish_reason'=>null,'recognized'=>false];
  $normalized=lac_agent_choice_content($choice);
  return $normalized+['recognized'=>true,'usage'=>is_array($decoded['usage']??null)?$decoded['usage']:null];
}
/** Incrementally emit only a validated final protocol body's user-facing text. */
function lac_agent_stream_final_delta(string $content,?int &$offset,?string &$mode,string &$escape,int &$escapeNeed,string &$utf8Pending,bool &$streamed,?callable $emit): void {
 if(!$emit)return;
 if($mode===null){
  if(preg_match('/^\s*\{\s*"type"\s*:\s*"final"\s*,/s',$content,$typeMatch)){
   $typeLength=strlen($typeMatch[0]);$tail=substr($content,$typeLength);
   if(!preg_match('/"content"\s*:\s*"/s',$tail,$contentMatch,PREG_OFFSET_CAPTURE))return;
   $mode='json';$offset=$typeLength+$contentMatch[0][1]+strlen($contentMatch[0][0]);
  }else{
   // Begin provisional streaming only when the
   // first non-whitespace byte proves this is not a JSON control/envelope.
   if(trim($content)==='')return;
   if(str_starts_with(ltrim($content),'{'))return;
   $mode='raw';$offset=0;
  }
 }
 $offset=$offset??0;$length=strlen($content);$plain='';
 if($mode==='raw'){
  if($offset<$length){$plain=substr($content,$offset);$offset=$length;}
 }else{
  while($offset<$length){
   $ch=$content[$offset++];
   if($escapeNeed>0){
    $escape.=$ch;$escapeNeed--;
    if($escapeNeed===0){$decoded=json_decode('"'.$escape.'"',true);if(is_string($decoded))$plain.=$decoded;$escape='';}
    continue;
   }
   if($escape!==''){
    $escape.=$ch;
    if($ch==='u'){$escapeNeed=4;continue;}
    $decoded=json_decode('"'.$escape.'"',true);if(is_string($decoded))$plain.=$decoded;$escape='';continue;
   }
   if($ch==='"')break;
   if($ch==='\\'){$escape='\\';continue;}
   $plain.=$ch;
  }
 }
 if($plain!==''){$candidate=$utf8Pending.$plain;$safeLength=lac_agent_utf8_complete_prefix_length($candidate);$safeText=substr($candidate,0,$safeLength);$utf8Pending=substr($candidate,$safeLength);if($safeText!==''){$emit('delta',['text'=>$safeText]);$streamed=true;}}
}
/** Return the byte length of the longest prefix ending on a UTF-8 code-point boundary. */
function lac_agent_utf8_complete_prefix_length(string $value): int {
 $length=strlen($value);if($length===0)return 0;$index=$length-1;while($index>=0&&(ord($value[$index])&0xC0)===0x80)$index--;if($index<0)return 0;$lead=ord($value[$index]);$expected=($lead&0x80)===0?1:((($lead&0xE0)===0xC0)?2:((($lead&0xF0)===0xE0)?3:((($lead&0xF8)===0xF0)?4:1)));$available=$length-$index;return $available<$expected?$index:$length;
}
/** HTTP/SSE model transport for direct text responses and Unified Agent JSON control messages. */
function lac_agent_model_request(string $endpoint,array $payload,int $timeout,array $stopFiles=[],?callable $emit=null,array $trace=[]): array {
 $requestId=bin2hex(random_bytes(8));$requestStarted=microtime(true);$firstByteAt=null;$firstTokenAt=null;
 $messageChars=0;foreach(($payload['messages']??[]) as $message)$messageChars+=strlen((string)($message['content']??''));
 $endpointHost=(string)(parse_url($endpoint,PHP_URL_HOST)?:'local-endpoint');
 $requestEvent=['run_id'=>$trace['run_id']??null,'request_id'=>$requestId,'round'=>$trace['round']??null,'endpoint_host'=>$endpointHost,'message_count'=>count($payload['messages']??[]),'message_chars'=>$messageChars,'timeout_seconds'=>$timeout>0?$timeout:null,'timeout_policy'=>$timeout>0?'agent_run_deadline':'manual_cancellation_only','stream'=>true,'protocol'=>'openai_compatible_sse','model'=>$payload['model']??null,'max_tokens'=>$payload['max_tokens']??null,'temperature'=>$payload['temperature']??null,'top_p'=>$payload['top_p']??null];if($emit)$emit('model_request_started',$requestEvent);else lac_agent_log('model_request_started',$requestEvent);
 $payload['stream']=true;
 $ch=curl_init($endpoint);
 $raw='';$lineBuffer='';$content='';$finalStreamed=false;$finalStreamOffset=null;$finalStreamMode=null;$finalEscape='';$finalEscapeNeed=0;$finalUtf8Pending='';$sseChunkCount=0;$stopRequested=false;$usage=null;$finishReason=null;$httpError=null;$responseError=null;$responseTooLarge=false;$unsupportedNativeToolOutput=false;$maxResponseBytes=8*1024*1024;

 // Stream ordinary text/Markdown provisionally; JSON control messages stay private.
 // The caller validates the completed response and may retract provisional text.
 $streamFinalContent=static function()use(&$content,&$finalStreamOffset,&$finalStreamMode,&$finalEscape,&$finalEscapeNeed,&$finalUtf8Pending,&$finalStreamed,$emit):void{lac_agent_stream_final_delta($content,$finalStreamOffset,$finalStreamMode,$finalEscape,$finalEscapeNeed,$finalUtf8Pending,$finalStreamed,$emit);};
 $consumeLine=static function(string $line)use(&$content,&$usage,&$finishReason,$streamFinalContent,&$firstTokenAt,$requestStarted,$trace,$requestId,$emit,&$responseError,&$unsupportedNativeToolOutput):void{
  $line=trim($line);
  if($line===''||!str_starts_with($line,'data:'))return;
  $data=trim(substr($line,5));
  if($data===''||$data==='[DONE]')return;
  $obj=json_decode($data,true);
  if(!is_array($obj))return;
  if(isset($obj['error'])){$error=$obj['error'];$responseError=is_string($error)?$error:(is_array($error)?(string)($error['message']??json_encode($error)):'Unknown model endpoint error');return;}
  if(isset($obj['usage'])&&is_array($obj['usage']))$usage=$obj['usage'];
  $choice=$obj['choices'][0]??null;
  if(!is_array($choice))return;
  if(lac_agent_choice_has_native_tool_output($choice))$unsupportedNativeToolOutput=true;
  $normalized=lac_agent_choice_content($choice);
  if(($normalized['finish_reason']??null)!==null)$finishReason=$normalized['finish_reason'];
  $piece=$normalized['content'];
  if($piece==='')return;
  if($firstTokenAt===null){$firstTokenAt=microtime(true);$eventData=['run_id'=>$trace['run_id']??null,'request_id'=>$requestId,'round'=>$trace['round']??null,'ttft_ms'=>(int)round(($firstTokenAt-$requestStarted)*1000)];if($emit)$emit('model_first_token',$eventData);else lac_agent_log('model_first_token',$eventData);}
  $content.=$piece;
  $streamFinalContent();
 };
 $isStopRequested=static function()use($stopFiles):bool{
  foreach($stopFiles as $file)if(is_string($file)&&$file!==''&&is_file($file))return true;
  return false;
 };
 if($isStopRequested())throw new RuntimeException('LAC_AGENT_STOPPED');
 curl_setopt_array($ch,[
  CURLOPT_POST=>true,
  CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: text/event-stream'],
  CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),
  CURLOPT_RETURNTRANSFER=>false,
  // No connection, total-duration, or low-throughput deadline. The user
  // controls cancellation through the stop file checked by the callbacks.
  CURLOPT_CONNECTTIMEOUT=>0,
  CURLOPT_TIMEOUT=>0,
  CURLOPT_LOW_SPEED_LIMIT=>0,
  CURLOPT_LOW_SPEED_TIME=>0,
  CURLOPT_NOPROGRESS=>false,
  CURLOPT_XFERINFOFUNCTION=>static function($curl,$downloadTotal,$downloadNow,$uploadTotal,$uploadNow)use($isStopRequested,&$stopRequested):int{
   if($isStopRequested()){$stopRequested=true;return 1;}
   return 0;
  },
  CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$raw,&$lineBuffer,$consumeLine,&$responseTooLarge,$maxResponseBytes,&$firstByteAt,$requestStarted,$trace,$requestId,$emit,$isStopRequested,&$stopRequested,&$sseChunkCount):int{
   if($isStopRequested()){$stopRequested=true;return 0;}
   $sseChunkCount++;
   if($firstByteAt===null){$firstByteAt=microtime(true);$eventData=['run_id'=>$trace['run_id']??null,'request_id'=>$requestId,'round'=>$trace['round']??null,'ttfb_ms'=>(int)round(($firstByteAt-$requestStarted)*1000)];if($emit)$emit('model_first_byte',$eventData);else lac_agent_log('model_first_byte',$eventData);}
   if(strlen($raw)+strlen($chunk)>$maxResponseBytes){$responseTooLarge=true;return 0;}
   $raw.=$chunk;$lineBuffer.=$chunk;
   while(($pos=strpos($lineBuffer,"\n"))!==false){
    $line=substr($lineBuffer,0,$pos);$lineBuffer=substr($lineBuffer,$pos+1);
    $consumeLine(rtrim($line,"\r"));
   }
   return strlen($chunk);
  }
 ]);
 $ok=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
 $curlErrno=curl_errno($ch);
 if($stopRequested||$isStopRequested()){
  $stopEvent=['run_id'=>$trace['run_id']??null,'request_id'=>$requestId,'round'=>$trace['round']??null,'curl_errno'=>$curlErrno,'duration_ms'=>(int)round((microtime(true)-$requestStarted)*1000)];
  curl_close($ch);if($emit)$emit('model_request_cancelled',$stopEvent);else lac_agent_log('model_request_cancelled',$stopEvent);
  throw new RuntimeException('LAC_AGENT_STOPPED');
 }
 $curlInfo=['http_status'=>$code,'curl_errno'=>$curlErrno,'total_time_ms'=>(int)round(((float)curl_getinfo($ch,CURLINFO_TOTAL_TIME))*1000),'namelookup_ms'=>(int)round(((float)curl_getinfo($ch,CURLINFO_NAMELOOKUP_TIME))*1000),'connect_ms'=>(int)round(((float)curl_getinfo($ch,CURLINFO_CONNECT_TIME))*1000),'starttransfer_ms'=>(int)round(((float)curl_getinfo($ch,CURLINFO_STARTTRANSFER_TIME))*1000),'download_bytes'=>(int)curl_getinfo($ch,CURLINFO_SIZE_DOWNLOAD),'response_bytes'=>strlen($raw),'streamed'=>false,'stream_chunks'=>0,'final_content_streamed'=>false,'content_chars'=>0,'usage'=>null,'finish_reason'=>null,'error'=>$err?:null];
 curl_close($ch);
 $curlInfo['run_id']=$trace['run_id']??null;$curlInfo['request_id']=$requestId;$curlInfo['round']=$trace['round']??null;$curlInfo['streamed']=$sseChunkCount>0;$curlInfo['stream_chunks']=$sseChunkCount;$curlInfo['final_content_streamed']=$finalStreamed;
 if($responseTooLarge){$curlInfo['error']='Model response exceeds the 8 MB limit.';if($emit)$emit('model_request_failed',$curlInfo);else lac_agent_log('model_request_failed',$curlInfo);throw new RuntimeException($curlInfo['error']);}
 if($ok===false||$code>=400){
  if(($curlInfo['curl_errno']??0)===CURLE_OPERATION_TIMEDOUT)$failure='The model transport was interrupted by the HTTP client or upstream server (received '.strlen($raw).' bytes). The Agent itself has no wall-clock timeout.';
  else $failure=$err?:'llama-server HTTP '.$code;
  $curlInfo['error']=$failure;if($emit)$emit('model_request_failed',$curlInfo);else lac_agent_log('model_request_failed',$curlInfo);
  throw new RuntimeException($failure);
 }
 if($lineBuffer!=='')$consumeLine(rtrim($lineBuffer,"\r"));
 // Compatibility fallback for OpenAI-compatible servers that ignore stream=true.
 if($content===''&&$raw!==''){
  $json=json_decode($raw,true);
  if(is_array($json)){
   if(isset($json['error'])){$error=$json['error'];$responseError=is_string($error)?$error:(is_array($error)?(string)($error['message']??json_encode($error)):'Unknown model endpoint error');}
   $nativeChoice=$json['choices'][0]??[];
   if(is_array($nativeChoice)&&lac_agent_choice_has_native_tool_output($nativeChoice))$unsupportedNativeToolOutput=true;
   $normalized=lac_agent_completion_payload($json);
   if(!empty($normalized['recognized'])){
    $content=(string)$normalized['content'];
    $usage=$normalized['usage']??$usage;
    $finishReason=$normalized['finish_reason']??$finishReason;
   }
   if($content!==''){

   }
  }
 }
 $curlInfo['content_chars']=mb_strlen($content);$curlInfo['usage']=$usage;$curlInfo['finish_reason']=$finishReason;$curlInfo['final_content_streamed']=$finalStreamed;
 if(function_exists('lac_agent_log'))lac_agent_log('model_output',['run_id'=>$trace['run_id']??null,'request_id'=>$requestId,'round'=>$trace['round']??null,'output_bytes'=>strlen($content),'model_output'=>$content,'stream_chunks'=>$sseChunkCount,'final_content_streamed'=>$finalStreamed]);
 if($responseError!==null||($content===''&&!$unsupportedNativeToolOutput)){
  $failure=$responseError!==null?'Model endpoint returned an error: '.mb_substr($responseError,0,1000):'Model endpoint returned HTTP 200 but no assistant text in the supported choices[0].delta.content or choices[0].message.content fields. Provider-native tool calls are not executed; use the project Unified Agent JSON protocol.';
  $curlInfo['error']=$failure;if($emit)$emit('model_request_failed',$curlInfo);else lac_agent_log('model_request_failed',$curlInfo);
  throw new RuntimeException($failure);
 }
 if($emit)$emit('model_request_completed',$curlInfo);else lac_agent_log('model_request_completed',$curlInfo);
 // The final body may have been streamed incrementally. Agent Loop still validates
 // the protocol and completion provenance before the task is marked complete.
 if($unsupportedNativeToolOutput){$nativeEvent=['run_id'=>$trace['run_id']??null,'request_id'=>$requestId,'round'=>$trace['round']??null,'reason'=>'PROVIDER_NATIVE_TOOL_CALLS_ARE_NOT_EXECUTABLE'];if($emit)$emit('model_native_tool_output_ignored',$nativeEvent);else lac_agent_log('model_native_tool_output_ignored',$nativeEvent);}
 return ['content'=>$content,'usage'=>$usage,'finish_reason'=>$finishReason,'streamed'=>$finalStreamed,'native_tool_calls'=>[],'unsupported_native_tool_output'=>$unsupportedNativeToolOutput,'request_id'=>$requestId,'duration_ms'=>(int)round((microtime(true)-$requestStarted)*1000),'ttfb_ms'=>$firstByteAt===null?null:(int)round(($firstByteAt-$requestStarted)*1000),'ttft_ms'=>$firstTokenAt===null?null:(int)round(($firstTokenAt-$requestStarted)*1000)];
}
