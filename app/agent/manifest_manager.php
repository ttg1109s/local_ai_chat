<?php
/** Data Pack manifest and physical segment index. Metadata is sidecar; legacy result JSON remains canonical. */
function lac_data_pack_manifest_dir(): string { return dirname(__DIR__,2).'/storage/agent-manifests'; }
function lac_manifest_write_all($fh,string $bytes): void { $length=strlen($bytes);$offset=0;while($offset<$length){$written=fwrite($fh,substr($bytes,$offset));if($written===false||$written===0)throw new RuntimeException('MANIFEST_SEGMENT_WRITE_FAILED');$offset+=$written;} }
function lac_data_pack_manifest_path(string $ref): ?string {
 $id=basename($ref,'.json');
 if(!preg_match('/^[a-f0-9]{8,64}$/i',$id)) return null;
 return lac_data_pack_manifest_dir().'/'.$id.'.json';
}
function lac_manifest_collect_fields(string $prefix,mixed $value,array &$fields,string $dataPackId,string $dir,string $manifestPath,int $segmentRows,int $depth=0): void {
 if($depth>8)return;
 $meta=['type'=>get_debug_type($value),'count'=>is_array($value)?count($value):null];
 if(is_array($value)&&array_is_list($value)&&count($value)>0){
  $segments=[];$slug=preg_replace('/[^a-zA-Z0-9_-]/','_', $prefix);$slug=substr((string)$slug,0,48).'_'.substr(hash('sha256',$prefix),0,8);
  foreach(array_chunk($value,$segmentRows) as $i=>$chunk){
   $name=$dataPackId.'.'.$slug.'.'.sprintf('%06d',$i).'.jsonl';$segPath=$dir.'/'.$name;$tmp=$segPath.'.'.bin2hex(random_bytes(4)).'.tmp';$fh=fopen($tmp,'xb');if(!$fh)throw new RuntimeException('SEGMENT_CREATE_FAILED');$hash=hash_init('sha256');$bytes=0;
   try{foreach($chunk as $row){$line=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);if($line===false)throw new RuntimeException('SEGMENT_ENCODE_FAILED');$line.="\n";lac_manifest_write_all($fh,$line);hash_update($hash,$line);$bytes+=strlen($line);}if(!fflush($fh))throw new RuntimeException('SEGMENT_FLUSH_FAILED');if(function_exists('fsync'))@fsync($fh);}catch(Throwable $e){fclose($fh);@unlink($tmp);throw $e;}fclose($fh);if(!rename($tmp,$segPath)){@unlink($tmp);throw new RuntimeException('SEGMENT_COMMIT_FAILED');}$segments[]=['file'=>$name,'rows'=>count($chunk),'bytes'=>$bytes,'sha256'=>hash_final($hash),'format'=>'jsonl','committed'=>true];
  }
  $meta['segments']=$segments;$meta['segmented']=true;
 }
 $fields[$prefix]=$meta;
 if(is_array($value)&&!array_is_list($value))foreach($value as $key=>$child)lac_manifest_collect_fields($prefix.'.'.(string)$key,$child,$fields,$dataPackId,$dir,$manifestPath,$segmentRows,$depth+1);
}
function lac_data_pack_manifest_build(string $ref,array $result,array $receipt=[],int $segmentRows=1000): array {
 $path=lac_data_pack_manifest_path($ref);if(!$path)throw new InvalidArgumentException('DATA_PACK_REF_INVALID');$dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('MANIFEST_DIR_CREATE_FAILED');$fields=[];$segmentRows=max(100,min(10000,$segmentRows));$id=basename($path,'.json');
 foreach($result as $key=>$value)lac_manifest_collect_fields((string)$key,$value,$fields,$id,$dir,$path,$segmentRows);
 $manifest=['schema_version'=>2,'data_pack_id'=>$id,'data_ref'=>$ref,'run_id'=>$receipt['run_id']??null,'execution_id'=>$receipt['execution_id']??null,'result_id'=>$receipt['result_id']??null,'tool'=>$receipt['tool']??null,'created_at'=>$receipt['created_at']??date(DATE_ATOM),'format'=>'json+jsonl-segments','fields'=>$fields,'integrity'=>$receipt['sha256']??null,'lifecycle'=>'committed','policy'=>['access'=>'run-scoped']];
 $json=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);$tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';if($json===false||file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('MANIFEST_COMMIT_FAILED');}return $manifest;
}
function lac_data_pack_manifest_get(string $ref,?string $runId=null): ?array {
 $path=lac_data_pack_manifest_path($ref); if(!$path||!is_file($path))return null;
 if(is_link($path))return null;
 $m=json_decode((string)file_get_contents($path),true); if(!is_array($m))return null;
 if($runId!==null&&(!is_string($m['run_id']??null)||!hash_equals((string)$m['run_id'],$runId))) throw new RuntimeException('DATA_PACK_ACCESS_DENIED');
 return $m;
}
