<?php
/** Durable artifact batch registry; independent from conversation messages. */
function lac_artifact_mutation_lock() {
 $dir=dirname(__DIR__,2).'/storage/artifacts';
 if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Could not create Artifact Store lock directory.');
 $fp=@fopen($dir.'/.artifact-manager.lock','c');
 if(!$fp||!flock($fp,LOCK_EX)){if($fp)fclose($fp);throw new RuntimeException('Could not lock Artifact Store.');}
 return $fp;
}
function lac_artifact_mutation_unlock($fp): void { if(is_resource($fp)){flock($fp,LOCK_UN);fclose($fp);} }
function lac_artifact_manifest_dir(): string {
 return dirname(__DIR__,2).'/storage/artifacts/batches';
}
function lac_artifact_batch_register(string $batchId,array $artifacts,array $meta=[]): array {
 if(!preg_match('/^bat_[a-f0-9]{20,40}$/',$batchId))throw new InvalidArgumentException('BATCH_ID_INVALID');
 $lock=lac_artifact_mutation_lock();try {
 $dir=lac_artifact_manifest_dir();
 if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Could not create the Artifact Batch Store.');
 $path=$dir.'/'.$batchId.'.json';
 $existing=is_file($path)&&!is_link($path)?json_decode((string)file_get_contents($path),true):null;
 $manifest=is_array($existing)?$existing:['batch_id'=>$batchId,'created_at'=>date(DATE_ATOM),'status'=>'success','items'=>[]];
 foreach($artifacts as $item){
  if(!is_array($item)||($item['type']??'')!=='image')continue;
  $id=(string)($item['id']??'');
  if($id===''||!preg_match('/^art_[a-f0-9]{24}$/',$id))continue;
  $manifest['items'][$id]=$item;
 }
 $manifest['item_count']=count($manifest['items']);
 $manifest['status']=$manifest['item_count']>0?'success':'empty';
 $manifest['updated_at']=date(DATE_ATOM);
 $manifest['meta']=array_intersect_key($meta,array_flip(['run_id','conversation_id','message_id','model_id']));
 $json=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT);
 $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';if($json===false||file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not save the Artifact Batch Manifest.');}
 return $manifest;
 } finally {lac_artifact_mutation_unlock($lock);}
}
function lac_artifact_batch_get(string $batchId): ?array {
 if(!preg_match('/^bat_[a-f0-9]{20,40}$/',$batchId))return null;
 $path=lac_artifact_manifest_dir().'/'.$batchId.'.json';
 if(!is_file($path)||is_link($path))return null;
 $v=json_decode((string)file_get_contents($path),true);
 if(!is_array($v))return null;
 $v['items']=array_values($v['items']??[]);
 return $v;
}
function lac_artifact_file_path(string $filename): ?string {
 if(!preg_match('/^chart_[a-f0-9]{32}\.(?:jpg|png)$/D',$filename))return null;
 return dirname(__DIR__,2).'/storage/python_outputs/'.$filename;
}

/** Resolve an artifact from the independent batch registry, never from a message row. */
function lac_artifact_find_by_id(string $artifactId): ?array {
 if(!preg_match('/^art_[a-f0-9]{24}$/',$artifactId))return null;
 $dir=lac_artifact_manifest_dir();
 foreach(glob($dir.'/*.json')?:[] as $path){
  if(is_link($path))continue;
  $manifest=json_decode((string)@file_get_contents($path),true);
  if(!is_array($manifest)||!isset($manifest['items'][$artifactId])||!is_array($manifest['items'][$artifactId]))continue;
  $item=$manifest['items'][$artifactId];$filename=(string)($item['filename']??'');
  if(!lac_artifact_file_path($filename))continue;
  return ['artifact'=>$item,'batch_id'=>$manifest['batch_id']??null,'meta'=>$manifest['meta']??[]];
 }
 return null;
}
function lac_artifact_filename_referenced_in_batches(string $filename): bool {
 $dir=lac_artifact_manifest_dir();
 foreach(glob($dir.'/*.json')?:[] as $path){
  if(is_link($path))continue;$manifest=json_decode((string)@file_get_contents($path),true);
  if(!is_array($manifest))continue;
  foreach(($manifest['items']??[]) as $item)if(is_array($item)&&($item['filename']??'')===$filename)return true;
 }
 return false;
}

