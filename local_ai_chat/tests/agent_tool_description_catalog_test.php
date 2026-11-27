<?php
require_once __DIR__.'/../app/agent/tool_registry.php';
require_once __DIR__.'/../app/agent/protocol_compiler.php';
require_once __DIR__.'/../app/agent/loop/protocol.php';
$catalog=lac_tool_catalog();
if(count($catalog)!==11)throw new RuntimeException('Expected 11 remaining registered tools (including two system tools).');
foreach($catalog as $tool){
 $compiled=lac_agent_compile_protocol([$tool]);
 $entry=$compiled['contract']['tools'][0]??[];
 if(array_keys($entry)!==['name','label','description'])throw new RuntimeException('Catalog must contain only name, label, and short description for '.$tool['name'].'.');
 if(strlen($entry['description'])>180)throw new RuntimeException('Catalog description is not concise for '.$tool['name'].'.');
 foreach(['when_to_use','when_not_to_use','examples','input_schema','fields','validation_rules','can_feed'] as $field){
  if(array_key_exists($field,$entry))throw new RuntimeException('Help-only field leaked into catalog: '.$field);
 }
}
foreach(['python_analyze_json','python_machine_learning','get_llama_info','get_infrastructure_status','document_extract'] as $removed){if(isset($catalog[$removed]))throw new RuntimeException('Retired tool remains registered: '.$removed);}
foreach(['data_access','verify_result'] as $system){if(empty($catalog[$system]['internal'])||empty($catalog[$system]['system_tool']))throw new RuntimeException('System tool must be internal and Runtime-managed: '.$system);}
$search=$catalog['search_web'];
$helpDescription=$search['description'];
if(strlen($helpDescription)<500)throw new RuntimeException('Full help description was lost from the registry.');
$compilerSource=file_get_contents(__DIR__.'/../app/agent/protocol_compiler.php');
foreach(['tool_reconsider','Help is advisory, not an instruction to execute','continue without tools'] as $needle){
 if(!str_contains($compilerSource,$needle))throw new RuntimeException('Protocol compiler is missing reconsideration rule: '.$needle);
}
$runner=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
foreach(["'when_not_to_use'=>","'can_feed'=>","'examples'=>","tool_reconsider_result","reconsidering_tool_selection","system_tool_requirement_enforced","MANDATORY_SYSTEM_TOOL_NOT_USED"] as $needle){
 if(!str_contains($runner,$needle))throw new RuntimeException('Runtime help/reconsideration flow is missing '.$needle);
}
$decoded=lac_agent_loop_decode_protocol('{"type":"tool_reconsider","tool":"search_web","reason":"The task is about a configured website."}');
if(($decoded['type']??'')!=='tool_reconsider')throw new RuntimeException('The protocol decoder must recognize tool_reconsider.');
$valid=lac_agent_loop_validate_message(['type'=>'tool_reconsider','tool'=>'search_web','reason'=>'The task is about a local configured website, not public web search.']);
if(empty($valid['valid']))throw new RuntimeException('A valid tool_reconsider message should validate.');
$invalid=lac_agent_loop_validate_message(['type'=>'tool_reconsider','tool'=>'search_web','reason'=>'']);
if(($invalid['error']??'')!=='TOOL_RECONSIDER_REASON_REQUIRED')throw new RuntimeException('tool_reconsider must require a reason.');
echo "PASS: concise first-round catalog, detailed on-demand help, and non-forced tool reconsideration
";
