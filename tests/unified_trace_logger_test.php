<?php
require_once __DIR__ . '/../app/agent/tool_logger.php';
$dir=sys_get_temp_dir().'/lac_trace_test_'.bin2hex(random_bytes(4));
@mkdir($dir,0777,true);
$file=$dir.'/123.jsonl';
lac_unified_trace_set_context([
 'trace_file'=>$file,'trace_id'=>'trace_test','run_id'=>'run_test',
 'message_id'=>123,'conversation_id'=>9,'started_ns'=>hrtime(true),'sequence'=>0
]);
lac_agent_log('agent_started',['mode'=>'debug','api_key'=>'secret-value']);
lac_unified_trace_write('tool_execution_completed',['tool'=>'search_web','duration_ms'=>42,'arguments'=>['q'=>'secret prompt']],'tool');
lac_unified_trace_write('delta',['text'=>'must never persist'],'model');
lac_agent_log('model_output',['model_output'=>str_repeat('M',3000)]);
lac_agent_log('agent_tool_call_proposed',['arguments_json'=>'{"query":"diagnostic query"}']);
$lines=file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
$rows=array_map(static fn($line)=>json_decode($line,true),$lines);
$ok=count($rows)===4
 && ($rows[0]['sequence']??0)===1
 && ($rows[1]['sequence']??0)===2
 && ($rows[0]['source']??'')==='agent'
 && ($rows[1]['source']??'')==='tool'
 && !str_contains(file_get_contents($file),'secret-value')
 && !str_contains(file_get_contents($file),'must never persist')
 && ($rows[0]['data']['api_key']??'')==='[redacted]'
 && strlen((string)($rows[2]['data']['model_output']??''))===3000
 && json_decode((string)($rows[3]['data']['arguments_json']??''),true)===['query'=>'diagnostic query'];
echo $ok ? "Unified trace logger tests passed.\n" : "Unified trace logger tests failed.\n";
foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);
exit($ok?0:1);
