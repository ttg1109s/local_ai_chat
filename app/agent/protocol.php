<?php

/** Stable signature for idempotent tool execution. */
function lac_agent_tool_signature(string $tool,array $arguments): string {
 $normalize=function(mixed $value)use(&$normalize):mixed{
  if(!is_array($value))return $value;
  if(!array_is_list($value))ksort($value,SORT_STRING);
  foreach($value as $key=>$item)$value[$key]=$normalize($item);
  return $value;
 };
 $json=json_encode($normalize($arguments),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRESERVE_ZERO_FRACTION);
 return hash('sha256',$tool."\0".($json===false?'{}':$json));
}

/** Convert decoded JSON while preserving empty objects separately from empty arrays. */
function lac_agent_json_value_to_php(mixed $value): mixed {
 if($value instanceof stdClass){
  $out=[];
  foreach($value as $key=>$item)$out[$key]=lac_agent_json_value_to_php($item);
  return $out===[]?(object)[]:$out;
 }
 if(is_array($value))return array_map('lac_agent_json_value_to_php',$value);
 return $value;
}

/** Accept only a JSON object for tool arguments. */
if(!function_exists('lac_agent_json_object')){
 function lac_agent_json_object(mixed $value): ?array {
  if($value instanceof stdClass)$value=(array)$value;
  if(!is_array($value)||($value!==[]&&array_is_list($value)))return null;
  return $value;
 }
}

/** Return compact diagnostics for rejected runtime-generated calls. */
function lac_agent_protocol_repair_diagnostic(array $batch): string {
 $items=[];
 foreach($batch as $call){
  if(empty($call['invalid']))continue;
  $items[]=[
   'reason'=>(string)($call['reason']??'INVALID_PROTOCOL'),
   'tool'=>isset($call['tool'])?(string)$call['tool']:null,
   'detail'=>isset($call['detail'])?substr((string)$call['detail'],0,180):null,
  ];
  if(count($items)>=8)break;
 }
 $json=json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
 return substr($json===false?'[]':$json,0,2400);
}
