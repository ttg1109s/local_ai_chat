<?php
require_once __DIR__.'/../app/agent/result_store.php';
require_once __DIR__.'/../app/agent/execution_store.php';
require_once __DIR__.'/../app/agent/loop/state.php';
function runtime_state_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$run='runtime_state_'.bin2hex(random_bytes(6));$call='call_'.bin2hex(random_bytes(6));$tool='python_generate_data';$execution='exec_'.bin2hex(random_bytes(8));
$pack=lac_data_pack_put($run,$tool,$execution,['ok'=>true,'tool'=>$tool,'datasets'=>[['values'=>[1,2,3]]]]);
$record=['tool'=>$tool,'state'=>'COMMITTED','result'=>['ok'=>true,'tool'=>$tool,'execution_id'=>$execution,'result_reference'=>$pack['data_ref']],'result_hash'=>hash('sha256','receipt'),'committed_at'=>date(DATE_ATOM)];
lac_execution_write($run,$call,$record);
$state=lac_agent_loop_execution_state($run,[['tool'=>$tool,'tool_call_id'=>$call,'ok'=>true]],[['name'=>$tool],['name'=>'python_plot']]);
runtime_state_assert($state['has_executed_tool']===true,'A committed execution must set has_executed_tool.');
runtime_state_assert($state['successful_tools']===[$tool],'Only committed tool names should appear as successful.');
runtime_state_assert(($state['committed_executions'][0]['result_available']??false)===true,'A committed Data Pack should be reported as readable while it exists.');
runtime_state_assert(in_array('python_plot',$state['available_tools'],true),'Tool availability must remain distinct from successful execution.');
$failedRun=$run.'_failed';$failedState=lac_agent_loop_execution_state($failedRun,[['tool'=>'python_plot','tool_call_id'=>'call_missing','ok'=>false,'error_code'=>'TOOL_FAILED']],[['name'=>'python_plot']]);
runtime_state_assert($failedState['has_executed_tool']===false,'A failed call must not count as successful execution.');
runtime_state_assert($failedState['successful_tools']===[],'A failed tool must not appear in successful_tools.');
@unlink(lac_execution_dir().'/'.lac_execution_key($run,$call).'.json');
@unlink(lac_execution_dir().'/'.lac_execution_key($run,$call).'.lock');
$packPath=lac_result_store_dir().'/'.basename($pack['data_ref']);@unlink($packPath);
echo "PASS: PHP-owned runtime execution state distinguishes availability, failure, committed execution, and Data Pack availability\n";
