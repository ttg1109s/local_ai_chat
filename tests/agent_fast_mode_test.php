<?php
require_once __DIR__.'/../app/agent/protocol.php';
require_once __DIR__.'/../app/agent/protocol_compiler.php';
require_once __DIR__.'/../app/agent/tool_registry.php';
$instructions=lac_agent_compile_protocol(array_values(lac_tool_catalog()))['system_text'];
foreach(['UNIFIED AGENT PROTOCOL','enabled by the user','tool_help_request','tool_call','tool_result','final'] as $needle){if(!str_contains($instructions,$needle))throw new RuntimeException('Unified protocol is missing '.$needle);}
if(str_contains($instructions,'input_schema')||str_contains($instructions,'"schema"'))throw new RuntimeException('Preparing catalog must not include tool schemas.');
if(str_contains($instructions,'FAST MODE')||str_contains($instructions,'THINKING MODE')||str_contains($instructions,'kind=plan'))throw new RuntimeException('Mode or DAG language remains in the active protocol.');
echo 'PASS: unified compact preparing catalog and on-demand tool help'.PHP_EOL;
