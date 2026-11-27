<?php
require_once __DIR__.'/../app/agent/tool_registry.php';
require_once __DIR__.'/../app/agent/tool_permissions.php';
require_once __DIR__.'/../app/agent/protocol_compiler.php';
$catalog=lac_tool_catalog();$allowed=['python_plot'];
$valid=[['tool'=>'python_plot','tool_call_id'=>'plot-1','arguments'=>['chart_type'=>'line']]];
if(lac_agent_tool_selection_violations($valid,$catalog,$allowed)!==[])throw new RuntimeException('An enabled tool must pass preflight.');
$unknown=[['tool'=>'write_paragraph','tool_call_id'=>'bad-1','arguments'=>[]]];
if((lac_agent_tool_selection_violations($unknown,$catalog,$allowed)[0]['error_code']??'')!=='UNKNOWN_TOOL')throw new RuntimeException('Unknown tools must be rejected.');
$disabled=[['tool'=>'python_calculate','tool_call_id'=>'bad-2','arguments'=>['expression'=>'2+3']]];
if((lac_agent_tool_selection_violations($disabled,$catalog,$allowed)[0]['error_code']??'')!=='TOOL_NOT_ALLOWED')throw new RuntimeException('Registered but disabled tools must be rejected.');
$compiled=lac_agent_compile_protocol([$catalog['python_plot']]);
if(!str_contains($compiled['system_text'],'already enabled')||!str_contains($compiled['system_text'],'tool_help_request'))throw new RuntimeException('Enabled capabilities must be exposed immediately with on-demand schema help.');
if(!str_contains($compiled['system_text'],'Provider-native tool_calls are unsupported'))throw new RuntimeException('Provider-native calls must remain disabled.');
$loop=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
if(!str_contains($loop,'$selectionViolations=lac_agent_tool_selection_violations'))throw new RuntimeException('Server-side permission preflight is missing.');
if(!str_contains($loop,'batch_rejected_atomically'))throw new RuntimeException('Atomic batch rejection audit log is missing.');
echo "PASS: strict opt-in permissions, unknown/disabled tool rejection, and native-tool policy\n";
