<?php
require_once __DIR__.'/../app/agent/tool_registry.php';
require_once __DIR__.'/../app/agent/protocol.php';
require_once __DIR__.'/../app/agent/protocol_compiler.php';

$runner=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
$orchestrator=file_get_contents(__DIR__.'/../app/chat/orchestrator.php');
$policy=lac_agent_execution_policy();
$compiled=lac_agent_compile_protocol([], 'Explain this concept.');
if(!str_contains($compiled['system_text'],'No tools are enabled by the user'))throw new RuntimeException('Empty tool snapshot must produce direct-response guidance.');
if(!in_array('tool_help_request',$policy['allowed_types'],true))throw new RuntimeException('Tool schema help must be part of the canonical protocol policy.');
if(!in_array('tool_call',$policy['allowed_types'],true)||!in_array('final',$policy['allowed_types'],true))throw new RuntimeException('Canonical protocol must support tool calls and final answers.');
if(str_contains($compiled['contract']['messages']?implode(',',$compiled['contract']['messages']):'','final_text'))throw new RuntimeException('Unsupported final_text message type must not be advertised.');
if(str_contains($runner,'read_data_pack'))throw new RuntimeException('Data Pack access must remain a Runtime-owned capability, not a model-callable tool.');
if(!str_contains($orchestrator,'$handoff=(bool)$available&&in_array($dispatchType,[\'tool_help_request\',\'tool_call\'],true);'))throw new RuntimeException('Only an explicit model tool request may hand off to Agent Runtime.');
if(!str_contains($orchestrator,'return lac_agent_run($pdo,$endpoint,$dispatchPayload,$userEnabledToolIds,$context,$emit);'))throw new RuntimeException('Chat Orchestrator must call Agent Runtime only after model handoff.');
if(str_contains($runner,'capability dispatch happens before Agent Runtime'))throw new RuntimeException('Agent Runtime must not own Round 0 capability dispatch.');
if(!str_contains($orchestrator,"'task_state'=>'direct_completed'"))throw new RuntimeException('Direct model completion path is missing.');
foreach(['app/agent/intent_guard.php','app/agent/structured_intent.php'] as $removed){if(file_exists(__DIR__.'/../'.$removed))throw new RuntimeException('Legacy keyword intent classifier remains: '.$removed);}
$protocol=file_get_contents(__DIR__.'/../app/agent/protocol.php');
if(!str_contains($protocol,'function lac_agent_json_object'))throw new RuntimeException('Canonical JSON object validation helper is missing.');
echo "PASS: lazy Agent dispatch, immutable user-enabled tool snapshot, conditional Data Pack helper, and canonical protocol\n";
