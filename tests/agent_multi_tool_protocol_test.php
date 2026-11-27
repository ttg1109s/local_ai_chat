<?php
require_once __DIR__.'/../app/agent/protocol.php';
require_once __DIR__.'/../app/agent/tool_registry.php';
require_once __DIR__.'/../app/agent/schema_validator.php';
require_once __DIR__.'/../app/agent/loop/protocol.php';
$call=json_decode('{"type":"tool_call","tool":"python_calculate","tool_call_id":"calc-001","arguments":{"expression":"(2+3)*4"}}');
$validation=lac_agent_loop_validate_message((array)$call);
if(empty($validation['valid']))throw new RuntimeException('A canonical one-tool call with stable ID should validate.');
$catalog=lac_tool_catalog();
$errors=lac_tool_validate_arguments('python_calculate',(array)$call->arguments,$catalog['python_calculate']);
if($errors)throw new RuntimeException('Canonical arguments failed schema validation: '.implode(' ',$errors));
$missing=json_decode('{"type":"tool_call","tool":"python_calculate","arguments":{"expression":"2+2"}}',true);
$validation=lac_agent_loop_validate_message($missing);
if(($validation['error']??'')!=='INVALID_PROTOCOL_FIELDS')throw new RuntimeException('A tool call without invocation identity must be rejected.');
$legacy=lac_agent_loop_decode_protocol('action-tools://python_calculate?arg=%7B%22expression%22%3A%222%2B2%22%7D');
if(($legacy['type']??'')!=='final')throw new RuntimeException('Retired action URI syntax must not be interpreted as an executable tool call.');
echo "PASS: canonical single-call protocol, typed schema validation, and legacy URI rejection\n";
