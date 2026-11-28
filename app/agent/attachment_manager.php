<?php
/** Durable attachment descriptors. Binary bytes live in Artifact/Data Pack storage, never here. */
function lac_attachment_store_dir(): string {return dirname(__DIR__,2).'/storage/attachments';}
/** Serialize descriptor registration and owner cleanup to avoid cleanup/register races. */
function lac_attachment_mutation_lock() {
 $dir=lac_attachment_store_dir();if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('ATTACHMENT_STORE_CREATE_FAILED');
 $fp=@fopen($dir.'/.attachment-manager.lock','c');if(!$fp||!flock($fp,LOCK_EX)){if($fp)fclose($fp);throw new RuntimeException('ATTACHMENT_STORE_LOCK_FAILED');}return $fp;
}
function lac_attachment_mutation_unlock($fp): void {if(is_resource($fp)){flock($fp,LOCK_UN);fclose($fp);}}

function lac_attachment_presentations(): array {return ['data_table','chart','image','document','json_viewer','code','sources','evidence','math','model_report','media','file'];}
function lac_attachment_detect_presentation(string $mime,string $format,string $type=''): string {
 $mime=strtolower($mime);$format=strtolower($format);
 if($type==='tabular'||in_array($format,['csv','jsonl','parquet','arrow','xlsx'],true)||str_contains($mime,'csv'))return 'data_table';
 if($type==='chart')return 'chart';if($type==='image'||str_starts_with($mime,'image/'))return 'image';
 if($type==='evidence')return 'evidence';if($type==='sources')return 'sources';if($type==='math')return 'math';if($type==='model_report')return 'model_report';
 if(in_array($format,['pdf','docx','pptx','txt','md','html'],true)||str_contains($mime,'pdf')||str_contains($mime,'word')||str_contains($mime,'spreadsheet')||str_contains($mime,'presentation'))return 'document';
 if(str_contains($mime,'audio/')||str_contains($mime,'video/'))return 'media';if(str_contains($mime,'json'))return 'json_viewer';return 'file';
}
function lac_attachment_register(array $item,array $policy=[]): array {
 $ref=(string)($item['source_ref']??'');$type=(string)($item['source_type']??'');
 if(!in_array($type,['data_pack','artifact','upload'],true))throw new InvalidArgumentException('ATTACHMENT_SOURCE_TYPE_INVALID');
 if($type==='data_pack'&&!preg_match('~^agent-results/[a-f0-9]{8,64}\.json$~i',$ref))throw new InvalidArgumentException('ATTACHMENT_REFERENCE_INVALID');
 if($type!=='data_pack'&&($ref===''||strlen($ref)>512||str_contains($ref,'..')))throw new InvalidArgumentException('ATTACHMENT_REFERENCE_INVALID');
 $id=(string)($item['attachment_id']??'att_'.bin2hex(random_bytes(12)));if(!preg_match('/^att_[a-f0-9]{24}$/',$id))throw new InvalidArgumentException('ATTACHMENT_ID_INVALID');
 $mime=substr(strtolower((string)($item['mime_type']??'application/octet-stream')),0,120);$format=(string)($item['format']??'');$typeHint=(string)($item['result_type']??'');$presentation=(string)($item['presentation']??lac_attachment_detect_presentation($mime,$format,$typeHint));
 if(!in_array($presentation,lac_attachment_presentations(),true))$presentation='file';
 $record=['schema_version'=>2,'attachment_id'=>$id,'conversation_id'=>(string)($item['conversation_id']??''),'message_id'=>(string)($item['message_id']??''),'run_id'=>isset($item['run_id'])?(string)$item['run_id']:null,'execution_id'=>isset($item['execution_id'])?(string)$item['execution_id']:null,'tool'=>(string)($item['tool']??''),'source_type'=>$type,'source_ref'=>$ref,'artifact_ref'=>isset($item['artifact_ref'])?(string)$item['artifact_ref']:null,'presentation'=>$presentation,'result_type'=>$typeHint,'format'=>$format,'title'=>substr(trim((string)($item['title']??'Attachment')),0,180),'mime_type'=>$mime,'size_bytes'=>max(0,(int)($item['size_bytes']??0)),'sha256'=>isset($item['sha256'])?(string)$item['sha256']:null,'provenance'=>is_array($item['provenance']??null)?$item['provenance']:[],'visibility'=>($item['visibility']??'user')==='user'?'user':'internal','status'=>'registered','version'=>max(1,(int)($item['version']??1)),'created_at'=>date(DATE_ATOM)];
 if($record['conversation_id']===''||($record['message_id']===''&&$record['run_id']===null&&$record['execution_id']===null))throw new InvalidArgumentException('ATTACHMENT_OWNER_REQUIRED');
 $lock=lac_attachment_mutation_lock();try{
  $dir=lac_attachment_store_dir();$path=$dir.'/'.$id.'.json';$json=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);$tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
  if($json===false||file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('ATTACHMENT_COMMIT_FAILED');}
  return $record;
 }finally{lac_attachment_mutation_unlock($lock);}
}
function lac_attachment_get(string $id,?string $conversationId=null,?string $messageId=null): ?array {
 if(!preg_match('/^att_[a-f0-9]{24}$/',$id))return null;$path=lac_attachment_store_dir().'/'.$id.'.json';if(!is_file($path)||is_link($path))return null;$v=json_decode((string)file_get_contents($path),true);if(!is_array($v))return null;
 if($conversationId!==null&&!hash_equals((string)$v['conversation_id'],$conversationId))return null;if($messageId!==null&&!hash_equals((string)$v['message_id'],$messageId))return null;return $v;
}
function lac_attachment_list(string $conversationId,?string $messageId=null): array {
 $out=[];$dir=lac_attachment_store_dir();foreach(glob($dir.'/att_*.json')?:[] as $p){if(is_link($p))continue;$v=json_decode((string)file_get_contents($p),true);if(is_array($v)&&($v['conversation_id']??'')===$conversationId&&($messageId===null||($v['message_id']??'')===$messageId)&&($v['visibility']??'')==='user')$out[]=$v;}usort($out,static fn($a,$b)=>strcmp((string)$a['created_at'],(string)$b['created_at']));return $out;
}
function lac_attachment_register_tool_result(array $result,array $context=[]): array {
 $ref=(string)($result['result_reference']??'');if($ref===''||empty($context['conversation_id']))return [];
 $domain=is_array($result['result']??null)?$result['result']:$result;
 // A binary image is presented through its Artifact attachment, not a second
 // non-renderable Data Pack card that merely points at the same tool execution.
 $file=(string)($domain['file']??'');$imageUrl=(string)($domain['image_url']??'');
 if($file!==''&&preg_match('/^chart_[a-f0-9]{32}\.(?:jpg|png)$/D',$file)&&$imageUrl!=='')return [];
 $env=function_exists('lac_tool_output_envelope')?lac_tool_output_envelope($result,$context):[];$r=$env['result']??[];$presentation=lac_attachment_detect_presentation((string)($r['mime_type']??'application/json'),(string)($r['format']??'json'),(string)($r['type']??'json'));
 return lac_attachment_register(['conversation_id'=>$context['conversation_id'],'message_id'=>$context['message_id']??null,'run_id'=>$context['run_id']??null,'execution_id'=>$result['execution_id']??null,'tool'=>$result['tool']??'','source_type'=>'data_pack','source_ref'=>$ref,'artifact_ref'=>null,'presentation'=>$presentation,'result_type'=>$r['type']??'json','format'=>$r['format']??'json','title'=>(string)($result['tool']??'Tool result'),'mime_type'=>'application/json','provenance'=>['tool'=>$result['tool']??null,'execution_id'=>$result['execution_id']??null,'tool_call_id'=>$result['tool_call_id']??null]],[]);
}

