<?php
require_once __DIR__.'/../app/agent/tool_logger.php';
$dir=sys_get_temp_dir().'/lac_trace_test_'.bin2hex(random_bytes(5));
if(!mkdir($dir,0775,true))throw new RuntimeException('Could not create test directory.');
$file=$dir.'/events.jsonl';
lac_unified_trace_set_context(['trace_file'=>$file,'trace_id'=>'trace_test','run_id'=>'run_test','conversation_id'=>'conversation_test','message_id'=>'message_test','started_ns'=>hrtime(true),'sequence'=>0,'request_id'=>'request_test']);
lac_agent_log('agent_tool_call_proposed',['tool_call_id'=>'call_test','execution_id'=>'execution_test','request_id'=>'request_test','round_id'=>2,'tool'=>'example_tool']);
lac_agent_log('agent_tool_call_rejected',['tool_call_id'=>'call_rejected','request_id'=>'request_test','reason'=>'INVALID_ARGUMENTS']);
lac_agent_log('execution_state_transition',['tool_call_id'=>'call_test','execution_id'=>'execution_test','to_state'=>'COMMITTED']);
lac_agent_log('agent_terminal',['run_id'=>'run_test','task_state'=>'completed_with_limitations','outcome'=>'partial']);
$rows=array_map(static fn($line)=>json_decode($line,true),file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
if(count($rows)!==4)throw new RuntimeException('Expected three trace records.');
$first=$rows[0];
foreach(['schema_version','event_id','sequence','timestamp','trace_id','run_id','request_id','tool_call_id','execution_id','category','event','severity','outcome','payload_hash','payload_bytes','data'] as $field){
 if(!array_key_exists($field,$first))throw new RuntimeException('Missing schema field: '.$field);
}
if($first['schema_version']!==2||$first['outcome']!=='proposed'||$first['request_id']!=='request_test'||$first['tool_call_id']!=='call_test')throw new RuntimeException('Correlation or event taxonomy failed.');
if($rows[1]['outcome']!=='rejected'||$rows[2]['outcome']!=='transition'||$rows[3]['outcome']!=='partial')throw new RuntimeException('Outcome taxonomy failed.');
if(array_column($rows,'sequence')!==[1,2,3,4])throw new RuntimeException('Sequence continuity failed.');
$lockPath=$file.'.lock';if(!is_file($lockPath))throw new RuntimeException('Trace writer must use stable lock file.');
$stale=$dir.'/old.jsonl';file_put_contents($stale,'old');touch($stale,time()-8*86400);$clean=lac_unified_trace_cleanup($dir,7);if(($clean['deleted']??0)!==1||is_file($stale))throw new RuntimeException('Trace retention cleanup failed for stale files.');
unlink($file);@unlink($lockPath);rmdir($dir);
lac_unified_trace_set_context(['trace_file'=>'','sequence'=>0]);
if(lac_unified_trace_write('trace_test_without_path')!==false||!empty(lac_unified_trace_health()['healthy']))throw new RuntimeException('Missing trace path was not reported as degraded.');
echo "PASS: trace schema v2, event taxonomy, correlation identifiers, and durable append\n";
