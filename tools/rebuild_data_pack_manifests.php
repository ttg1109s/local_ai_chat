<?php
/**
 * Rebuild sidecar manifests for legacy result records.
 * Usage: php tools/rebuild_data_pack_manifests.php [--apply] [--limit=500]
 * Dry-run is the default. Never rewrites canonical result JSON.
 */
require_once dirname(__DIR__).'/app/agent/result_store.php';
require_once dirname(__DIR__).'/app/agent/manifest_manager.php';
$apply=in_array('--apply',$argv,true);$limit=500;
foreach($argv as $arg)if(str_starts_with($arg,'--limit='))$limit=max(1,min(10000,(int)substr($arg,8)));
$files=array_slice(glob(lac_result_store_dir().'/*.json')?:[],0,$limit);$ok=0;$skip=0;$fail=0;
foreach($files as $file){
 $raw=@file_get_contents($file);$record=is_string($raw)?json_decode($raw,true):null;
 if(!is_array($record)||!is_array($record['result']??null)||!is_string($record['run_id']??null)||!is_string($record['execution_id']??null)){$skip++;continue;}
 $ref='agent-results/'.basename($file);$manifestPath=lac_data_pack_manifest_path($ref);
 if($manifestPath&&is_file($manifestPath)){$skip++;continue;}
 if(!$apply){$ok++;continue;}
 try{
  $payload=json_encode($record['result'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
  $sha=$payload===false?null:hash('sha256',$payload);
  if(is_array($record['integrity']??null)&&(!is_string($sha)||!hash_equals((string)$record['integrity']['sha256'],$sha)))throw new RuntimeException('canonical record integrity mismatch');
  lac_data_pack_manifest_build($ref,$record['result'],['run_id'=>$record['run_id'],'execution_id'=>$record['execution_id'],'tool'=>$record['tool']??'','created_at'=>$record['created_at']??date(DATE_ATOM),'sha256'=>$sha]);
  $ok++;
 }catch(Throwable $e){$fail++;fwrite(STDERR,basename($file).': '.$e->getMessage().PHP_EOL);}
}
echo json_encode(['mode'=>$apply?'apply':'dry_run','scanned'=>count($files),'ready_or_created'=>$ok,'skipped'=>$skip,'failed'=>$fail],JSON_PRETTY_PRINT).PHP_EOL;
if($fail>0)exit(1);
