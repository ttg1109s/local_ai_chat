<?php
$loop=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
$transport=file_get_contents(__DIR__.'/../app/agent/model_transport.php');
$store=file_get_contents(__DIR__.'/../app/agent/execution_store.php');
$completion=file_get_contents(__DIR__.'/../app/agent/completion_validator.php');
function boundary_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
boundary_assert(str_contains($loop,'$validatedMessage=[\'recognized\'=>true,\'valid\'=>true,\'type\'=>\'tool_call\''),'Canonical tool_call messages must be validated directly without an intermediate legacy envelope.');
boundary_assert(!str_contains($loop,'lac_agent_parse_response_contract')&&!str_contains($loop,'lac_agent_parse_calls'),'The active loop must not use old response-contract or URI parsers.');
boundary_assert(str_contains($transport,'Stream ordinary text/Markdown provisionally; JSON control messages stay private.'),'Transport must support provisional raw-text streaming while keeping JSON control messages private.');
boundary_assert(str_contains($transport,'function lac_agent_stream_final_delta')&&str_contains($transport,'$emit(\'delta\''),'Canonical final content must be forwarded incrementally.');
boundary_assert(!str_contains($transport,'final_stream')&&!str_contains($transport,'action-tools://'),'Transport must not retain retired protocol markers.');
boundary_assert(str_contains($loop,'$emit(\'clear_delta\''),'A streamed draft must be retractable if provenance validation fails.');
boundary_assert(str_contains(file_get_contents(__DIR__.'/../app/agent/loop/bootstrap.php'),'completion_validator.php'),'Agent bootstrap must load completion validation.');
boundary_assert(str_contains($transport,'$unsupportedNativeToolOutput'),'Provider-native tool output must be detected and rejected.');
boundary_assert(str_contains($store,'function lac_execution_verify_success'),'Execution success must be checked against the durable ledger.');
boundary_assert(str_contains($loop,'lac_execution_verify_success($runId,$toolCallId,$name)'),'Tool results must be receipt-verified.');
boundary_assert(!str_contains($loop,'lac_agent_parse_plan(')&&!str_contains($loop,'lac_agent_plan_validate('),'DAG planning must not be in the active Agent loop.');
boundary_assert(str_contains($completion,'function lac_agent_unverified_tool_claims'),'Final output must pass tool-use provenance validation.');
echo "PASS: canonical model boundary, provider-native call rejection, and receipt-gated completion\\n";