function lac_artifact_delete_files_if_unreferenced_unlocked(array $filenames): array {
 $deleted=[];$kept=[];$failed=[];
 foreach(array_unique($filenames) as $filename){
  $path=lac_artifact_file_path((string)$filename);if(!$path)continue;
  if(lac_artifact_filename_referenced_in_batches((string)$filename)){$kept[]=$filename;continue;}
  if(!file_exists($path)||@unlink($path))$deleted[]=$filename;else$failed[]=$filename;
 }
 return ['deleted'=>$deleted,'kept'=>$kept,'failed'=>$failed];
}
function lac_artifact_delete_files_if_unreferenced(array $filenames): array {
 $lock=lac_artifact_mutation_lock();try {
 $deleted=[];$kept=[];$failed=[];
 foreach(array_unique($filenames) as $filename){
  $path=lac_artifact_file_path((string)$filename);if(!$path)continue;
  if(lac_artifact_filename_referenced_in_batches((string)$filename)){$kept[]=$filename;continue;}
  if(!file_exists($path)||@unlink($path))$deleted[]=$filename;else$failed[]=$filename;
 }
 return ['deleted'=>$deleted,'kept'=>$kept,'failed'=>$failed];
 } finally {lac_artifact_mutation_unlock($lock);}
}
function lac_artifact_delete_by_id(string $artifactId,int $conversationId): array {
 $lock=lac_artifact_mutation_lock();try {
 if(!preg_match('/^art_[a-f0-9]{24}$/',$artifactId))throw new InvalidArgumentException('ARTIFACT_ID_INVALID');
 $dir=lac_artifact_manifest_dir();$filename=null;$found=false;
 foreach(glob($dir.'/*.json')?:[] as $path){
  if(is_link($path))continue;$manifest=json_decode((string)@file_get_contents($path),true);
  if(!is_array($manifest)||!isset($manifest['items'][$artifactId]))continue;
  if((int)($manifest['meta']['conversation_id']??0)!==$conversationId)throw new RuntimeException('ARTIFACT_ACCESS_DENIED');
  $filename=(string)($manifest['items'][$artifactId]['filename']??'');unset($manifest['items'][$artifactId]);$found=true;
  $manifest['item_count']=count($manifest['items']);
  if(!$manifest['item_count'])@unlink($path);
  else { $json=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);$tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';if($json===false||file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('ARTIFACT_MANIFEST_UPDATE_FAILED');} }
  break;
 }
 if(!$found)return ['deleted'=>false,'file_deleted'=>false];
 $cleanup=lac_artifact_delete_files_if_unreferenced_unlocked([$filename]);
 return ['deleted'=>true,'file_deleted'=>in_array($filename,$cleanup['deleted'],true),'file_cleanup_failed'=>in_array($filename,$cleanup['failed'],true)];
 } finally {lac_artifact_mutation_unlock($lock);}
}
function lac_artifact_cleanup_message(int $messageId): array {
 $lock=lac_artifact_mutation_lock();try {
 $files=[];$dir=lac_artifact_manifest_dir();
 foreach(glob($dir.'/*.json')?:[] as $path){
  if(is_link($path))continue;$manifest=json_decode((string)@file_get_contents($path),true);
  if(!is_array($manifest)||(int)($manifest['meta']['message_id']??0)!==$messageId)continue;
  foreach(($manifest['items']??[]) as $item)if(is_array($item)&&!empty($item['filename']))$files[]=(string)$item['filename'];
  @unlink($path);
 }
 return lac_artifact_delete_files_if_unreferenced_unlocked($files);
 } finally {lac_artifact_mutation_unlock($lock);}
}
function lac_artifact_cleanup_conversation(int $conversationId): array {
 $lock=lac_artifact_mutation_lock();try {
 $files=[];$dir=lac_artifact_manifest_dir();
 foreach(glob($dir.'/*.json')?:[] as $path){
  if(is_link($path))continue;$manifest=json_decode((string)@file_get_contents($path),true);
  if(!is_array($manifest)||(int)($manifest['meta']['conversation_id']??0)!==$conversationId)continue;
  foreach(($manifest['items']??[]) as $item)if(is_array($item)&&!empty($item['filename']))$files[]=(string)$item['filename'];
  @unlink($path);
 }
 return lac_artifact_delete_files_if_unreferenced_unlocked($files);
 } finally {lac_artifact_mutation_unlock($lock);}
}
