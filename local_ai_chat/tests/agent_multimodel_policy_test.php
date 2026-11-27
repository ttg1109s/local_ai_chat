<?php
require_once __DIR__.'/../app/agent/response_contract.php';
require_once __DIR__.'/../app/agent/protocol.php';
require_once __DIR__.'/../app/agent/tool_registry.php';
require_once __DIR__.'/../app/agent/protocol_compiler.php';
require_once __DIR__.'/../app/agent/execution_policy.php';
function multimodel_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
multimodel_assert(lac_agent_should_constrain_response('http://127.0.0.1:8080/v1/chat/completions')===false,'Localhost must not imply constrained JSON Schema support');
multimodel_assert(lac_agent_should_constrain_response('https://remote.example/v1/chat/completions')===false,'Remote endpoints must not assume constrained JSON Schema support');
$catalog=lac_tool_catalog();$compiled=lac_agent_compile_protocol([$catalog['python_calculate']]);
foreach(['enabled by the user','tool_help_request','tool_call','tool_result','CANONICAL ENGLISH','tool_call_id'] as $needle)multimodel_assert(str_contains($compiled['system_text'],$needle),'Unified protocol missing: '.$needle);
multimodel_assert(!str_contains($compiled['system_text'],'kind=plan'),'Unified protocol must not request planning graphs');
$policy=lac_agent_execution_policy();multimodel_assert($policy['protocol']==='UNIFIED_AGENT_PROTOCOL'&&!isset($policy['executes_dag'])&&$policy['allowed_types']===['tool_help_request','tool_call','final'],'The Agent must expose one unified execution policy.');
$loop=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
$orchestrator=file_get_contents(__DIR__.'/../app/chat/orchestrator.php');
multimodel_assert(str_contains($orchestrator,'lac_agent_compile_protocol($available'),'Chat Orchestrator must compile the enabled-tool catalog before Round 0');
foreach(['tools','tool_choice','functions','function_call'] as $field)multimodel_assert(str_contains($orchestrator,"'$field'"),'Chat Orchestrator must strip provider-native tool fields: '.$field);
multimodel_assert(str_contains($orchestrator,'$context[\'dispatch_response\']=$dispatchResponse'),'The original Round 0 response must persist into Agent Runtime.');
multimodel_assert(str_contains($orchestrator,'PROVIDER_NATIVE_TOOL_OUTPUT_UNSUPPORTED'),'Provider-native output must be rejected before Agent Runtime.');
multimodel_assert(str_contains($orchestrator,"['tool_help_request','tool_call']"),'Only explicit model tool requests may initiate Agent Runtime.');
echo "PASS: unified multi-model protocol, persistent context, and provider-native rejection\n";
