<?php
/** Completion is a Runtime decision based on committed node outputs, not model assertions. */
function lac_agent_completion_validate(array $problem,array $nodes,array $results,array $externalPending=[]): array {
 $issues=[];$criteria=$problem['success_criteria']??[];$covered=[];
 foreach($nodes as $node){
  $id=(string)($node['id']??'');$result=$results[$id]??null;
  if(($node['status']??'pending')!=='completed'||!is_array($result)||empty($result['ok'])){
   $issues[]='Node has no confirmed successful result: '.$id;continue;
  }
  foreach($node['success_criteria']??[] as $index)if(is_int($index)||ctype_digit((string)$index))$covered[(int)$index]=true;
 }
 foreach($nodes as $node)if(in_array($node['status']??'pending',['pending','running','blocked'],true))$issues[]='Node is not complete: '.(string)($node['id']??'unknown');
 $hasMapping=false;foreach($nodes as $node)if(!empty($node['success_criteria'])){$hasMapping=true;break;}
 if(!$hasMapping&&$criteria)$issues[]='No node declares success_criteria for evidence mapping.';
 if($hasMapping)foreach($criteria as $i=>$criterion)if(empty($covered[$i]))$issues[]='No successful node proves criterion #'.($i+1).': '.(string)$criterion;
 if(!$criteria)$issues[]='Problem contract has no completion criteria.';
 foreach($externalPending as $item)$issues[]='External action has not been approved: '.(string)$item;
 return ['complete'=>$issues===[],'issues'=>array_values(array_unique($issues))];
}

