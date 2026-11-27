<?php
require_once __DIR__.'/../app/agent/context/builder.php';
$messages=[['role'=>'system','content'=>'System policy'] ,['role'=>'user','content'=>'Original objective']];
for($i=0;$i<40;$i++)$messages[]=['role'=>$i%2?'assistant':'user','content'=>str_repeat('historical message '.$i.' ',80)];
$messages[]=['role'=>'user','content'=>'Latest evidence and decision'];
$out=lac_agent_context_build(['messages'=>$messages],['token_budget'=>2048,'reserve_tokens'=>256,'recent_messages'=>6]);
$bounded=$out['payload']['messages'];
$all=json_encode($bounded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if(($bounded[0]['content']??'')!=='System policy')throw new RuntimeException('System instruction was not preserved.');
if(!str_contains($all,'Original objective'))throw new RuntimeException('Original objective was not preserved.');
if(!str_contains($all,'Latest evidence and decision'))throw new RuntimeException('Latest evidence was not preserved.');
if(empty($out['stats']['compacted'])||$out['stats']['messages_out']>count($messages))throw new RuntimeException('History was not compacted.');
if(!str_contains($all,'context_compaction_summary'))throw new RuntimeException('Compaction summary missing.');
echo "PASS: bounded context preserves instructions/objective/latest evidence and summarizes omitted history\n";
