<?php
require_once __DIR__.'/../app/agent/result_store.php';
require_once __DIR__.'/../app/agent/execution_store.php';
$run='recovery_commit_'.bin2hex(random_bytes(5));$call='call_'.bin2hex(random_bytes(5));$exec=bin2hex(random_bytes(12));
$result=['ok'=>true,'tool'=>'python_statistics','execution_id'=>$exec,'tool_call_id'=>$call,'result'=>['mean'=>4.2]];
$pack=lac_data_pack_put($run,'python_statistics',$exec,$result);
// Simulate a crash after the Data Pack commit, before ledger receipt/commit.
lac_execution_write($run,$call,['schema_version'=>2,'tool'=>'python_statistics','execution_id'=>$exec,'attempt'=>1,'state'=>'RESULT_RECEIVED','invocation_status'=>'RESULT_RECEIVED','execution_started'=>true,'history'=>[['state'=>'EXECUTING','at'=>date(DATE_ATOM)]]]);
$reconcile=lac_invocation_reconcile($run);
$record=lac_invocation_get($run,$call);
if(($record['state']??'')!=='COMMITTED')throw new RuntimeException('Verified Data Pack result was not promoted to COMMITTED.');
if(lac_execution_verify_success($run,$call,'python_statistics')===null)throw new RuntimeException('Recovered commit has no verifiable receipt.');
@unlink(lac_execution_dir().'/'.lac_execution_key($run,$call).'.json');@unlink(lac_execution_dir().'/'.lac_execution_key($run,$call).'.lock');
@unlink(lac_result_store_dir().'/'.$exec.'.json');
echo "PASS: reconciliation recovers a verified Data Pack committed before the execution ledger\n";
