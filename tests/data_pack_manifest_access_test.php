<?php
require_once __DIR__.'/../app/agent/result_store.php';
require_once __DIR__.'/../app/agent/manifest_manager.php';
require_once __DIR__.'/../app/agent/data_access.php';
$run='test-run-'.bin2hex(random_bytes(4));$exec=hash('sha256',$run);$rows=[];
for($i=0;$i<1205;$i++)$rows[]=['id'=>$i,'state'=>$i%2?'ok':'failed'];
$ref=lac_result_store_put($exec,['rows'=>$rows],$run,'test');
$m=lac_data_pack_manifest_build($ref,['rows'=>$rows],['run_id'=>$run,'execution_id'=>$exec,'tool'=>'test','sha256'=>'fixture']);
if(empty($m['fields']['rows']['segmented']))throw new RuntimeException('Expected physical segmentation');
$page=lac_data_pack_read_segmented($ref,$run,'rows',990,30);
if(count($page['rows'])!==30||$page['rows'][0]['id']!==990||$page['rows'][29]['id']!==1019)throw new RuntimeException('Segment pagination mismatch');
$meta=lac_data_pack_inspect($ref,$run);if(empty($meta['manifest_available']))throw new RuntimeException('Manifest inspection failed');
$denied=false;try{lac_data_pack_manifest_get($ref,'another-run');}catch(Throwable $e){$denied=true;}if(!$denied)throw new RuntimeException('Run scope was not enforced');
// Scalar nested text fields can be resolved server-side without exposing the payload to the model.
$textResult=['docs'=>['old_text'=>"before\n",'new_text'=>"after\n"]];
$textExec=hash('sha256',$run.'-text');
$textRef=lac_result_store_put($textExec,$textResult,$run,'text-fixture');
$textManifest=lac_data_pack_manifest_build($textRef,$textResult,['run_id'=>$run,'execution_id'=>$textExec,'tool'=>'text-fixture','sha256'=>'fixture']);
if(($textManifest['fields']['docs.old_text']['type']??null)!=='string')throw new RuntimeException('Nested scalar text field was not indexed');
$reads=[];
$resolved=lac_data_pack_resolve_arguments([
 'old_text'=>['$data_ref'=>$textRef,'$path'=>'docs.old_text'],
 'new_text'=>['$data_ref'=>$textRef,'$path'=>'docs.new_text']
],$run,$reads);
if($resolved['old_text']!=="before\n"||$resolved['new_text']!=="after\n")throw new RuntimeException('Scalar text reference resolution mismatch');
if(count($reads)!==2||($reads[0]['operation']??null)!=='read_text')throw new RuntimeException('Scalar text resolution was not logged');
$deniedText=false;try{lac_data_pack_resolve_value(['$data_ref'=>$textRef,'$path'=>'docs.old_text','$max_chars'=>2],$run,$reads);}catch(Throwable $e){$deniedText=str_contains($e->getMessage(),'SIZE_LIMIT');}
if(!$deniedText)throw new RuntimeException('Scalar text size limit was not enforced');
echo "data_pack_manifest_access_test: PASS\n";