/** Remove presentation descriptors when their optional provenance owner is deleted. */
function lac_attachment_cleanup_message(string $conversationId,string $messageId): int {
 $removed=0;$dir=lac_attachment_store_dir();
 foreach(glob($dir.'/att_*.json')?:[] as $path){
  if(is_link($path))continue;$record=json_decode((string)@file_get_contents($path),true);
  if(!is_array($record)||($record['conversation_id']??'')!==$conversationId||($record['message_id']??'')!==$messageId)continue;
  if(@unlink($path))$removed++;
 }
 return $removed;
}
function lac_attachment_cleanup_conversation(string $conversationId): int {
 $removed=0;$dir=lac_attachment_store_dir();
 foreach(glob($dir.'/att_*.json')?:[] as $path){
  if(is_link($path))continue;$record=json_decode((string)@file_get_contents($path),true);
  if(!is_array($record)||($record['conversation_id']??'')!==$conversationId)continue;
  if(@unlink($path))$removed++;
 }
 return $removed;
}

function lac_attachment_cleanup_artifact(string $conversationId,string $artifactId): int {
 $removed=0;$dir=lac_attachment_store_dir();
 foreach(glob($dir.'/att_*.json')?:[] as $path){
  if(is_link($path))continue;$record=json_decode((string)@file_get_contents($path),true);
  if(!is_array($record)||($record['conversation_id']??'')!==$conversationId||($record['artifact_ref']??'')!==$artifactId)continue;
  if(@unlink($path))$removed++;
 }
 return $removed;
}
