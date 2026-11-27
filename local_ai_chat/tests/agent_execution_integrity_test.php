<?php
require_once __DIR__.'/../app/agent/execution_store.php';
require_once __DIR__.'/../app/agent/completion_validator.php';

function integrity_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$run='integrity_'.bin2hex(random_bytes(6));$call='call_'.bin2hex(random_bytes(6));
$record=[
 'tool'=>'search_web',
 'state'=>'COMMITTED',
 'result'=>['ok'=>true,'tool'=>'search_web','execution_id'=>'exec_test','result_reference'=>'agent-results/test.json'],
 'result_hash'=>hash('sha256','test'),
 'committed_at'=>date(DATE_ATOM)
];
lac_execution_write($run,$call,$record);
$verified=lac_execution_verify_success($run,$call,'search_web');
integrity_assert(is_array($verified)&&$verified['state']==='COMMITTED'&&$verified['tool_call_id']===$call,'Only committed ledger records may produce a success receipt.');
integrity_assert(lac_execution_verify_success($run,$call,'python_plot')===null,'A receipt cannot be replayed for a different tool.');

$noReceipt=lac_agent_unverified_tool_claims('I searched the web and found three sources.',[]);
integrity_assert(in_array('WEB_SEARCH_CLAIM',$noReceipt,true),'Unreceipted web-search claims must be detected.');
integrity_assert(in_array('WEB_SEARCH_CLAIM',lac_agent_unverified_tool_claims(json_decode('"T\u00f4i \u0111\u00e3 t\u00ecm ki\u1ebfm tr\u00ean web v\u00e0 t\u00ecm \u0111\u01b0\u1ee3c ba ngu\u1ed3n."'),[]),true),'Unreceipted Vietnamese web-search claims must also be detected.');
$siteReadCalls=[['tool'=>'manage_websites','ok'=>true,'execution_receipt'=>$verified]];
integrity_assert(in_array('WEB_SEARCH_CLAIM',lac_agent_unverified_tool_claims('I searched the web and found three sources.',$siteReadCalls),true),'A configured-website read receipt must not be treated as proof of public web search.');
$verifiedCalls=[['tool'=>'search_web','ok'=>true,'execution_receipt'=>$verified]];
integrity_assert(lac_agent_unverified_tool_claims('I searched the web and found three sources.',$verifiedCalls)===[],'A matching committed receipt should authorize the corresponding tool-use claim.');
integrity_assert(in_array('COMPUTATION_CLAIM',lac_agent_unverified_tool_claims('I calculated the result using code.',[]),true),'Unreceipted computation claims must be detected.');

$path=lac_execution_dir().'/'.lac_execution_key($run,$call).'.json';@unlink($path);@unlink($path.'.lock');
echo "PASS: Runtime execution receipts and tool-use provenance validation\n";
