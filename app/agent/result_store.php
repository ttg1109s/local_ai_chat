<?php
require_once __DIR__.'/artifact_manager.php';
function lac_result_store_dir(): string { return dirname(__DIR__,2).'/storage/agent-results'; }
function lac_result_store_put(string $executionId,array $result,?string $runId=null,string $tool='',?string $resultId=null): string {
 $dir=lac_result_store_dir();if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Could not create the Result Store.');
 $id=preg_replace('/[^a-f0-9]/i','',$executionId);if($id==='')throw new InvalidArgumentException('Invalid execution_id.');
 $path=$dir.'/'.$id.'.json';
 $payload=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
 if($payload===false)throw new RuntimeException('Could not encode the result payload.');
 $record=['schema_version'=>2,'result_id'=>$resultId,'execution_id'=>$id,'run_id'=>$runId,'tool'=>$tool,'created_at'=>date(DATE_ATOM),
  'integrity'=>['algorithm'=>'sha256','sha256'=>hash('sha256',$payload),'bytes'=>strlen($payload)],'result'=>$result];
 $json=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
 $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
 if($json===false||file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not commit the result.');}
 return 'agent-results/'.$id.'.json';
}
function lac_result_store_get(string $reference): ?array {
 $base=basename($reference);if(!preg_match('/^[a-f0-9]{8,64}\.json$/i',$base))return null;
 $path=lac_result_store_dir().'/'.$base;if(!is_file($path)||is_link($path))return null;
 $v=json_decode((string)file_get_contents($path),true);
 if(!is_array($v)||!is_array($v['result']??null))return null;
 // Legacy records remain readable; records written with integrity metadata are verified.
 if(is_array($v['integrity']??null)){
  $encoded=json_encode($v['result'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
  $actual=$encoded===false?null:hash('sha256',$encoded);
  if(!is_string($actual)||!is_string($v['integrity']['sha256']??null)||!hash_equals($v['integrity']['sha256'],$actual)||strlen((string)$encoded)!==(int)($v['integrity']['bytes']??-1)){
   if(function_exists('lac_agent_log'))lac_agent_log('data_pack_integrity_failed',['data_ref'=>'agent-results/'.$base,'expected_sha256'=>$v['integrity']['sha256']??null,'actual_sha256'=>$actual]);
   throw new RuntimeException('DATA_PACK_INTEGRITY_FAILED: stored result does not match its committed checksum.');
  }
 }
 return $v['result'];
}


/**
 * Data Pack API: immutable, content-addressed result records.
 * A reference is a locator only; the original typed PHP value remains server-side.
 */
function lac_data_pack_put(string $runId,string $tool,string $executionId,array $result): array {
 $resultId='res_'.substr(hash('sha256',$runId.'|'.$executionId.'|'.$tool),0,24);
 $result['result_id']=$resultId;
 $reference=lac_result_store_put($executionId,$result,$runId,$tool,$resultId);
 $manifestStatus='pending';$manifestError=null;
 if(is_file(__DIR__.'/manifest_manager.php')){
  require_once __DIR__.'/manifest_manager.php';
  try{lac_data_pack_manifest_build($reference,$result,['run_id'=>$runId,'execution_id'=>$executionId,'result_id'=>$resultId,'tool'=>$tool,'created_at'=>date(DATE_ATOM),'sha256'=>hash('sha256',(string)json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR))]);$manifestStatus='committed';}
  catch(Throwable $e){$manifestStatus='failed';$manifestError=$e->getMessage();if(function_exists('lac_agent_log'))lac_agent_log('data_pack_manifest_failed',['data_ref'=>$reference,'error'=>$manifestError]);}
 }
 $encoded=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
 $bytes=$encoded===false?0:strlen($encoded);
 $receipt=[
  'data_ref'=>$reference,
  'result_id'=>$resultId,
  'execution_id'=>$executionId,
  'run_id'=>$runId,
  'tool'=>$tool,
  'ok'=>(bool)($result['ok']??false),
  'bytes'=>$bytes,
  'sha256'=>$encoded===false?null:hash('sha256',$encoded),
  'created_at'=>date(DATE_ATOM),
  'manifest_status'=>$manifestStatus,
  'manifest_error'=>$manifestError
 ];
 if(function_exists('lac_agent_log'))lac_agent_log('data_pack_committed',$receipt);
 return $receipt;
}

/** Retrieve original result by a Data Pack reference; never accepts arbitrary filesystem paths. */
function lac_data_pack_get(string $reference): ?array {
 $result=lac_result_store_get($reference);
 if($result!==null && function_exists('lac_agent_log')) {
  lac_agent_log('data_pack_read',['data_ref'=>$reference,'ok'=>true]);
 }
 return $result;
}

/**
 * Run-scoped read API for model-requested Data Pack access.
 * The reference is validated as an opaque agent-results locator and ownership is
 * checked against the active run before any result bytes are returned.
 */
function lac_data_pack_get_for_run(string $reference,string $runId): ?array {
 $base=basename($reference);
 if(!preg_match('/^[a-f0-9]{8,64}\.json$/i',$base))return null;
 if(!preg_match('~^agent-results/[a-f0-9]{8,64}\.json$~i',$reference))return null;
 $path=lac_result_store_dir().'/'.$base;
 if(!is_file($path)||is_link($path))return null;
 $record=json_decode((string)@file_get_contents($path),true);
 if(!is_array($record)||!is_array($record['result']??null))return null;
 if($runId===''||!hash_equals((string)($record['run_id']??''),$runId)) {
  if(function_exists('lac_agent_log'))lac_agent_log('data_pack_read_denied',['data_ref'=>$reference,'run_id'=>$runId,'reason'=>'run_scope_mismatch']);
  throw new RuntimeException('DATA_PACK_ACCESS_DENIED: reference does not belong to the current Agent turn.');
 }
 if(is_array($record['integrity']??null)){
  $encoded=json_encode($record['result'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
  $actual=$encoded===false?null:hash('sha256',$encoded);
  if(!is_string($actual)||!is_string($record['integrity']['sha256']??null)||!hash_equals($record['integrity']['sha256'],$actual)||strlen((string)$encoded)!==(int)($record['integrity']['bytes']??-1)){
   if(function_exists('lac_agent_log'))lac_agent_log('data_pack_integrity_failed',['data_ref'=>$reference,'run_id'=>$runId,'expected_sha256'=>$record['integrity']['sha256']??null,'actual_sha256'=>$actual]);
   throw new RuntimeException('DATA_PACK_INTEGRITY_FAILED: stored result does not match its committed checksum.');
  }
 }
 if(function_exists('lac_agent_log'))lac_agent_log('data_pack_read',['data_ref'=>$reference,'run_id'=>$runId,'ok'=>true]);
 return $record['result'];
}

/** Check run-scoped Data Pack availability without reading payload bytes or emitting a read event. */
function lac_data_pack_exists_for_run(string $reference,string $runId): bool {
 $base=basename($reference);
 if(!preg_match('/^[a-f0-9]{8,64}\.json$/i',$base)||!preg_match('~^agent-results/[a-f0-9]{8,64}\.json$~i',$reference))return false;
 $path=lac_result_store_dir().'/'.$base;
 if(!is_file($path))return false;
 $record=json_decode((string)@file_get_contents($path),true);
 return is_array($record)&&is_array($record['result']??null)&&$runId!==''&&hash_equals((string)($record['run_id']??''),$runId);
}

/** Select a nested field and paginate list results without exposing filesystem paths. */
function lac_data_pack_read_for_run(string $reference,string $runId,?string $path=null,int $offset=0,int $limit=50): array {
 $result=lac_data_pack_get_for_run($reference,$runId);
 if($result===null)throw new RuntimeException('DATA_PACK_NOT_FOUND');
 $selected=$result;
 if($path!==null&&$path!=='') {
  if(strlen($path)>500||preg_match('/(^\.|\.\.|[\/\\\\])/', $path))throw new InvalidArgumentException('DATA_PACK_PATH_INVALID');
  foreach(explode('.',$path) as $segment) {
   if($segment===''||!is_array($selected)||!array_key_exists($segment,$selected))throw new RuntimeException('DATA_PACK_PATH_NOT_FOUND: '.$path);
   $selected=$selected[$segment];
  }
 }
 $offset=max(0,min(100000,$offset));$limit=max(1,min(100,$limit));
 $total=null;
 if(is_array($selected)&&array_is_list($selected)) {
  $total=count($selected);
  $selected=array_slice($selected,$offset,$limit);
 }
 $encoded=json_encode($selected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
 if($encoded===false)throw new RuntimeException('DATA_PACK_ENCODING_FAILED');
 if(strlen($encoded)>12000)throw new RuntimeException('DATA_PACK_RESULT_TOO_LARGE: specify a specific path or read smaller pages (maximum 12 KB per page).');
 return [
  'data_ref'=>$reference,'path'=>$path,'offset'=>$offset,'limit'=>$limit,
  'total_items'=>$total,'has_more'=>$total!==null?($offset+$limit<$total):false,
  'data'=>$selected
 ];
}

/**
 * Resolve {"$data_ref":"agent-results/<id>.json"} recursively before schema validation.
 * Also supports {"$data_ref":"...","$path":"result.data"} for selecting a nested value.
 */
function lac_data_pack_resolve_value(mixed $value,string $runId,array &$reads=[]): mixed {
 if($value instanceof stdClass){$copy=clone $value;foreach($copy as $key=>$child)$copy->{$key}=lac_data_pack_resolve_value($child,$runId,$reads);return $copy;}
 if(is_array($value) && array_key_exists('$data_ref',$value)) {
  if(count($value)>5 || !is_string($value['$data_ref']) || trim($value['$data_ref'])==='') {
   throw new InvalidArgumentException('DATA_PACK_REFERENCE_INVALID');
  }
  $reference=$value['$data_ref'];
  if(isset($value['$query'])) {
   if(!is_array($value['$query'])||!is_string($value['$field']??null)||$value['$field']==='')throw new InvalidArgumentException('DATA_PACK_QUERY_REFERENCE_INVALID');
   require_once __DIR__.'/data_access.php';
   $selected=lac_data_pack_query($reference,$runId,$value['$field'],$value['$query']);
   $reads[]=['data_ref'=>$reference,'field'=>$value['$field'],'operation'=>'query'];
   return $selected;
  }
  if(!isset($value['$path']))throw new RuntimeException('DATA_PACK_FULL_HYDRATION_DISABLED: use $path with pagination or $query for selective access.');
  $path=(string)$value['$path'];
  $segments=explode('.',$path);
  if(strlen($path)>500||!$segments)throw new InvalidArgumentException('DATA_PACK_PATH_INVALID');
  foreach($segments as $segment)if($segment===''||$segment==='.'||$segment==='..'||str_contains($segment,'/')||str_contains($segment,'\\'))throw new InvalidArgumentException('DATA_PACK_PATH_INVALID');
  $manifest=function_exists('lac_data_pack_manifest_get')?lac_data_pack_manifest_get($reference,$runId):null;
  if(!is_array($manifest))throw new RuntimeException('DATA_PACK_MANIFEST_NOT_FOUND: selective access requires a committed manifest.');
  $fieldMeta=$manifest['fields'][$path]??null;
  if(!is_array($fieldMeta))throw new RuntimeException('DATA_PACK_FIELD_NOT_FOUND');
  if(!empty($fieldMeta['segmented'])){
   require_once __DIR__.'/data_access.php';
   if(array_key_exists('$row_index',$value)||array_key_exists('$column',$value)){
    if(!is_int($value['$row_index']??null)||$value['$row_index']<0||$value['$row_index']>2000000||!is_string($value['$column']??null)||$value['$column']==='')throw new InvalidArgumentException('DATA_PACK_TEXT_CELL_REFERENCE_INVALID');
    $maxChars=(int)($value['$max_chars']??100000);
    if($maxChars<1||$maxChars>100000)throw new InvalidArgumentException('DATA_PACK_TEXT_LIMIT_INVALID');
    $page=lac_data_pack_read_segmented($reference,$runId,$path,$value['$row_index'],1);
    $row=$page['rows'][0]??null;
    if(!is_array($row)||!array_key_exists($value['$column'],$row))throw new RuntimeException('DATA_PACK_TEXT_CELL_NOT_FOUND');
    $selected=$row[$value['$column']];
    if(!is_string($selected))throw new RuntimeException('DATA_PACK_SCALAR_TEXT_REQUIRED');
    if(mb_strlen($selected)>$maxChars)throw new RuntimeException('DATA_PACK_TEXT_SIZE_LIMIT_EXCEEDED');
    $reads[]=['data_ref'=>$reference,'path'=>$path,'operation'=>'read_text_cell','row_index'=>$value['$row_index'],'column'=>$value['$column'],'chars'=>mb_strlen($selected)];
    return $selected;
   }
   $selected=lac_data_pack_read_segmented($reference,$runId,$path,(int)($value['$offset']??0),(int)($value['$limit']??100));
   $reads[]=['data_ref'=>$reference,'path'=>$path,'operation'=>'read_page'];
   return $selected;
  }
  // Scalar text hydration is intentionally narrow: only manifest-declared,
  // non-segmented string fields may be injected, and only within a byte/char cap.
  $maxChars=(int)($value['$max_chars']??100000);
  if($maxChars<1||$maxChars>100000)throw new InvalidArgumentException('DATA_PACK_TEXT_LIMIT_INVALID');
  if(($fieldMeta['type']??null)!=='string')throw new RuntimeException('DATA_PACK_FIELD_NOT_SEGMENTED: use inspect_data_pack and query_data_pack for dataset access.');
  $result=lac_data_pack_get_for_run($reference,$runId);
  if($result===null)throw new RuntimeException('DATA_PACK_NOT_FOUND');
  $selected=$result;
  foreach($segments as $segment){
   if($segment===''||!is_array($selected)||!array_key_exists($segment,$selected))throw new RuntimeException('DATA_PACK_FIELD_NOT_FOUND');
   $selected=$selected[$segment];
  }
  if(!is_string($selected))throw new RuntimeException('DATA_PACK_SCALAR_TEXT_REQUIRED');
  if(mb_strlen($selected)>$maxChars)throw new RuntimeException('DATA_PACK_TEXT_SIZE_LIMIT_EXCEEDED');
  $reads[]=['data_ref'=>$reference,'path'=>$path,'operation'=>'read_text','chars'=>mb_strlen($selected)];
  return $selected;
 }
 if(is_array($value)) {
  $out=[];foreach($value as $key=>$item)$out[$key]=lac_data_pack_resolve_value($item,$runId,$reads);return $out;
 }
 return $value;
}
function lac_data_pack_resolve_arguments(array $arguments,string $runId,array &$reads=[]): array {
 return lac_data_pack_resolve_value($arguments,$runId,$reads);
}

/**
 * Build a bounded, model-facing projection of a tool result.
 * This is infrastructure hydration, not a model-callable tool.
 */
function lac_data_pack_compact_preview(mixed $value,int $depth=0): mixed {
 if($depth>=6)return '[depth omitted]';
 if(is_string($value))return function_exists('mb_substr')?mb_substr($value,0,1600):substr($value,0,1600);
 if(!is_array($value))return $value;
 $out=[];$count=0;
 foreach($value as $key=>$item) {
  $keyText=(string)$key;
  if(preg_match('/base64|binary|raw_bytes|image_data|file_blob/i',$keyText))continue;
  if(++$count>30){$out['_truncated_items']=true;break;}
  $out[$key]=lac_data_pack_compact_preview($item,$depth+1);
 }
 return $out;
}
/** Build a compact receipt plus a Runtime-hydrated canonical Data Pack source for model context. */
function lac_data_pack_model_receipt(array $result,?array $receipt=null,?string $batchId=null,?PDO $pdo=null,array $context=[]): array {
 $receipt=$receipt??[];
 // Tool executor wraps domain output in result.result. Extract only safe, bounded
 // artifact metadata so the model can reference generated images without receiving raw files.
 $domainResult=is_array($result['result']??null)?$result['result']:$result;
 $artifacts=[];
 // Keep source URLs in the Data Pack and expose a compact, numbered citation index
 // to the model so it can cite only pages actually fetched by web tools.
 $sourceRows = $domainResult['sources'] ?? ($domainResult['pages'] ?? []);
 $citations = [];
 if (is_array($sourceRows)) {
  foreach (array_slice($sourceRows, 0, 20) as $i => $source) {
   if (!is_array($source)) continue;
   $url = trim((string)($source['url'] ?? ''));
   if (!preg_match('~^https?://~i', $url)) continue;
   $citations[] = ['number'=>count($citations)+1,'title'=>mb_substr((string)($source['title'] ?? $url),0,180),'url'=>$url];
  }
 }
 $file=(string)($domainResult['file']??'');
 $imageUrl=(string)($domainResult['image_url']??'');
 if($file!=='' && preg_match('/^chart_[a-f0-9]{32}\.(?:jpg|png)$/D',$file)
    && $imageUrl!=='' && preg_match('~^api\.php\?action=python_output&file=chart_[a-f0-9]{32}\.(?:jpg|png)$~D',$imageUrl)) {
  $artifacts[]=[
   'id'=>'art_'.substr(hash('sha256',$file.'|'.($receipt['execution_id']??bin2hex(random_bytes(4)))),0,24),
   'batch_id'=>$batchId,
   'type'=>'image',
   'mime_type'=>str_ends_with($file,'.jpg')?'image/jpeg':'image/png',
   'filename'=>$file,
   'url'=>$imageUrl,
   'status'=>'success'
  ];
 }
 if($batchId!==null && $artifacts){
  lac_artifact_batch_register($batchId,$artifacts,[
   'run_id'=>$result['run_id']??null,
   'conversation_id'=>$context['conversation_id']??($result['conversation_id']??null),
   'message_id'=>$context['message_id']??null,
   'model_id'=>$result['model_id']??null
  ]);
  if($pdo instanceof PDO && !empty($context['message_id'])) {
   foreach($artifacts as $artifact) lac_artifact_register_message($pdo,$artifact,$context);
  }
 }
 $modelArtifacts=array_map(static function(array $item):array{unset($item['url']);return $item;},$artifacts);
 $preview=null;
 $toolName=(string)($result['tool']??'');
 if($toolName==='manage_websites') {
  $action=(string)($domainResult['action']??'');
  if($action==='list'&&is_array($domainResult['websites']??null)) {
   $sites=[];
   foreach(array_slice($domainResult['websites'],0,40) as $site) {
    if(!is_array($site))continue;
    $sites[]=[
     'name'=>mb_substr((string)($site['name']??''),0,120),
     'url'=>mb_substr((string)($site['url']??''),0,500),
     'search_url'=>mb_substr((string)($site['search_url']??''),0,500)
    ];
   }
   $preview=['action'=>'list','count'=>(int)($domainResult['count']??count($sites)),'websites'=>$sites];
  } elseif($action==='read'&&is_array($domainResult['content']??null)) {
   $content=$domainResult['content'];$pages=[];
   foreach(array_slice((array)($content['pages']??[]),0,8) as $page) {
    if(!is_array($page))continue;
    $pages[]=[
     'title'=>mb_substr((string)($page['title']??''),0,180),
     'url'=>mb_substr((string)($page['url']??''),0,1000),
     'text'=>mb_substr((string)($page['text']??$page['content']??''),0,3500)
    ];
   }
   $preview=['action'=>'read','site'=>mb_substr((string)($domainResult['site']??''),0,120),'pages'=>$pages];
  }
 }
 if($preview===null) {
  $modelPreviewSource=$domainResult;
  unset($modelPreviewSource['image_url']);
  $preview=lac_data_pack_compact_preview($modelPreviewSource);
 }
 if($preview!==null) {
  $previewJson=json_encode($preview,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
  while($previewJson!==false&&strlen($previewJson)>16000) {
   if(isset($preview['websites'])&&count($preview['websites'])>1)array_pop($preview['websites']);
   elseif(isset($preview['pages'])&&count($preview['pages'])>1)array_pop($preview['pages']);
   else {$preview=null;break;}
   $previewJson=json_encode($preview,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
  }
 }
 $dataRef=(string)($result['result_reference']??($receipt['data_ref']??''));
 $runtimeDataPackRead=$dataRef!==''?lac_data_pack_read_for_model($dataRef,(string)($context['run_id']??''),(string)($receipt['tool_call_id']??$result['tool_call_id']??''),$toolName):null;
 return [
  'ok'=>(bool)($result['ok']??false),
  'tool'=>$result['tool']??null,
  'tool_call_id'=>$result['tool_call_id']??null,
  'execution_id'=>$result['execution_id']??($receipt['execution_id']??null),
  'result_id'=>$result['result_id']??($receipt['result_id']??null),
  'data_ref'=>$result['result_reference']??($receipt['data_ref']??null),
  'data_pack_read'=>$runtimeDataPackRead?array_diff_key($runtimeDataPackRead,['data'=>true]):null,
  'batch_id'=>$batchId,
  'artifacts'=>$modelArtifacts,
  'citations'=>$citations,
  'preview'=>$preview,
  'image_url'=>null,
  'cached'=>(bool)($result['cached']??false),
  'duration_ms'=>$result['duration_ms']??null,
  'error_code'=>$result['error_code']??null,
  'error'=>empty($result['ok'])?($result['error']??'Tool failed'):null,
  'result_shape'=>[
   'top_level_keys'=>array_slice(array_keys($result),0,30),
   'has_original_result'=>!empty($result['result_reference'])||!empty($receipt['data_ref'])
  ]
 ];
}


/**
 * Runtime-owned Data Pack read for model context.
 * This is an architectural read, not a model-callable business tool. The original
 * committed payload is fetched under the active run scope and attached to the
 * model receipt only when it fits the configured prompt safety ceiling.
 */
function lac_data_pack_read_for_model(string $reference,string $runId,string $toolCallId='',string $tool=''): array {
 if($reference===''||$runId==='')return ['status'=>'unavailable','error_code'=>'DATA_PACK_REFERENCE_REQUIRED','data'=>null];
 // Never hydrate the canonical payload into model context.
 $manifest=function_exists('lac_data_pack_manifest_get')?lac_data_pack_manifest_get($reference,$runId):null;
 if(!$manifest)return ['status'=>'reference_only','data_ref'=>$reference,'manifest_available'=>false,'data'=>null];
 $fields=[];foreach(($manifest['fields']??[]) as $name=>$meta)$fields[$name]=['type'=>$meta['type']??'unknown','count'=>$meta['count']??null,'segmented'=>(bool)($meta['segmented']??false)];
 return ['status'=>'reference_only','data_ref'=>$reference,'manifest_available'=>true,'fields'=>$fields,'data'=>null];
}

/**
 * Data Pack cleanup is intentionally locked off by default while result retention
 * and reproducibility are being validated. Re-enable only by setting
 * LAC_DATA_PACK_CLEANUP_ENABLED=1 in the PHP process environment.
 */
function lac_data_pack_cleanup_enabled(): bool {
 return getenv('LAC_DATA_PACK_CLEANUP_ENABLED') === '1';
}

/** Delete every Data Pack owned by a terminal Job. Safe to call repeatedly. */
function lac_data_pack_cleanup_run(string $runId): array {
 if(!lac_data_pack_cleanup_enabled()) {
  if(function_exists('lac_agent_log'))lac_agent_log('data_pack_cleanup_skipped',['run_id'=>$runId,'reason'=>'cleanup_disabled']);
  return ['run_id'=>$runId,'enabled'=>false,'deleted'=>0,'bytes'=>0,'errors'=>[]];
 }
 $dir=lac_result_store_dir();$deleted=0;$bytes=0;$errors=[];
 if(!is_dir($dir))return ['run_id'=>$runId,'enabled'=>true,'deleted'=>0,'bytes'=>0,'errors'=>[]];
 require_once __DIR__.'/manifest_manager.php';
 foreach(glob($dir.'/*.json')?:[] as $path){
  if(is_link($path))continue;
  $raw=@file_get_contents($path);if($raw===false)continue;
  $meta=json_decode($raw,true);
  if(!is_array($meta)||($meta['run_id']??null)!==$runId)continue;
  $reference='agent-results/'.basename($path);
  try {
   $manifest=lac_data_pack_manifest_get($reference,$runId);
   if(is_array($manifest)){
    $manifestDir=lac_data_pack_manifest_dir();$packId=(string)($manifest['data_pack_id']??'');
    foreach(($manifest['fields']??[]) as $fieldMeta)foreach(($fieldMeta['segments']??[]) as $segment){
     $name=(string)($segment['file']??'');
     if($packId===''||!preg_match('/^'.preg_quote($packId,'/').'\.[a-zA-Z0-9_-]+\.\d{6}\.jsonl$/D',$name))continue;
     $segmentPath=$manifestDir.'/'.$name;
     if(is_file($segmentPath)&&!is_link($segmentPath)){
      $segmentBytes=(int)(filesize($segmentPath)?:0);
      if(@unlink($segmentPath))$bytes+=$segmentBytes;else$errors[]=$name;
     }
    }
    $manifestPath=lac_data_pack_manifest_path($reference);
    if($manifestPath&&is_file($manifestPath)&&!is_link($manifestPath)){
     $manifestBytes=(int)(filesize($manifestPath)?:0);
     if(@unlink($manifestPath))$bytes+=$manifestBytes;else$errors[]=basename($manifestPath);
    }
   }
  } catch(Throwable $e) {$errors[]='manifest:'.basename($path);}
  // A manifest can be missing or corrupt after an interrupted build. The canonical
  // result record establishes run ownership; safely remove any remaining segments
  // matching this exact Data Pack ID to avoid orphaned disk usage.
  $packId=basename($path,'.json');$manifestDir=lac_data_pack_manifest_dir();
  if(preg_match('/^[a-f0-9]{8,64}$/i',$packId))foreach(glob($manifestDir.'/'.$packId.'.*.jsonl')?:[] as $orphan){
   $name=basename($orphan);
   if(!preg_match('/^'.preg_quote($packId,'/').'\\.[a-zA-Z0-9_-]+\\.\\d{6}\\.jsonl$/D',$name)||is_link($orphan)||!is_file($orphan))continue;
   $orphanBytes=(int)(filesize($orphan)?:0);if(@unlink($orphan))$bytes+=$orphanBytes;else $errors[]=$name;
  }
  $size=(int)(filesize($path)?:strlen($raw));
  if(@unlink($path)){$deleted++;$bytes+=$size;}else{$errors[]=basename($path);}
 }
 if(function_exists('lac_agent_log'))lac_agent_log('data_pack_cleanup',['run_id'=>$runId,'deleted'=>$deleted,'bytes'=>$bytes,'errors'=>$errors]);
 return ['run_id'=>$runId,'enabled'=>true,'deleted'=>$deleted,'bytes'=>$bytes,'errors'=>$errors];
}
