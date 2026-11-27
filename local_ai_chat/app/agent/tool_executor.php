<?php
require_once __DIR__.'/protocol.php';
require_once __DIR__.'/tool_registry.php';
require_once __DIR__.'/tool_permissions.php';
require_once __DIR__.'/tool_logger.php';
require_once __DIR__.'/schema_validator.php';

if (!class_exists('LacToolException')) {
 class LacToolException extends RuntimeException {
  public string $errorCode;
  public bool $retryable;
  public function __construct(string $errorCode, string $message, bool $retryable=false) {
   parent::__construct($message);
   $this->errorCode=$errorCode;
   $this->retryable=$retryable;
  }
 }
}

function lac_tool_clip_result(mixed $value,int $maxBytes=180000): mixed {
 $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
 if($json===false||strlen($json)<=$maxBytes)return $value;
 if(is_array($value)){
  $out=[];$used=0;
  foreach($value as $k=>$v){$part=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);$size=strlen((string)$part);if($used+$size>$maxBytes-1000)break;$out[$k]=$v;$used+=$size;}
  $out['_truncated']=true;$out['_truncation_note']='Result was truncated to protect the context window. Request a specific section if needed.';return $out;
 }
 return ['value'=>mb_substr((string)$value,0,40000),'_truncated'=>true];
}
function lac_tool_execute(PDO $pdo,string $name,array $args,array $permissions,array $context=[]): array {
 $catalog=lac_tool_catalog();$rid=bin2hex(random_bytes(8));$started=microtime(true);
 lac_agent_log('tool_started',['id'=>$rid,'execution_id'=>$context['execution_id']??$rid,'tool_call_id'=>$context['tool_call_id']??null,'request_id'=>$context['request_id']??null,'run_id'=>$context['run_id']??null,'tool'=>$name,'argument_keys'=>array_keys($args),
  'argument_bytes'=>strlen((string)json_encode($args,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)),
  'diagnostic_args'=>$name==='manage_websites'?['action'=>mb_substr((string)($args['action']??''),0,20),'site'=>mb_substr((string)($args['site']??''),0,160)]:null,
  'pid'=>getmypid()?:null]);
 $fail=static function(string $code,string $message,bool $retryable=false)use($rid,$name,$started,$context):array{
  $duration=(int)round((microtime(true)-$started)*1000);lac_agent_log('tool_failed',['id'=>$rid,'execution_id'=>$context['execution_id']??$rid,'tool_call_id'=>$context['tool_call_id']??null,'request_id'=>$context['request_id']??null,'run_id'=>$context['run_id']??null,'tool'=>$name,'code'=>$code,'error_message'=>mb_substr($message,0,500),'duration_ms'=>$duration,'memory_peak_bytes'=>memory_get_peak_usage(true)]);
  return ['ok'=>false,'id'=>$rid,'tool'=>$name,'error_code'=>$code,'error'=>$message,'retryable'=>$retryable,'duration_ms'=>$duration];
 };
 if(!isset($catalog[$name]))return $fail('TOOL_NOT_FOUND','Tool does not exist.');
 if(!lac_agent_has_permission($permissions,$name)){lac_agent_log('tool_denied',['id'=>$rid,'execution_id'=>$context['execution_id']??$rid,'tool_call_id'=>$context['tool_call_id']??null,'request_id'=>$context['request_id']??null,'run_id'=>$context['run_id']??null,'tool'=>$name]);return $fail('PERMISSION_DENIED','The user has not enabled this tool in the Tool List.');}
 if(($context['sandbox']??false))return $fail('SANDBOX_DISABLED','Tool is disabled in Sandbox.');
 $meta=$catalog[$name];$validation=lac_tool_validate_arguments($name,$args,$meta);
 if($validation)return $fail('INVALID_ARGUMENTS',implode(' ',$validation));
 $clip=static fn($v,$n=12000)=>mb_substr((string)$v,0,$n);
 try {
  require_once __DIR__.'/tool_handlers.php';
  $dispatchContext=$context; $dispatchContext['tool_id']=$rid;
  $out=lac_tool_dispatch($pdo,$name,$args,$meta,$dispatchContext,$clip);
  $duration=(int)round((microtime(true)-$started)*1000);
  lac_agent_log('tool_executed',['id'=>$rid,'execution_id'=>$context['execution_id']??$rid,'tool_call_id'=>$context['tool_call_id']??null,'request_id'=>$context['request_id']??null,'run_id'=>$context['run_id']??null,'tool'=>$name,'ok'=>true,'duration_ms'=>$duration,'result_bytes'=>strlen((string)json_encode($out,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR)),'memory_peak_bytes'=>memory_get_peak_usage(true)]);
  return ['ok'=>true,'id'=>$rid,'tool'=>$name,'result'=>$out,'duration_ms'=>$duration,'retryable'=>false];
 }catch(LacToolException $e){return $fail($e->errorCode,$e->getMessage(),$e->retryable);}
 catch(InvalidArgumentException $e){return $fail('INVALID_ARGUMENTS',$e->getMessage());}
 catch(DomainException $e){return $fail('RESEARCH_DUPLICATE_QUERY',$e->getMessage(),false);}
 catch(LengthException $e){return $fail('RESEARCH_BUDGET_EXHAUSTED',$e->getMessage(),false);}
 catch(Throwable $e){return $fail('EXECUTION_FAILED',$e->getMessage(),false);}
}