/** Detect explicit first-person tool-use claims that lack a matching committed receipt. */
function lac_agent_unverified_tool_claims(string $content,array $calls): array {
 $verified=[];
 foreach($calls as $call){
  if(empty($call['ok'])||($call['execution_receipt']['state']??'')!=='COMMITTED')continue;
  $verified[]=(string)($call['tool']??'');
 }
 $hasTool=static function(array $needles)use($verified):bool{
  foreach($verified as $tool)foreach($needles as $needle){
   if($tool===$needle)return true;
   if($needle==='compute'&&in_array($tool,['python_calculate','python_statistics'],true))return true;
   if($needle==='calculate'&&$tool==='python_calculate')return true;
   if($needle==='search'&&in_array($tool,['web_search','browser_search','search_web'],true))return true;
   if($needle==='send'&&in_array($tool,['email_send','message_send'],true))return true;
   if($needle==='email'&&$tool==='email_send')return true;
   if($needle==='book'&&$tool==='restaurant_booking')return true;
   if($needle==='upload'&&$tool==='file_upload')return true;
   if($needle==='download'&&$tool==='file_download')return true;
   if($needle==='delete'&&$tool==='file_delete')return true;
   if($needle==='update'&&$tool==='file_update')return true;
  }
  return false;
 };
 $claims=[];
 $rules=[
  ['code'=>'WEB_SEARCH_CLAIM','pattern'=>'/\b(?:i|we)\s+(?:(?:have|just|already)\s+)?(?:searched|browsed|looked up|checked online|retrieved from the web|found online)\b|\b(?:the )?(?:web|search) results (?:show|indicate|confirm)\b|\b(?:found|retrieved|collected)\s+\d+\s+(?:web )?(?:sources|results|pages)\b|\b(?:web )?search (?:is )?(?:complete|completed|done)\b|(?:t\x{f4}i|ch\x{fa}ng t\x{f4}i)\s+(?:\x{111}\x{e3}\s+)?(?:t\x{ec}m ki\x{1ebf}m|tra c\x{1ee9}u|duy\x{1ec7}t web|t\x{ec}m tr\x{ea}n m\x{1ea1}ng)|(?:k\x{1ebf}t qu\x{1ea3} t\x{ec}m ki\x{1ebf}m|ngu\x{1ed3}n t\x{f4}i t\x{ec}m \x{111}\x{1b0}\x{1ee3}c)/iu','tools'=>['search']],
  ['code'=>'COMPUTATION_CLAIM','pattern'=>'/\b(?:i|we)\s+(?:(?:have|just|already)\s+)?(?:ran|executed|calculated|computed)\b|(?:t\x{f4}i|ch\x{fa}ng t\x{f4}i)\s+(?:\x{111}\x{e3}\s+)?(?:ch\x{1ea1}y|th\x{1ef1}c thi|t\x{ed}nh to\x{e1}n|t\x{ed}nh)\b/iu','tools'=>['python_calculate','python_statistics','compute','calculate']],
  ['code'=>'CHART_TOOL_CLAIM','pattern'=>'/\b(?:i|we)\s+(?:(?:have|just|already)\s+)?plotted\s+(?:the )?(?:chart|plot)\b|(?:t\x{f4}i|ch\x{fa}ng t\x{f4}i)\s+(?:\x{111}\x{e3}\s+)?v\x{1ebd}\s+(?:bi\x{1ec3}u \x{111}\x{1ed3}|\x{111}\x{1ed3} th\x{1ecb})/iu','tools'=>['python_plot']],
  ['code'=>'DATA_GENERATION_TOOL_CLAIM','pattern'=>'/\b(?:i|we)\s+(?:(?:have|just|already)\s+)?generated\s+(?:the )?(?:dataset|data)\b|(?:t\x{f4}i|ch\x{fa}ng t\x{f4}i)\s+(?:\x{111}\x{e3}\s+)?t\x{1ea1}o\s+(?:b\x{1ed9} )?d\x{1eef} li\x{1ec7}u/iu','tools'=>['python_generate_data']],
  ['code'=>'EXTERNAL_ACTION_CLAIM','pattern'=>'/\b(?:i|we)\s+(?:(?:have|just|already)\s+)?(?:sent|forwarded|booked|uploaded|downloaded|deleted|updated)\b|\b(?:email|message|reservation|booking|file)\s+(?:has been|was)\s+(?:sent|booked|uploaded|deleted|updated|saved)\b|\b(?:sent|booked|uploaded|deleted|updated)\s+successfully\b|(?:t\x{f4}i|ch\x{fa}ng t\x{f4}i)\s+(?:\x{111}\x{e3}\s+)?(?:g\x{1eed}i|\x{111}\x{1eb7}t|t\x{1ea3}i l\x{ea}n|t\x{1ea3}i xu\x{1ed1}ng|x\x{f3}a|c\x{1ead}p nh\x{1ead}t)\b|(?:email|tin nh\x{1eaf}n|t\x{1ec7}p|file)\s+(?:\x{111}\x{e3} \x{111}\x{1b0}\x{1ee3}c|\x{111}\x{e3})\s+(?:g\x{1eed}i|\x{111}\x{1eb7}t|t\x{1ea3}i l\x{ea}n|x\x{f3}a|c\x{1ead}p nh\x{1ead}t|l\x{1b0}u)/iu','tools'=>['send','email','book','upload','download','delete','update']],
  ['code'=>'GENERIC_TOOL_USE_CLAIM','pattern'=>'/\b(?:i|we)\s+(?:used|called|invoked)\s+(?:the )?(?:tool|browser|search)\b/i','tools'=>[]],
 ];
 foreach($rules as $rule){
  if(!preg_match($rule['pattern'],$content))continue;
  $supported=$rule['tools']===[]?($verified!==[]):$hasTool($rule['tools']);
  if(!$supported)$claims[]=$rule['code'];
 }
 return array_values(array_unique($claims));
}


/**
 * Validate a model final against an explicit, tool-agnostic JSON data contract.
 * The contract is supplied by the task/planner; this function never assumes tool
 * names, field names, array shapes, or that output must copy source data verbatim.
 * Supported JSON Schema subset: type, required, properties, items, enum,
 * minItems/maxItems, minLength/maxLength, minimum/maximum, and additionalProperties.
 */

/** JSON Schema string length counts Unicode code points, not UTF-8 bytes. */
function lac_agent_schema_string_length(string $value): int {
 $matched=preg_match_all('/./us',$value,$matches);
 return $matched===false?strlen($value):$matched;
}

