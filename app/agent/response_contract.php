<?php
require_once __DIR__.'/schema_validator.php';
require_once __DIR__.'/tool_registry.php';
require_once __DIR__.'/execution_policy.php';
/** Legacy internal envelope used only after the staged unified message is normalized. */
function lac_agent_response_schema(array $tools): array {
 $names=[];foreach($tools as $tool)if(!empty($tool['name']))$names[]=(string)$tool['name'];
 return ['type'=>'object','additionalProperties'=>false,'required'=>['kind','content','tool','arguments'],'properties'=>[
  'kind'=>['type'=>'string','enum'=>$names?['final','tool_call']:['final']],
  'content'=>['type'=>'string'],'tool'=>['type'=>'string','enum'=>array_merge([''],$names)],
  'arguments'=>['type'=>'object']
 ]];
}
if(!function_exists('lac_agent_json_object')){
 function lac_agent_json_object(mixed $value): ?array {
  if($value instanceof stdClass)return (array)$value;
  if(is_array($value)&&!array_is_list($value))return $value;
  return null;
 }
}
function lac_agent_parse_response_contract(string $content,array $tools,array $permissions): array {
 $raw=trim($content);
 if($raw===''||$raw[0]!=='{')return ['recognized'=>true,'valid'=>false,'error'=>'NOT_STRUCTURED_JSON'];
 try{$decoded=json_decode($raw,false,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){return ['recognized'=>true,'valid'=>false,'error'=>'INVALID_JSON'];}
 if(!$decoded instanceof stdClass)return ['recognized'=>true,'valid'=>false,'error'=>'ROOT_NOT_OBJECT'];
 $data=(array)$decoded;
 if(!array_intersect(['kind','content','tool','arguments'],array_keys($data)))return ['recognized'=>true,'valid'=>false,'error'=>'NOT_RESPONSE_ENVELOPE'];
 $extra=array_diff(array_keys($data),['kind','content','tool','arguments']);
 if($extra)return ['recognized'=>true,'valid'=>false,'error'=>'UNKNOWN_ENVELOPE_FIELDS','fields'=>array_values($extra)];
 foreach(['kind','content','tool','arguments'] as $key)if(!property_exists($decoded,$key))return ['recognized'=>true,'valid'=>false,'error'=>'MISSING_ENVELOPE_FIELD','field'=>$key];
 $kind=$decoded->kind;$contentValue=$decoded->content;$tool=$decoded->tool;$arguments=lac_agent_json_object($decoded->arguments);
 if(!is_string($kind)||!is_string($contentValue)||!is_string($tool)||$arguments===null)return ['recognized'=>true,'valid'=>false,'error'=>'INVALID_ENVELOPE_TYPES'];
 if($kind==='final'){
  if(trim($contentValue)===''||$tool!==''||$arguments!==[])return ['recognized'=>true,'valid'=>false,'error'=>'INVALID_FINAL_CONDITION'];
  return ['recognized'=>true,'valid'=>true,'kind'=>'final','content'=>$contentValue];
 }
 if($kind!=='tool_call')return ['recognized'=>true,'valid'=>false,'error'=>'INVALID_KIND'];
 if($contentValue!=='')return ['recognized'=>true,'valid'=>false,'error'=>'NON_FINAL_CONTENT_MUST_BE_EMPTY'];
 $allowed=array_values(array_intersect(array_map(static fn($t)=>(string)($t['name']??''),$tools),$permissions));
 if(!in_array($tool,$allowed,true))return ['recognized'=>true,'valid'=>false,'error'=>'TOOL_NOT_ALLOWED','stage'=>'permission_validation','details'=>[['code'=>'TOOL_NOT_ALLOWED','path'=>'tool','message'=>'This tool is not enabled for the current run.','retryable'=>false]]];
 $catalog=lac_tool_catalog();if(!isset($catalog[$tool]))return ['recognized'=>true,'valid'=>false,'error'=>'UNKNOWN_TOOL','stage'=>'protocol_validation','details'=>[['code'=>'UNKNOWN_TOOL','path'=>'tool','message'=>'The requested tool is not registered.','retryable'=>false]]];
 $errors=lac_tool_validate_arguments($tool,$arguments,$catalog[$tool]);
 if($errors)return ['recognized'=>true,'valid'=>false,'error'=>'INVALID_ARGUMENTS','details'=>lac_schema_errors_structured($errors)];
 return ['recognized'=>true,'valid'=>true,'kind'=>'tool_call','tool'=>$tool,'arguments'=>$arguments];
}
function lac_agent_apply_response_schema(array $payload,array $tools,bool $enabled): array {
 if(!$enabled)return $payload;
 $payload['response_format']=['type'=>'json_schema','schema'=>lac_agent_response_schema($tools)];
 return $payload;
}
function lac_agent_should_constrain_response(string $endpoint,array $context=[]): bool {
 $override=getenv('LAC_AGENT_STRUCTURED_OUTPUT');
 if($override!==false&&$override!=='')return filter_var($override,FILTER_VALIDATE_BOOLEAN);
 if(array_key_exists('structured_output_enabled',$context))return (bool)$context['structured_output_enabled'];
 return false;
}
