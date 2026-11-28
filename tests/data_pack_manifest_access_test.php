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
echo "data_pack_manifest_access_test: PASS\n";
