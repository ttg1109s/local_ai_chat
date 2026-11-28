<?php
/** Runtime-owned, bounded Data Flow plan validator and dispatcher. */
function lac_data_flow_validate_plan(array $plan,array $policy=[]): array {
 $allowed=['inspect_data_pack','search_data_pack','read_data_pack','query_data_pack','attach_data_pack','verify_data_pack'];$ops=$plan['operations']??[];if(!is_array($ops))throw new InvalidArgumentException('DATA_FLOW_OPERATIONS_INVALID');$max=max(1,min(20,(int)($policy['max_operations']??8)));if(count($ops)>$max)throw new RuntimeException('DATA_FLOW_BUDGET_EXCEEDED');$clean=[];
 foreach($ops as $op){if(!is_array($op))throw new InvalidArgumentException('DATA_FLOW_OPERATION_INVALID');$type=(string)($op['operation']??'');if(!in_array($type,$allowed,true))throw new InvalidArgumentException('DATA_FLOW_OPERATION_UNSUPPORTED');$ref=(string)($op['data_ref']??'');if($type!=='attach_data_pack'&&$ref==='' )throw new InvalidArgumentException('DATA_FLOW_REFERENCE_REQUIRED');if($ref!==''&&!preg_match('~^agent-results/[a-f0-9]{8,64}\.json$~i',$ref))throw new InvalidArgumentException('DATA_FLOW_REFERENCE_INVALID');$field=(string)($op['field']??'');if(strlen($field)>128||preg_match('/[\x00-\x1f]/',$field))throw new InvalidArgumentException('DATA_FLOW_FIELD_INVALID');$limit=max(1,min(1000,(int)($op['limit']??100)));$offset=max(0,min(200000,(int)($op['offset']??0)));$clean[]=['operation'=>$type,'data_ref'=>$ref?:null,'field'=>$field?:null,'offset'=>$offset,'limit'=>$limit,'where'=>is_array($op['where']??null)?$op['where']:[],'sort'=>is_array($op['sort']??null)?$op['sort']:null,'aggregate'=>is_array($op['aggregate']??null)?array_slice($op['aggregate'],0,20):[],'max_scan_rows'=>max(1000,min(200000,(int)($op['max_scan_rows']??50000))),'presentation'=>isset($op['presentation'])?(string)$op['presentation']:null,'title'=>substr((string)($op['title']??''),0,180)];}
 return ['version'=>2,'operations'=>$clean,'validated_at'=>date(DATE_ATOM)];
}
function lac_data_flow_execute_read(array $op,string $runId,array $context=[]): array {
 require_once __DIR__.'/data_access.php';$ref=(string)($op['data_ref']??'');switch($op['operation']??''){
  case 'inspect_data_pack':return lac_data_pack_inspect($ref,$runId);
  case 'read_data_pack':return lac_data_pack_read_segmented($ref,$runId,(string)($op['field']??''),(int)($op['offset']??0),(int)($op['limit']??100));
  case 'query_data_pack':return lac_data_pack_query($ref,$runId,(string)($op['field']??''),$op);
  case 'verify_data_pack':return lac_data_pack_verify($ref,$runId);
  case 'search_data_pack':$m=lac_data_pack_manifest_get($ref,$runId);if(!$m)throw new RuntimeException('DATA_PACK_MANIFEST_NOT_FOUND');$needle=strtolower((string)($op['field']??''));$matches=[];foreach($m['fields'] as $name=>$meta)if($needle===''||str_contains(strtolower((string)$name),$needle))$matches[]=['field'=>$name,'metadata'=>$meta];return ['data_ref'=>$ref,'matches'=>$matches];
  case 'attach_data_pack':require_once __DIR__.'/attachment_manager.php';$item=['conversation_id'=>$context['conversation_id']??'','message_id'=>$context['message_id']??'','run_id'=>$runId,'source_type'=>'data_pack','source_ref'=>$ref,'title'=>$op['title']??'Data Pack','presentation'=>$op['presentation']??'file','visibility'=>'user'];return lac_attachment_register($item);
  default:throw new InvalidArgumentException('DATA_FLOW_OPERATION_NOT_EXECUTABLE');
 }
}
function lac_data_flow_execute_plan(array $validated,string $runId,array $context=[]): array {
 $out=[];foreach($validated['operations'] as $index=>$op){try{$out[]=['index'=>$index,'operation'=>$op['operation'],'ok'=>true,'result'=>lac_data_flow_execute_read($op,$runId,$context)];}catch(Throwable $e){$out[]=['index'=>$index,'operation'=>$op['operation'],'ok'=>false,'error_code'=>$e->getMessage()];}}return ['version'=>2,'run_id'=>$runId,'results'=>$out,'completed'=>count(array_filter($out,static fn($r)=>$r['ok'])),'failed'=>count(array_filter($out,static fn($r)=>!$r['ok']))];
}
