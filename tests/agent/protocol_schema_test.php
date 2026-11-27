<?php
require_once __DIR__.'/../../app/agent/protocol.php';
require_once __DIR__.'/../../app/agent/schema_validator.php';
require_once __DIR__.'/../../app/agent/protocol_compiler.php';
require_once __DIR__.'/../../app/agent/tool_registry.php';
require_once __DIR__.'/../../app/agent/loop/protocol.php';
require_once __DIR__.'/../../app/agent/loop/recovery.php';
function t_assert(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function t_eq(mixed $a,mixed $b,string $message):void{t_assert($a===$b,$message.'; got '.var_export($a,true));}
$typed=lac_agent_json_value_to_php(json_decode('{"empty_object":{},"empty_array":[],"nested":{"x":{}}}'));
t_assert(($typed['empty_object']??null) instanceof stdClass,'{} must remain an object');
t_eq($typed['empty_array']??null,[],'[] must remain an array');
t_assert(($typed['nested']['x']??null) instanceof stdClass,'nested {} must remain an object');
$schema=['type'=>'object','properties'=>['obj'=>['type'=>'object'],'arr'=>['type'=>'array','items'=>['type'=>'integer']]],'required'=>['obj','arr'],'additionalProperties'=>false];
t_eq(lac_schema_validate_value(['obj'=>(object)[],'arr'=>[]],$schema),[],'schema accepts typed empty object and array');
t_assert(count(lac_schema_validate_value(['obj'=>[],'arr'=>(object)[]],$schema))>=1,'schema rejects object/array confusion');
$catalog=lac_tool_catalog();$available=[$catalog['python_calculate']];
$policy=lac_agent_execution_policy();t_eq($policy['protocol'],'UNIFIED_AGENT_PROTOCOL','one execution policy is active');t_eq($policy['allowed_types'],['tool_help_request','tool_call','final'],'one canonical message set is active');
$compiled=lac_agent_compile_protocol($available,'Calculate');
t_assert(!str_contains($compiled['system_text'],'toolbox_request'),'compiler does not request toolbox selection');
t_assert(!str_contains($compiled['system_text'],'action-tools://')&&!str_contains($compiled['system_text'],'final_stream'),'retired protocol syntax is absent');
t_assert(str_contains($compiled['system_text'],'already enabled')&&!str_contains($compiled['system_text'],'input_schema'),'compiler exposes enabled tools without preloading schemas');t_assert(str_contains($compiled['system_text'],'tool_call_id')&&str_contains($compiled['system_text'],'Provider-native tool_calls are unsupported'),'compiler defines invocation identity and explicitly rejects native calls');
t_assert(str_contains($compiled['system_text'],'python_calculate'),'catalog contains enabled tool');
$decoded=lac_agent_loop_decode_protocol('{"type":"tool_call","tool":"python_calculate","tool_call_id":"calc_001","arguments":{"expression":"2+2"}}');
t_eq($decoded['type']??null,'tool_call','canonical tool_call message decodes');
t_eq($decoded['arguments']->expression??null,'2+2','canonical arguments remain typed');
$plain=lac_agent_loop_decode_protocol('A direct answer.');t_eq($plain['type']??null,'final','ordinary text maps to direct final');
$callValidation=lac_agent_loop_validate_message($decoded);t_eq($callValidation['valid']??null,true,'tool_call with a stable ID validates');
$missingId=lac_agent_loop_validate_message(['type'=>'tool_call','tool'=>'python_calculate','arguments'=>(object)['expression'=>'2+2']]);t_eq($missingId['error']??null,'INVALID_PROTOCOL_FIELDS','tool_call without required ID is rejected');
$badId=lac_agent_loop_validate_message(['type'=>'tool_call','tool'=>'python_calculate','tool_call_id'=>'bad id','arguments'=>(object)['expression'=>'2+2']]);t_eq($badId['error']??null,'INVALID_TOOL_CALL_ID','tool_call ID format is validated');
$mixed=lac_agent_loop_decode_protocol('I will use the tool. {"type":"tool_call","tool":"python_calculate","tool_call_id":"calc_002","arguments":{"expression":"2+2"}}');t_eq($mixed['error_code']??null,'MIXED_PROTOCOL_OUTPUT','embedded protocol JSON is rejected rather than downgraded to final');
$malformedMixed=lac_agent_loop_decode_protocol('Text before {"type":"tool_call", broken}');t_eq($malformedMixed['type']??null,'invalid','malformed embedded protocol is rejected');
$feedback=lac_agent_loop_error_feedback('INVALID_PROTOCOL_FIELDS','protocol_validation','Rejected before execution.',true);t_eq($feedback['execution_started']??null,false,'structured protocol feedback confirms execution did not start');t_eq($feedback['error']['code']??null,'INVALID_PROTOCOL_FIELDS','structured protocol feedback carries a stable error code');
$retryFeedback=lac_agent_loop_tool_failure_payload(['tool'=>'python_calculate','tool_call_id'=>'calc_retry_1','error_code'=>'TEMPORARY_FAILURE','phenomenon'=>'Temporary failure.','retryable'=>true,'execution_started'=>true],[],1,1);t_eq($retryFeedback['tool_call_id']??null,'calc_retry_1','retry feedback preserves invocation identity');t_eq($retryFeedback['execution_started']??null,true,'retry feedback preserves execution-started state');

$unknown=lac_agent_loop_decode_protocol('{"type":"toolbox_request"}');t_eq($unknown['type']??null,'toolbox_request','retired messages are not normalized to supported types');
echo "OK: canonical protocol and schema regression tests passed\n";
