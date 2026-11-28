<?php
require_once __DIR__.'/../app/agent/completion_validator.php';
$contract=['type'=>'json_schema','schema'=>['type'=>'object','required'=>['name'],'properties'=>['name'=>['type'=>'string','minLength'=>2,'maxLength'=>2]],'additionalProperties'=>false]];
if(lac_agent_output_contract_issues('{"name":"é猫"}',$contract)!==[])throw new RuntimeException('Unicode code-point length should satisfy minLength/maxLength=2.');
if(count(lac_agent_output_contract_issues('{"name":"é"}',$contract))===0)throw new RuntimeException('Unicode minLength must reject one code point.');
if(count(lac_agent_output_contract_issues('{"name":"é猫🙂"}',$contract))===0)throw new RuntimeException('Unicode maxLength must reject three code points.');
echo "PASS: JSON Schema Unicode string length semantics\n";
