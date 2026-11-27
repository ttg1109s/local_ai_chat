<?php
require_once __DIR__.'/../app/agent/protocol.php';
$batch=[
 ['invalid'=>true,'reason'=>'invalid_json_syntax','tool'=>'python_plot','detail'=>str_repeat('X',1000),'position'=>42],
 ['invalid'=>true,'reason'=>'missing_end_marker','marker'=>'[[LAC_TOOL_CALL]]'],
];
$diagnostic=lac_agent_protocol_repair_diagnostic($batch);
if(strlen($diagnostic)>2500)throw new RuntimeException('repair diagnostic exceeds bound');
if(str_contains($diagnostic,str_repeat('X',300)))throw new RuntimeException('diagnostic contains unbounded parser detail');
if(!str_contains($diagnostic,'invalid_json_syntax')||!str_contains($diagnostic,'python_plot'))throw new RuntimeException('diagnostic omits useful metadata');
echo "PASS protocol repair diagnostic is bounded and structured\n";

$loop=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
if(!preg_match('/if\\(\\$repairableProtocolError&&\\$protocolRepairAttempts<\\$maxProtocolRepairAttempts\\)\\s*\\{[\\s\\S]{0,180}?\\$protocolRepairAttempts\\+\\+;/',$loop))throw new RuntimeException('Protocol repair attempt counter must increment before retry.');
echo "PASS protocol repair retry budget increments and remains bounded\\n";
