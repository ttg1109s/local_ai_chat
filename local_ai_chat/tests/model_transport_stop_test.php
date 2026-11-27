<?php
require_once __DIR__.'/../app/agent/model_transport.php';
$source=file_get_contents(__DIR__.'/../app/agent/model_transport.php');
$checks=[
 'progress_callback'=>str_contains($source,'CURLOPT_XFERINFOFUNCTION'),
 'progress_enabled'=>str_contains($source,'CURLOPT_NOPROGRESS=>false'),
 'stream_callback_stop_check'=>str_contains($source,'$isStopRequested()'),
 'cancel_sentinel'=>str_contains($source,"RuntimeException('LAC_AGENT_STOPPED')"),
 'cancel_event'=>str_contains($source,"model_request_cancelled"),
 'no_total_timeout'=>str_contains($source,'CURLOPT_TIMEOUT=>0'),
 'no_connect_timeout'=>str_contains($source,'CURLOPT_CONNECTTIMEOUT=>0'),
 'no_low_speed_timeout'=>str_contains($source,'CURLOPT_LOW_SPEED_TIME=>0'),
 'agent_stop_marks_cancelled'=>str_contains(file_get_contents(__DIR__.'/../app/agent/loop/runner.php'),'$save(\'cancelled\')'),
 'plain_text_incremental_streaming'=>str_contains($source,'first non-whitespace byte proves this is not a JSON control/envelope'),
 'tool_protocol_not_streamed'=>str_contains($source,'JSON control messages stay private'),
 'provisional_final_retraction'=>str_contains(file_get_contents(__DIR__.'/../app/agent/loop/runner.php'),'$emit(\'clear_delta\''),
];
foreach($checks as $name=>$ok)if(!$ok)throw new RuntimeException('Missing stop transport behavior: '.$name);
echo "PASS: active stop cancellation hooks are present\\n";
