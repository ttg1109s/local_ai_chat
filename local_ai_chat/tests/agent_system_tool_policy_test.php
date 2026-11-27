<?php
require_once __DIR__.'/../app/agent/tool_registry.php';
require_once __DIR__.'/../app/agent/protocol_compiler.php';
require_once __DIR__.'/../app/agent/loop/protocol.php';
$catalog=lac_tool_catalog();
$runtimeIds=['search_web','data_access','verify_result'];
$map=lac_agent_loop_enabled_tool_map($runtimeIds,$catalog);
foreach(['data_access','verify_result'] as $name)if(!isset($map[$name]))throw new RuntimeException('Runtime must expose system tool '.$name.'.');
foreach(['data_access','verify_result'] as $name){
 if(empty($catalog[$name]['internal'])||empty($catalog[$name]['system_tool']))throw new RuntimeException($name.' must be marked as a Runtime-managed system tool.');
 if(in_array($name,array_keys(array_filter($catalog,static fn($t)=>empty($t['internal']))),true))throw new RuntimeException($name.' must not be user-selectable.');
}
$compiled=lac_agent_compile_protocol(array_values($map));
foreach(['data_access','verify_result'] as $name){
 if(!in_array($name,array_column($compiled['contract']['tools'],'name'),true))throw new RuntimeException('System tool missing from model catalog: '.$name);
}
foreach(['mandatory whenever a committed Data Pack','mandatory system tool','Runtime blocks finalization'] as $needle){
 if(!str_contains($compiled['system_text'],$needle))throw new RuntimeException('System-tool policy missing: '.$needle);
}
$runner=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
foreach(["'data_access','verify_result'","MANDATORY_SYSTEM_TOOL_NOT_USED","system_tool_requirement_enforced",'$requiredDataPackRefs'] as $needle){
 if(!str_contains($runner,$needle))throw new RuntimeException('Runtime system-tool enforcement missing: '.$needle);
}
echo "PASS: Runtime-managed system tools are injected and enforced when applicable\n";
