<?php
/** Build the immutable capability snapshot exposed to the model for this run. */
function lac_agent_loop_enabled_tool_map(array $enabledToolIds,array $catalog): array {
 $enabledToolMap=[];
 foreach($enabledToolIds as $id){
  $id=strtolower(trim((string)$id));
  if($id!==''&&isset($catalog[$id]))$enabledToolMap[$id]=$catalog[$id];
 }
 return $enabledToolMap;
}

/**
 * Decode exactly one canonical Agent message.
 *
 * Ordinary prose remains a direct final answer. A response that embeds a
 * protocol-looking JSON object in prose is not silently downgraded to final:
 * it is rejected as mixed output so the model can repair the turn.
 */
function lac_agent_loop_decode_protocol(string $content): ?array {
 $raw=trim($content);
 if($raw==='')return null;

 try{
  $decoded=json_decode($raw,false,64,JSON_THROW_ON_ERROR);
  if($decoded instanceof stdClass){
   $message=(array)$decoded;
   if(array_key_exists('type',$message))return $message;
   return ['type'=>'final','content'=>$content];
  }
  return ['type'=>'final','content'=>$content];
 }catch(Throwable $error){
  if(str_starts_with($raw,'{'))return ['type'=>'invalid','error_code'=>'MALFORMED_PROTOCOL_JSON','message'=>'The response begins with a JSON object but is not valid JSON.'];
 }

 // Search balanced JSON objects without being confused by braces in strings.
 $length=strlen($raw);$inString=false;$escaped=false;$depth=0;$start=null;
 for($i=0;$i<$length;$i++){
  $ch=$raw[$i];
  if($inString){
   if($escaped){$escaped=false;continue;}
   if($ch==='\\'){$escaped=true;continue;}
   if($ch==='"')$inString=false;
   continue;
  }
  if($ch==='"'){$inString=true;continue;}
  if($ch==='{'){
   if($depth===0)$start=$i;
   $depth++;
  }elseif($ch==='}'&&$depth>0){
   $depth--;
   if($depth===0&&$start!==null){
    $candidate=substr($raw,$start,$i-$start+1);
    $embedded=json_decode($candidate,false,64);
    if($embedded instanceof stdClass){
     $fields=get_object_vars($embedded);
     if(isset($fields['type'])&&is_string($fields['type'])&&in_array($fields['type'],['tool_help_request','tool_reconsider','tool_call','final'],true)){
      return ['type'=>'invalid','error_code'=>'MIXED_PROTOCOL_OUTPUT','message'=>'A protocol message was embedded in surrounding prose. Emit exactly one JSON protocol object with no surrounding text, or answer in plain text without a protocol object.'];
     }
    }
    $start=null;
   }
  }
 }
 // Malformed protocol-like fragments should not be mistaken for successful finals.
 if(preg_match('/\\{\\s*"type"\\s*:\\s*"(?:tool_help_request|tool_call|final)"/',$raw)){
  return ['type'=>'invalid','error_code'=>'MALFORMED_EMBEDDED_PROTOCOL','message'=>'The response contains a malformed protocol-looking JSON fragment.'];
 }
 return ['type'=>'final','content'=>$content];
}


