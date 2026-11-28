<?php
require_once __DIR__.'/../app/agent/tool_registry.php';
require_once __DIR__.'/../app/agent/protocol.php';
require_once __DIR__.'/../app/agent/protocol_compiler.php';
require_once __DIR__.'/../app/agent/schema_validator.php';
$catalog=lac_tool_catalog();
if(empty($catalog['python_calculate']['when_not_to_use'])||!str_contains($catalog['python_calculate']['when_not_to_use'],'Python code'))throw new RuntimeException('python_calculate usage boundary is missing.');
$invalid=[];foreach($catalog as $name=>$tool)foreach(($tool['examples']??[]) as $index=>$arguments){$errors=lac_tool_validate_arguments($name,$arguments,$tool);if($errors)$invalid[]=['tool'=>$name,'example'=>$index,'errors'=>$errors];}
if($invalid)throw new RuntimeException('Tool examples failed schema validation: '.json_encode($invalid,JSON_UNESCAPED_UNICODE));
$graph=lac_tool_capability_graph($catalog);if(count($graph['nodes'])!==count($catalog))throw new RuntimeException('Capability graph node count mismatch.');
if(!in_array(['from'=>'python_generate_number','to'=>'python_statistics','type'=>'data_handoff'],$graph['edges'],true))throw new RuntimeException('Missing data-generation to statistics capability edge.');
$prompt=lac_agent_compile_protocol(array_values($catalog))['system_text'];
foreach(['does NOT run Python code','Provider-native tool_calls are ignored and never executed','enabled by the user','tool_help_request'] as $needle)if(!str_contains($prompt,$needle))throw new RuntimeException('Unified protocol guidance missing: '.$needle);
if(str_contains($prompt,'input_schema'))throw new RuntimeException('Preparing must not contain tool schemas.');
echo "PASS: capability catalog, schema examples, unified protocol and math-tool boundary\n";
