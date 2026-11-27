<?php
if(!function_exists('mb_substr')){function mb_substr($value,$start,$length=null,$encoding=null){return $length===null?substr((string)$value,$start):substr((string)$value,$start,$length);}}
require_once __DIR__.'/../app/agent/result_store.php';
require_once __DIR__.'/../app/agent/tool_registry.php';
require_once __DIR__.'/../app/agent/tool_permissions.php';
require_once __DIR__.'/../app/agent/loop/protocol.php';
require_once __DIR__.'/../app/agent/completion_validator.php';
$run='test_run_'.bin2hex(random_bytes(4));
$id=bin2hex(random_bytes(12));
$original=['action'=>'list','count'=>2,'websites'=>[
 ['name'=>'Weather','url'=>'https://weather.example/','search_url'=>''],
 ['name'=>'News','url'=>'https://news.example/','search_url'=>'']
]];
$packReceipt=lac_data_pack_put($run,'manage_websites',$id,$original);$ref=$packReceipt['data_ref'];
 if(!preg_match('/^res_[a-f0-9]{24}$/',(string)($packReceipt['result_id']??'')))throw new RuntimeException('Committed Data Pack is missing its stable result_id.');
 $committedRecord=json_decode((string)file_get_contents(lac_result_store_dir().'/'.$id.'.json'),true);
 if(($committedRecord['result_id']??null)!==$packReceipt['result_id']||($committedRecord['result']['result_id']??null)!==$packReceipt['result_id'])throw new RuntimeException('result_id must be persisted in both record metadata and canonical result.');
 $manifestRecord=lac_data_pack_manifest_get($ref,$run);
 if(($manifestRecord['result_id']??null)!==$packReceipt['result_id'])throw new RuntimeException('Data Pack manifest lost result_id provenance.');