function lac_agent_output_contract_issues(string $content,array $contract): array {
 if(($contract['type']??'')!=='json_schema')return [];
 $schema=$contract['schema']??null;
 if(!is_array($schema))return ['OUTPUT_CONTRACT_INVALID: JSON schema is missing.'];
 $candidates=[];
 if(preg_match_all('/```(?:json)?\s*\n?(.*?)```/is',$content,$matches))foreach($matches[1] as $block)$candidates[]=trim($block);
 $trimmed=trim($content);if($trimmed!=='')$candidates[]=$trimmed;
 $value=null;$decoded=false;
 foreach($candidates as $candidate){$parsed=json_decode($candidate);if(json_last_error()===JSON_ERROR_NONE){$value=$parsed;$decoded=true;break;}}
 if(!$decoded)return ['OUTPUT_JSON_INVALID: final response does not contain valid JSON matching the declared output contract.'];
 $issues=[];$maxIssues=50;
 $check=null;
 $check=static function(mixed $v,array $rule,string $path)use(&$check,&$issues,$maxIssues):void{
  if(count($issues)>=$maxIssues)return;
  $type=$rule['type']??null;
  $actual=$v instanceof stdClass?'object':(is_array($v)?'array':(is_string($v)?'string':(is_int($v)||is_float($v)?'number':(is_bool($v)?'boolean':($v===null?'null':'unknown')))));
  if(is_string($type)&&$type!==$actual&& !($type==='integer'&&is_int($v)) && !($type==='number'&&$actual==='number')){$issues[]='OUTPUT_TYPE_MISMATCH at '.$path.': expected '.$type.', got '.$actual.'.';return;}
  if(isset($rule['enum'])&&is_array($rule['enum'])&&!in_array($v,$rule['enum'],true))$issues[]='OUTPUT_ENUM_MISMATCH at '.$path.'.';
  if(is_string($v)){
   if(isset($rule['minLength'])&&lac_agent_schema_string_length($v)<(int)$rule['minLength'])$issues[]='OUTPUT_STRING_TOO_SHORT at '.$path.'.';
   if(isset($rule['maxLength'])&&lac_agent_schema_string_length($v)>(int)$rule['maxLength'])$issues[]='OUTPUT_STRING_TOO_LONG at '.$path.'.';
  }
  if(is_int($v)||is_float($v)){
   if(isset($rule['minimum'])&&$v<$rule['minimum'])$issues[]='OUTPUT_NUMBER_BELOW_MINIMUM at '.$path.'.';
   if(isset($rule['maximum'])&&$v>$rule['maximum'])$issues[]='OUTPUT_NUMBER_ABOVE_MAXIMUM at '.$path.'.';
  }
  if(is_array($v)){
   if(isset($rule['minItems'])&&count($v)<(int)$rule['minItems'])$issues[]='OUTPUT_ARRAY_TOO_SHORT at '.$path.'.';
   if(isset($rule['maxItems'])&&count($v)>(int)$rule['maxItems'])$issues[]='OUTPUT_ARRAY_TOO_LONG at '.$path.'.';
   if(is_array($rule['items']??null))foreach($v as $i=>$child)$check($child,$rule['items'],$path.'['.$i.']');
  }elseif($v instanceof stdClass){
   $members=get_object_vars($v);
   foreach(($rule['required']??[]) as $key)if(!array_key_exists((string)$key,$members))$issues[]='OUTPUT_REQUIRED_FIELD_MISSING at '.$path.'.'.(string)$key.'.';
   $properties=is_array($rule['properties']??null)?$rule['properties']:[];
   foreach($properties as $key=>$childRule){if(array_key_exists($key,$members)&&is_array($childRule))$check($members[$key],$childRule,$path.'.'.$key);}
   if(($rule['additionalProperties']??true)===false)foreach($members as $key=>$child)if(!array_key_exists((string)$key,$properties))$issues[]='OUTPUT_ADDITIONAL_FIELD at '.$path.'.'.(string)$key.'.';
  }
 };
 $check($value,$schema,'$');
 return array_slice(array_values(array_unique($issues)),0,$maxIssues);
}
