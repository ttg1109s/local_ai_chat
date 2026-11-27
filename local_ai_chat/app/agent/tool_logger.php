<?php
/**
 * Unified execution trace recorder.
 * Internal event names, outcomes, and diagnostics are English-only.
 */
$GLOBALS['lac_unified_trace_context'] = $GLOBALS['lac_unified_trace_context'] ?? [];

function lac_trace_sanitize(mixed $value, string $key='', int $depth=0): mixed {
 if($depth>8)return '[max_depth]';
 $normalized=strtolower(str_replace(['-','.'],'_',$key));
 if(in_array($normalized,['model_output','final_text','arguments_json'],true)){
  if(!is_string($value))return $value;
  if($normalized==='model_output'){$value=preg_replace('/<think>.*?<\/think>/si','[model reasoning omitted]',$value);$value=preg_replace('/<analysis>.*?<\/analysis>/si','[model analysis omitted]',$value);}
  $value=preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/-]+/i','$1[redacted]',$value);
  $value=preg_replace('/((?:api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret)=)[^&\s]+/i','$1[redacted]',$value);
  $value=preg_replace('/("(?:api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret)"\s*:\s*")[^"]*(")/i','$1[redacted]$2',$value);
  $value=preg_replace('/\bsk-[A-Za-z0-9_-]{12,}\b/','[redacted_key]',$value);
  if(strlen($value)>262144)return substr($value,0,262144).'…[trace_truncated]';
  return $value;
 }
 $sensitive=['authorization','password','api_key','secret','cookie','session','credential','access_token','refresh_token','prompt','messages','content','stdout','stderr','body','response_body','system_prompt','user_request','chain_of_thought'];
 foreach($sensitive as $needle)if($normalized===$needle||str_contains($normalized,$needle))return '[redacted]';
 if(is_array($value)){
  $out=[];$count=0;
  foreach($value as $k=>$v){if($count++>=150){$out['_truncated']='item_limit';break;}$out[$k]=lac_trace_sanitize($v,(string)$k,$depth+1);}
  return $out;
 }
 if(is_object($value))return '[object]';
 if(is_string($value)){
  $value=preg_replace('/(Bearer\\s+)[A-Za-z0-9._~+\\/-]+/i','$1[redacted]',$value);
  $value=preg_replace('/((?:api[_-]?key|access[_-]?token|refresh[_-]?token|password|secret)=)[^&\\s]+/i','$1[redacted]',$value);
  $value=preg_replace('/\\bsk-[A-Za-z0-9_-]{12,}\\b/','[redacted_key]',$value);
  if(strlen($value)>2000)return mb_substr($value,0,2000).'…[truncated]';
  return $value;
 }
 if(is_resource($value))return '[resource]';
 return $value;
}
function lac_unified_trace_set_context(array $context): void {
 $GLOBALS['lac_unified_trace_context']=array_merge($GLOBALS['lac_unified_trace_context']??[],$context);
}
function lac_trace_event_defaults(string $event): array {
 $map=[
  'agent_completed'=>['lifecycle','success','info'],
  'agent_terminal'=>['lifecycle','observed','info'],
  'agent_completed_with_limitations'=>['lifecycle','partial','warning'],
  'agent_failed'=>['lifecycle','failed','error'],
  'agent_cancelled'=>['lifecycle','cancelled','warning'],
  'agent_interrupted'=>['lifecycle','unknown','error'],
  'agent_tool_call_proposed'=>['protocol','proposed','info'],
  'agent_tool_call_validated'=>['protocol','validated','info'],
  'agent_tool_call_rejected'=>['protocol','rejected','warning'],
  'agent_tool_selection_rejected'=>['authorization','rejected','warning'],
  'tool_denied'=>['authorization','denied','warning'],
  'tool_started'=>['execution','started','info'],
  'tool_start'=>['execution','started','info'],
  'tool_result'=>['execution','observed','info'],
  'tool_error'=>['execution','failed','error'],
  'tool_executed'=>['execution','success','info'],
  'tool_failed'=>['execution','failed','error'],
  'execution_state_transition'=>['execution','transition','info'],
  'trace_write_failed'=>['observability','degraded','error'],
 ];
 if(isset($map[$event]))return $map[$event];
 if(str_contains($event,'cancelled'))return ['lifecycle','cancelled','warning'];
 if(str_contains($event,'rejected'))return ['protocol','rejected','warning'];
 if(str_contains($event,'denied'))return ['authorization','denied','warning'];
 if(str_contains($event,'failed')||str_contains($event,'error'))return ['execution','failed','error'];
 if(str_contains($event,'completed'))return ['lifecycle','success','info'];
 if(str_contains($event,'started'))return ['lifecycle','started','info'];
 return ['execution','observed','info'];
}
/** Append one schema-v2 event. Returns false if persistence was not confirmed. */
function lac_unified_trace_write(string $event,array $data=[],string $source='system',string $level='info'): bool {
 if($event==='delta')return true;
 $ctx=$GLOBALS['lac_unified_trace_context']??[];
 $path=(string)($ctx['trace_file']??'');
 if($path==='')return lac_trace_write_failure('trace_path_missing','',((int)($ctx['sequence']??0))+1);
 [$category,$outcome,$defaultSeverity]=lac_trace_event_defaults($event);
 $now=microtime(true);$mono=hrtime(true);$seq=(int)($ctx['sequence']??0)+1;
 $rawPayload=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
 $row=[
  'schema_version'=>2,'event_id'=>'evt_'.bin2hex(random_bytes(12)),'sequence'=>$seq,
  'timestamp'=>gmdate('Y-m-d\\TH:i:s',$now).sprintf('.%03dZ',(int)(($now-floor($now))*1000)),
  'recorded_at'=>gmdate('Y-m-d\\TH:i:s',(int)$now).'Z',
  'elapsed_ms'=>isset($ctx['started_ns'])?round(($mono-(int)$ctx['started_ns'])/1e6,3):null,
  'trace_id'=>$data['trace_id']??$ctx['trace_id']??null,
  'run_id'=>$data['run_id']??$ctx['run_id']??null,
  'message_id'=>$data['message_id']??$ctx['message_id']??null,
  'conversation_id'=>$data['conversation_id']??$ctx['conversation_id']??null,
  'round_id'=>$data['round_id']??$data['round']??$ctx['round_id']??null,
  'request_id'=>$data['request_id']??$ctx['request_id']??null,
  'tool_call_id'=>$data['tool_call_id']??$data['call_id']??$ctx['tool_call_id']??null,
  'execution_id'=>$data['execution_id']??$ctx['execution_id']??null,
  'batch_id'=>$data['batch_id']??$ctx['batch_id']??null,
  'source'=>$source,'category'=>$data['_category']??$category,'event'=>$event,
  'severity'=>$data['_severity']??($level!==''?$level:$defaultSeverity),
  'outcome'=>$data['_outcome']??$outcome,
  'duration_ms'=>$data['duration_ms']??null,
  'payload_bytes'=>$rawPayload===false?null:strlen($rawPayload),
  'payload_hash'=>$rawPayload===false?null:hash('sha256',$rawPayload),
  'data'=>lac_trace_sanitize($data)
 ];
 unset($row['data']['_category'],$row['data']['_severity'],$row['data']['_outcome']);
 $json=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
 if($json===false)return false;
 $dir=dirname($path);
 if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))return lac_trace_write_failure('directory_create_failed',$path,$seq);
 $fh=@fopen($path,'ab');
 if(!$fh)return lac_trace_write_failure('file_open_failed',$path,$seq);
 $ok=false;
 if(@flock($fh,LOCK_EX)){
  @fseek($fh,0,SEEK_END);$start=@ftell($fh);$line=$json.PHP_EOL;$offset=0;$length=strlen($line);
  while($offset<$length){$written=@fwrite($fh,substr($line,$offset));if($written===false||$written===0)break;$offset+=$written;}
  $ok=$offset===$length&&@fflush($fh);
  if(!$ok&&is_int($start)){@ftruncate($fh,$start);@fflush($fh);}
  @flock($fh,LOCK_UN);
 }else{$ok=false;}
 @fclose($fh);
 if(!$ok)return lac_trace_write_failure('append_or_flush_failed',$path,$seq);
 $ctx['sequence']=$seq;$GLOBALS['lac_unified_trace_context']=$ctx;
 $GLOBALS['lac_unified_trace_health']=['healthy'=>true,'last_success_at'=>gmdate(DATE_ATOM),'last_error'=>null];
 return true;
}
function lac_trace_write_failure(string $reason,string $path,int $sequence): bool {
 $health=$GLOBALS['lac_unified_trace_health']??[];
 $health=['healthy'=>false,'last_error'=>$reason,'failed_sequence'=>$sequence,'failed_at'=>gmdate(DATE_ATOM)];
 $health['failure_count']=(int)($health['failure_count']??0)+1;
 $GLOBALS['lac_unified_trace_health']=$health;
 $count=(int)$health['failure_count'];
 if($count===1||($count&($count-1))===0)error_log('[LAC_TRACE_DEGRADED] '.$reason.' sequence='.$sequence.' path='.basename($path));
 return false;
}
function lac_unified_trace_health(): array {
 return $GLOBALS['lac_unified_trace_health']??['healthy'=>true,'failure_count'=>0,'last_error'=>null];
}
function lac_unified_trace_cleanup(string $directory,int $retentionDays=7): array {
 $cutoff=time()-max(1,$retentionDays)*86400;$deleted=0;$bytes=0;
 if(!is_dir($directory))return ['deleted'=>0,'bytes'=>0];
 foreach(new DirectoryIterator($directory) as $file){
  if($file->isDot()||!$file->isFile()||$file->getExtension()!=='jsonl')continue;
  if($file->getMTime()>=$cutoff)continue;
  $path=$file->getPathname();$size=$file->getSize();
  if(@unlink($path)){$deleted++;$bytes+=$size;}
 }
 return ['deleted'=>$deleted,'bytes'=>$bytes];
}
function lac_agent_log(string $event,array $data=[]): void {
 $source='agent';
 if(str_starts_with($event,'model_')||str_starts_with($event,'native_'))$source='model';
 elseif(str_starts_with($event,'tool_'))$source='tool';
 elseif(str_starts_with($event,'generation_'))$source='system';
 [$category,$outcome,$severity]=lac_trace_event_defaults($event);
 if($event==='agent_terminal'&&isset($data['outcome']))$outcome=(string)$data['outcome'];
 $data['_category']=$category;$data['_outcome']=$outcome;$data['_severity']=$severity;
 lac_unified_trace_write($event,$data,$source,$severity);
}