try {
 $read=lac_data_pack_read_for_run($ref,$run,'websites',0,1);
 if(($read['total_items']??null)!==2 || count($read['data']??[])!==1 || ($read['data'][0]['name']??'')!=='Weather') {
  throw new RuntimeException('Scoped Data Pack read/path/pagination failed.');
 }
 $denied=false;
 try { lac_data_pack_read_for_run($ref,'another_run','websites',0,10); }
 catch(Throwable $e) { $denied=str_contains($e->getMessage(),'DATA_PACK_ACCESS_DENIED'); }
 if(!$denied)throw new RuntimeException('Cross-run Data Pack access was not denied.');
 $catalog=lac_tool_catalog();
 if(!isset($catalog['data_access']))throw new RuntimeException('Model-facing Data Access tool is missing.');
 if(in_array('data_access',lac_agent_permissions(['data_access']),true))throw new RuntimeException('System Data Access must not be user-selectable.');
 if(!empty($catalog['data_access']['internal'])&&!empty($catalog['data_access']['system_tool'])){
  $toolbox=lac_agent_loop_enabled_tool_map(['python_calculate','data_access'],$catalog);
  if(!isset($toolbox['data_access']))throw new RuntimeException('Protocol toolbox must expose Runtime-injected Data Access.');
 }
 $receipt=lac_data_pack_model_receipt(
  ['ok'=>true,'tool'=>'manage_websites','result'=>$original,'result_reference'=>$ref],
  ['data_ref'=>$ref,'execution_id'=>$id],null,null,['run_id'=>$run]
 );
 if(($receipt['preview']['websites'][0]['name']??'')!=='Weather')throw new RuntimeException('Website list preview missing.');
 $genericReceipt=lac_data_pack_model_receipt(
  ['ok'=>true,'tool'=>'python_generate_data','result'=>['datasets'=>[['name'=>'series_1','values'=>[1.25,2.5,3.75]]]],'result_reference'=>$ref],
  ['data_ref'=>$ref,'execution_id'=>$id],null,null,['run_id'=>$run]
 );
 if(array_key_exists('data_pack_source',$genericReceipt)||($genericReceipt['data_pack_read']['status']??'')!=='reference_only')throw new RuntimeException('Model receipt hydrated Data Pack payload into context.');
 $resolvedReads=[];
 $resolved=lac_data_pack_resolve_arguments(['values'=>['$data_ref'=>$ref,'$path'=>'websites']],$run,$resolvedReads);
 if(($resolved['values']['rows'][0]['name']??'')!=='Weather')throw new RuntimeException('Selective reference pagination failed.');
 $fullHydrationDenied=false;
 try { lac_data_pack_resolve_arguments(['values'=>['$data_ref'=>$ref]],$run); }
 catch(Throwable $e) { $fullHydrationDenied=str_contains($e->getMessage(),'DATA_PACK_FULL_HYDRATION_DISABLED'); }
 if(!$fullHydrationDenied)throw new RuntimeException('Unbounded full Data Pack hydration must be rejected.');
 $deniedResolve=false;
 try { lac_data_pack_resolve_arguments(['values'=>['$data_ref'=>$ref,'$path'=>'websites']],'other_run'); }
 catch(Throwable $e) { $deniedResolve=str_contains($e->getMessage(),'DATA_PACK_ACCESS_DENIED'); }
 if(!$deniedResolve)throw new RuntimeException('Runtime reference hydration bypassed run scope.');
 $dataId=bin2hex(random_bytes(12));
 $dataRun='test_contract_'.bin2hex(random_bytes(4));
 // Data Packs are tool-agnostic: test a non-Python tool and a nested arbitrary structure.
 $dataResult=['ok'=>true,'tool'=>'python_plot','result'=>[
  'document'=>['title'=>'Quarterly report','sections'=>[
   ['heading'=>'Revenue','paragraphs'=>['First paragraph','Second paragraph']],
   ['heading'=>'Costs','paragraphs'=>['Third paragraph']]
  ]],
  'metadata'=>['pages'=>3,'format'=>'pdf']
 ]];
 $dataReceipt=lac_data_pack_put($dataRun,'python_plot',$dataId,$dataResult);
 // New records must be checksum-verified before any payload is returned.
 $integrityPath=lac_result_store_dir().'/'.$dataId.'.json';
 $integrityRecord=json_decode((string)file_get_contents($integrityPath),true);
 if(($integrityRecord['integrity']['algorithm']??null)!=='sha256')throw new RuntimeException('Committed Data Pack is missing SHA-256 integrity metadata.');
 $integrityRecord['result']['result']['metadata']['pages']=99;
 file_put_contents($integrityPath,json_encode($integrityRecord,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 $tamperDetected=false;
 try{lac_data_pack_get_for_run($dataReceipt['data_ref'],$dataRun);}catch(Throwable $e){$tamperDetected=str_contains($e->getMessage(),'DATA_PACK_INTEGRITY_FAILED');}
 if(!$tamperDetected)throw new RuntimeException('Data Pack payload tampering was not detected.');
 $integrityRecord['result']=$dataResult;
 file_put_contents($integrityPath,json_encode($integrityRecord,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 // Recompute metadata after restoring the original payload for subsequent reads.
 $payloadJson=json_encode($dataResult,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
 $integrityRecord['integrity']['sha256']=hash('sha256',$payloadJson);
 $integrityRecord['integrity']['bytes']=strlen($payloadJson);
 file_put_contents($integrityPath,json_encode($integrityRecord,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 $page1=lac_data_pack_read_for_run($dataReceipt['data_ref'],$dataRun,'result.document.sections',0,1);
 if(($page1['total_items']??null)!==2||count($page1['data']??[])!==1||empty($page1['has_more']))throw new RuntimeException('Generic Data Pack nested pagination failed.');
 $page2=lac_data_pack_read_for_run($dataReceipt['data_ref'],$dataRun,'result.document.sections',1,1);
 if(($page2['data'][0]['heading']??'')!=='Costs'||!empty($page2['has_more']))throw new RuntimeException('Generic Data Pack pagination did not return the second page.');
 $contract=['type'=>'json_schema','schema'=>[
  'type'=>'object','required'=>['document','metadata'],'additionalProperties'=>false,
  'properties'=>[
   'document'=>['type'=>'object','required'=>['title','sections'],'properties'=>[
    'title'=>['type'=>'string'],'sections'=>['type'=>'array','minItems'=>2,'items'=>['type'=>'object','required'=>['heading']]]
   ]],
   'metadata'=>['type'=>'object','required'=>['pages'],'properties'=>['pages'=>['type'=>'integer','minimum'=>1]]]
  ]
 ]];
 $validFinal=json_encode(['document'=>['title'=>'Quarterly report','sections'=>[['heading'=>'Revenue'],['heading'=>'Costs']]],'metadata'=>['pages'=>3]],JSON_UNESCAPED_UNICODE);
 if(lac_agent_output_contract_issues($validFinal,$contract))throw new RuntimeException('Valid generic output contract was rejected.');
 $invalidFinal=json_encode(['document'=>['title'=>12,'sections'=>[['heading'=>'Revenue']]],'metadata'=>(object)[],'unexpected'=>true],JSON_UNESCAPED_UNICODE);
 $contractIssues=lac_agent_output_contract_issues($invalidFinal,$contract);
 if(!array_filter($contractIssues,static fn($issue)=>str_starts_with($issue,'OUTPUT_TYPE_MISMATCH')))throw new RuntimeException('Generic output type mismatch was not detected.');
 if(!array_filter($contractIssues,static fn($issue)=>str_starts_with($issue,'OUTPUT_REQUIRED_FIELD_MISSING')))throw new RuntimeException('Generic required-field mismatch was not detected.');
 if(!array_filter($contractIssues,static fn($issue)=>str_starts_with($issue,'OUTPUT_ADDITIONAL_FIELD')))throw new RuntimeException('Generic additional-field mismatch was not detected.');
 $cleanup=lac_data_pack_cleanup_run($dataRun);
 if(!empty($cleanup['enabled'])||!empty($cleanup['deleted']))throw new RuntimeException('Data Pack cleanup must remain disabled by default.');
 $retainedPath=lac_result_store_dir().'/'.$dataId.'.json';
 if(!is_file($retainedPath))throw new RuntimeException('Data Pack was deleted while cleanup is locked.');
 @unlink($retainedPath);
 echo "PASS: scoped reads, pagination, internal reader permission, generic previews, output contract validation, cleanup lock\n";
} finally {
 $path=lac_result_store_dir().'/'.$id.'.json';
 if(is_file($path))@unlink($path);
}
