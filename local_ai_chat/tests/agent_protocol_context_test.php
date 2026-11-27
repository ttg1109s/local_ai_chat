<?php
require_once __DIR__.'/../app/agent/tool_registry.php';
require_once __DIR__.'/../app/agent/protocol_compiler.php';
require_once __DIR__.'/../app/agent/loop/protocol.php';
$catalog=lac_tool_catalog();
$compiled=lac_agent_compile_protocol([$catalog['python_calculate']],'Calculate 2+2');
if(!str_contains($compiled['system_text'],'tool_call_id'))throw new RuntimeException('The canonical protocol must require invocation IDs.');
if(!str_contains($compiled['system_text'],'Provider-native tool_calls are unsupported'))throw new RuntimeException('Provider-native tool calls must remain explicitly unsupported.');
if(str_contains($compiled['system_text'],'TOOL EXECUTION EXHAUSTED')||str_contains($compiled['system_text'],'FINALIZATION REQUIRED'))throw new RuntimeException('The compiler must not advertise an unreachable forced-finalization state.');
if(($compiled['contract']['version']??'')!=='4.3')throw new RuntimeException('Protocol contract version was not advanced.');
$loop=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
if(str_contains($loop,'finalizationRequired'))throw new RuntimeException('Unreachable finalization state must not remain in the loop.');
if(str_contains($loop,'validated_structured_final')||!str_contains($loop,"'goal_completion_verified'=>false"))throw new RuntimeException('A validated final response must not be misrepresented as proof of goal completion.');
if(!str_contains($loop,"'outcome'=>['completed'=>'response_delivered'"))throw new RuntimeException('Terminal telemetry must distinguish response delivery from goal success.');

echo "PASS: protocol context, invocation identity, and native-tool rejection policy\n";
