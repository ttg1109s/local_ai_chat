<?php
$loop=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
$bootstrap=file_get_contents(__DIR__.'/../app/agent/loop/bootstrap.php');
if(!str_contains($bootstrap,"completion_validator.php"))throw new RuntimeException('Agent entry point must declare the completion validator dependency');
require_once __DIR__.'/../app/agent/loop/runner.php';
if(!str_contains(file_get_contents(__DIR__.'/../api_routes/chat_stream.php'),'fresh_model_round_without_tools'))throw new RuntimeException('Runtime exception must trigger an isolated recovery attempt instead of immediately failing the task');
$client=file_get_contents(__DIR__.'/../assets/app.js');
if(!str_contains($client,"ev==='clear_delta'")||!str_contains($client,"setAssistantGenerationState(assistantBody,generationModelLabel,'typing',false)"))throw new RuntimeException('Client must support provisional-draft retraction and the live typing state');
if(!function_exists('lac_agent_unverified_tool_claims'))throw new RuntimeException('Agent entry point did not load completion_validator.php');
if(!str_contains(file_get_contents(__DIR__.'/../app/agent/protocol_compiler.php'),'without a wrapper'))throw new RuntimeException('Agent protocol must explicitly allow direct final text without a wrapper.');
$transport=file_get_contents(__DIR__.'/../app/agent/model_transport.php');if(!str_contains($transport,"'raw'")||!str_contains($transport,"lac_agent_log('model_output'"))throw new RuntimeException('Model transport must support provisional raw-text streaming and persist model output in the trace.');
if(!str_contains(file_get_contents(__DIR__.'/../app/agent/loop/runner.php'),"lac_agent_log('agent_tool_call_proposed'"))throw new RuntimeException('Agent must trace the exact proposed tool arguments.');
if(!str_contains(file_get_contents(__DIR__.'/../api_routes/chat_stream.php'),'agent_protocol_recovery_started'))throw new RuntimeException('Invalid protocol exhaustion must trigger isolated synthesis recovery.');
if(str_contains(file_get_contents(__DIR__.'/../api_routes/chat_stream.php'),'The Agent encountered an internal runtime failure'))throw new RuntimeException('Internal Agent runtime details must not be appended to user-facing chat.');
echo "PASS: active Agent entry point loads completion validation dependency\n";
