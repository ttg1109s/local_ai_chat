<?php
require_once __DIR__.'/../app/agent/task_store.php';
require_once __DIR__.'/../app/agent/result_store.php';

$runId=bin2hex(random_bytes(10));
$executionId=bin2hex(random_bytes(10));
$toolCallId='call_cancel_retention_'.bin2hex(random_bytes(4));
putenv('LAC_DATA_PACK_CLEANUP_ENABLED=1');

$receipt=lac_data_pack_put($runId,'test_tool',$executionId,[
 'ok'=>true,'tool'=>'test_tool','execution_id'=>$executionId,'tool_call_id'=>$toolCallId,
 'rows'=>[['value'=>42]]
]);
$executionState=['committed_executions'=>[[
 'execution_id'=>$executionId,'tool'=>'test_tool','tool_call_id'=>$toolCallId,
 'result_available'=>true,'result_reference'=>$receipt['data_ref']
]]];

lac_agent_task_save($runId,'running',[
 'completed_tool_count'=>1,
 'tool_execution_state'=>$executionState
]);
lac_agent_task_save($runId,'cancelled',[
 'completed_tool_count'=>1,
 'tool_execution_state'=>$executionState
],'running');

if(!lac_data_pack_exists_for_run($receipt['data_ref'],$runId)) {
 throw new RuntimeException('Committed Data Pack was deleted after cancellation.');
}
$task=lac_agent_task_load($runId);
if(empty($task['result_data_retained'])) {
 throw new RuntimeException('Cancelled task checkpoint did not mark committed result retention.');
}
if(($task['tool_execution_state']['committed_executions'][0]['result_available']??false)!==true) {
 throw new RuntimeException('Cancelled checkpoint incorrectly marked committed result unavailable.');
}

// Explicitly clean up the isolated test run.
lac_data_pack_cleanup_run($runId);
@unlink(lac_agent_task_path($runId));
@unlink(lac_agent_task_path($runId).'.lock');
echo "task_store_cancellation_retention_test: PASS\\n";
