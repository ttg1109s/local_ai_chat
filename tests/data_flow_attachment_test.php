<?php
require_once __DIR__.'/../app/agent/data_flow_controller.php';
require_once __DIR__.'/../app/agent/attachment_manager.php';
$p=lac_data_flow_validate_plan(['operations'=>[['operation'=>'inspect_data_pack','data_ref'=>'agent-results/abcdef1234567890.json'],['operation'=>'read_data_pack','data_ref'=>'agent-results/abcdef1234567890.json','field'=>'rows','limit'=>20]]]);
if(count($p['operations'])!==2)throw new RuntimeException('Plan normalization failed');
$denied=false;try{lac_data_flow_validate_plan(['operations'=>[['operation'=>'delete_data_pack']]]);}catch(Throwable $e){$denied=true;}if(!$denied)throw new RuntimeException('Unsupported operation accepted');
$searchMissing=false;try{lac_data_flow_validate_plan(['operations'=>[['operation'=>'search_data_pack','field'=>'rows']]]);}catch(InvalidArgumentException $e){$searchMissing=$e->getMessage()==='DATA_FLOW_REFERENCE_REQUIRED';}if(!$searchMissing)throw new RuntimeException('Search operation without Data Pack reference was not rejected during validation');
$searchValid=lac_data_flow_validate_plan(['operations'=>[['operation'=>'search_data_pack','data_ref'=>'agent-results/abcdef1234567890.json','field'=>'rows']]]);if(($searchValid['operations'][0]['data_ref']??null)!=='agent-results/abcdef1234567890.json')throw new RuntimeException('Search Data Pack reference was not preserved');
$conversation='c_'.bin2hex(random_bytes(6));$message='m_'.bin2hex(random_bytes(6));
$a=lac_attachment_register(['conversation_id'=>$conversation,'message_id'=>$message,'source_type'=>'data_pack','source_ref'=>'agent-results/abcdef1234567890.json','presentation'=>'data_table','title'=>'Test table']);
if(!lac_attachment_get($a['attachment_id'],$conversation,$message))throw new RuntimeException('Attachment lookup failed');
if(count(lac_attachment_list($conversation,$message))!==1)throw new RuntimeException('Attachment listing failed');
echo "data_flow_attachment_test: PASS\\n";
