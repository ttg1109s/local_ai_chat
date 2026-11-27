<?php
require_once __DIR__.'/../app/agent/tool_registry.php';
require_once __DIR__.'/../app/agent/execution_store.php';
require_once __DIR__.'/../app/agent/result_store.php';
require_once __DIR__.'/../app/agent/completion_validator.php';
function runtime_check(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$run='test_'.bin2hex(random_bytes(5));$call='call_'.bin2hex(random_bytes(5));
lac_execution_transition($run,$call,'REQUESTED',['tool'=>'mock','arguments_hash'=>hash('sha256','{}'),'execution_id'=>bin2hex(random_bytes(8)),'attempt'=>1]);
lac_execution_transition($run,$call,'VALIDATED');
lac_execution_transition($run,$call,'DISPATCHED');
lac_execution_transition($run,$call,'EXECUTING');
lac_execution_transition($run,$call,'RESULT_RECEIVED',['result_status'=>'success']);
lac_execution_transition($run,$call,'COMMITTED');
$record=lac_execution_read($run,$call);
runtime_check(($record['state']??'')==='COMMITTED','valid lifecycle commits');
runtime_check(count($record['history']??[])===6,'lifecycle history is durable');
$bad=false;try{lac_execution_transition($run,$call,'EXECUTING');}catch(Throwable $e){$bad=true;}
runtime_check($bad,'illegal lifecycle transition rejected');
$ref=lac_result_store_put(bin2hex(random_bytes(8)),['ok'=>true,'value'=>42]);
runtime_check((lac_result_store_get($ref)['value']??null)===42,'result store roundtrip');
$catalog=lac_tool_catalog();
runtime_check(($catalog['search_web']['idempotent']??false)===true,'read-only tool defaults idempotent');
runtime_check(($catalog['python_plot']['cache_policy']??'')==='never','compute results are not semantically cached');
$gate=lac_agent_completion_validate(['success_criteria'=>['c1']], [['id'=>'n1','status'=>'completed','success_criteria'=>[0]]], ['n1'=>['ok'=>true]]);
runtime_check(!empty($gate['complete']),'completion validator accepts committed successful evidence');
$blocked=lac_agent_completion_validate(['success_criteria'=>['c1']], [['id'=>'n1','status'=>'blocked']], []);
runtime_check(empty($blocked['complete']),'completion validator rejects blocked node');
echo "Agent runtime tests passed.\\n";