/** Validate the exact canonical model-to-runtime envelope before dispatch. */
function lac_agent_loop_validate_message(array $message): array {
 $type=$message['type']??null;
 if(!is_string($type))return ['valid'=>false,'error'=>'PROTOCOL_TYPE_REQUIRED','details'=>[['code'=>'PROTOCOL_TYPE_REQUIRED','path'=>'type','message'=>'A string protocol type is required.','retryable'=>true]]];
 $fields=[
  'tool_help_request'=>['type','tool'],
  'tool_reconsider'=>['type','tool','reason'],
  'tool_call'=>['type','tool','tool_call_id','arguments'],
  'final'=>['type','content'],
 ];
 if(!isset($fields[$type]))return ['valid'=>false,'error'=>'UNKNOWN_PROTOCOL_TYPE','details'=>[['code'=>'UNKNOWN_PROTOCOL_TYPE','path'=>'type','message'=>'The message type is not part of the canonical Agent protocol.','retryable'=>false]]];
 $actual=array_keys($message);$expected=$fields[$type];$extra=array_values(array_diff($actual,$expected));$missing=array_values(array_diff($expected,$actual));
 if($extra||$missing)return ['valid'=>false,'error'=>'INVALID_PROTOCOL_FIELDS','details'=>[['code'=>'INVALID_PROTOCOL_FIELDS','path'=>'$','message'=>'Unexpected fields: '.implode(', ',$extra).'; missing fields: '.implode(', ',$missing).'.','retryable'=>true]]];
 if($type==='tool_help_request'&&(!is_string($message['tool'])||trim($message['tool'])===''))
  return ['valid'=>false,'error'=>'INVALID_TOOL_NAME','details'=>[['code'=>'INVALID_TOOL_NAME','path'=>'tool','message'=>'tool must be a non-empty string.','retryable'=>true]]];
 if($type==='tool_reconsider'){
  if(!is_string($message['tool'])||trim($message['tool'])==='')return ['valid'=>false,'error'=>'INVALID_TOOL_NAME','details'=>[['code'=>'INVALID_TOOL_NAME','path'=>'tool','message'=>'tool must be a non-empty string.','retryable'=>true]]];
  if(!is_string($message['reason'])||trim($message['reason'])==='')return ['valid'=>false,'error'=>'TOOL_RECONSIDER_REASON_REQUIRED','details'=>[['code'=>'TOOL_RECONSIDER_REASON_REQUIRED','path'=>'reason','message'=>'Provide a concise reason why the helped tool is unsuitable.','retryable'=>true]]];
  if(strlen($message['reason'])>2000)return ['valid'=>false,'error'=>'TOOL_RECONSIDER_REASON_TOO_LONG','details'=>[['code'=>'TOOL_RECONSIDER_REASON_TOO_LONG','path'=>'reason','message'=>'reason must not exceed 2000 bytes.','retryable'=>true]]];
 }
 if($type==='tool_call'){
  if(!is_string($message['tool'])||trim($message['tool'])==='')
   return ['valid'=>false,'error'=>'INVALID_TOOL_NAME','details'=>[['code'=>'INVALID_TOOL_NAME','path'=>'tool','message'=>'tool must be a non-empty string.','retryable'=>true]]];
  if(!is_string($message['tool_call_id'])||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$message['tool_call_id']))
   return ['valid'=>false,'error'=>'INVALID_TOOL_CALL_ID','details'=>[['code'=>'INVALID_TOOL_CALL_ID','path'=>'tool_call_id','message'=>'tool_call_id must be 1–128 characters using letters, digits, dot, underscore, colon, or hyphen.','retryable'=>true]]];
  if(!($message['arguments'] instanceof stdClass))
   return ['valid'=>false,'error'=>'ARGUMENTS_MUST_BE_OBJECT','details'=>[['code'=>'ARGUMENTS_MUST_BE_OBJECT','path'=>'arguments','message'=>'Tool arguments must be a JSON object.','retryable'=>true]]];
 }
 if($type==='final'&&(!is_string($message['content'])||trim($message['content'])===''))
  return ['valid'=>false,'error'=>'EMPTY_FINAL_CONTENT','details'=>[['code'=>'EMPTY_FINAL_CONTENT','path'=>'content','message'=>'Final content must be a non-empty string.','retryable'=>true]]];
 return ['valid'=>true,'type'=>$type];
}

/** Stable machine-readable feedback for a rejected control/protocol request. */
function lac_agent_loop_error_feedback(string $code,string $phase,string $message,bool $retryable=false,?string $tool=null,array $details=[]): array {
 return [
  'type'=>'tool_result',
  'tool'=>$tool,
  'status'=>'rejected',
  'phase'=>$phase,
  'execution_started'=>false,
  'execution_committed'=>false,
  'error'=>['code'=>$code,'message'=>$message,'details'=>$details,'retryable'=>$retryable],
 ];
}
