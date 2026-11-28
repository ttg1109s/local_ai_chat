<?php
require_once __DIR__.'/../app/agent/result_store.php';
require_once __DIR__.'/../app/agent/manifest_manager.php';
$run='cleanup_'.bin2hex(random_bytes(5));$exec=bin2hex(random_bytes(12));$rows=[];
for($i=0;$i<120;$i++)$rows[]=['id'=>$i,'value'=>'v'.$i];
$pack=lac_data_pack_put($run,'cleanup_fixture',$exec,['rows'=>$rows]);$ref=$pack['data_ref'];
$manifest=lac_data_pack_manifest_get($ref,$run);if(!$manifest)throw new RuntimeException('Manifest missing before cleanup.');
$files=[];foreach($manifest['fields']['rows']['segments']??[] as $segment)$files[]=lac_data_pack_manifest_dir().'/'.$segment['file'];
$manifestPath=lac_data_pack_manifest_path($ref);putenv('LAC_DATA_PACK_CLEANUP_ENABLED=1');
$result=lac_data_pack_cleanup_run($run);
if(($result['deleted']??0)!==1)throw new RuntimeException('Expected exactly one canonical result to be removed.');
if(is_file(lac_result_store_dir().'/'.$exec.'.json'))throw new RuntimeException('Canonical result survived cleanup.');
if($manifestPath&&is_file($manifestPath))throw new RuntimeException('Manifest survived cleanup.');
foreach($files as $file)if(is_file($file))throw new RuntimeException('Segment survived cleanup.');

// Cleanup must also reclaim segments left by a manifest-build interruption.
$orphanRun='cleanup_orphan_'.bin2hex(random_bytes(5));$orphanExec=bin2hex(random_bytes(12));
$orphanPack=lac_data_pack_put($orphanRun,'cleanup_fixture',$orphanExec,['rows'=>$rows]);
$orphanRef=$orphanPack['data_ref'];$orphanManifest=lac_data_pack_manifest_get($orphanRef,$orphanRun);
$orphanFiles=[];foreach($orphanManifest['fields']['rows']['segments']??[] as $segment)$orphanFiles[]=lac_data_pack_manifest_dir().'/'.$segment['file'];
$orphanManifestPath=lac_data_pack_manifest_path($orphanRef);if($orphanManifestPath)@unlink($orphanManifestPath);
$orphanCleanup=lac_data_pack_cleanup_run($orphanRun);
foreach($orphanFiles as $file)if(is_file($file))throw new RuntimeException('Orphaned segment survived cleanup without a manifest.');
if(($orphanCleanup['deleted']??0)!==1)throw new RuntimeException('Orphaned cleanup did not remove its canonical result.');

putenv('LAC_DATA_PACK_CLEANUP_ENABLED');
echo "Data Pack cleanup test passed.\n";
