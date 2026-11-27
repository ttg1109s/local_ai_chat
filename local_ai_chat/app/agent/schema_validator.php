<?php
/** Small JSON-Schema subset validator; deliberately dependency-free for local PHP installs. */
function lac_schema_strlen(string $value): int {return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);}
function lac_schema_type_ok(mixed $v, mixed $type,bool $rootObject=false): bool {
 $types=is_array($type)?$type:[$type];
 foreach($types as $t){$ok=match($t){'object'=>(is_array($v)&&(!array_is_list($v)||($rootObject&&$v===[])))||$v instanceof stdClass,'array'=>is_array($v)&&array_is_list($v),'string'=>is_string($v),'number'=>(is_int($v)||is_float($v))&&!is_bool($v)&&is_finite((float)$v),'integer'=>is_int($v),'boolean'=>is_bool($v),'null'=>$v===null,default=>false};if($ok)return true;}
 return false;
}
function lac_schema_object_to_array(mixed $value): ?array {
 if($value instanceof stdClass)return (array)$value;
 if(is_array($value)&&($value===[]||!array_is_list($value)))return $value;
 return null;
}
function lac_schema_validate_value(mixed $value,array $schema,string $path='arguments',int $depth=0,?int $maxDepth=null): array {
 if($maxDepth===null)$maxDepth=max(1,min(16,(int)($schema['x-lac-maxDepth']??5)));
 $maxProperties=max(1,min(1000,(int)($schema['maxProperties']??$schema['x-lac-maxProperties']??50)));
 $maxItems=isset($schema['x-lac-maxItems'])?max(1,min(1000000,(int)$schema['x-lac-maxItems'])):null;
 if($depth>$maxDepth)return ["$path exceeds the maximum nesting depth ($maxDepth)."];
 $type=$schema['type']??null;
 if($type!==null&&!lac_schema_type_ok($value,$type,$depth===0&&$path==='arguments'))return ["$path has an invalid type; expected ".(is_array($type)?implode('|',$type):$type).'.'];
 $errors=[];
 if(is_string($value)){
  if(isset($schema['minLength'])&&lac_schema_strlen($value)<$schema['minLength'])$errors[]="$path is shorter than the minimum length.";
  if(isset($schema['maxLength'])&&lac_schema_strlen($value)>$schema['maxLength'])$errors[]="$path exceeds the maximum length.";
  if(isset($schema['enum'])&&!in_array($value,$schema['enum'],true))$errors[]="$path is not an allowed value.";
  if(isset($schema['pattern'])&&@preg_match('~'.str_replace('~','\\~',(string)$schema['pattern']).'~u',$value)!==1)$errors[]="$path does not match the required format.";
 }
 if(is_int($value)||is_float($value)){
  if(isset($schema['minimum'])&&$value<$schema['minimum'])$errors[]="$path is below the allowed minimum.";
  if(isset($schema['maximum'])&&$value>$schema['maximum'])$errors[]="$path exceeds the allowed maximum.";
  if(isset($schema['exclusiveMinimum'])&&$value<=$schema['exclusiveMinimum'])$errors[]="$path must be greater than the limit.";
  if(isset($schema['exclusiveMaximum'])&&$value>=$schema['exclusiveMaximum'])$errors[]="$path must be less than the limit.";
 }
 if(is_array($value)&&array_is_list($value)){
  $arrayLimit=isset($schema['maxItems'])?(int)$schema['maxItems']:(isset($schema['x-lac-maxItems'])?(int)$schema['x-lac-maxItems']:100);
  if(count($value)>$arrayLimit)$errors[]="$path exceeds the default maximum item count ($arrayLimit).";
  if(isset($schema['minItems'])&&count($value)<$schema['minItems'])$errors[]="$path does not contain enough items.";
  if($maxItems!==null&&count($value)>$maxItems)$errors[]="$path exceeds the default x-lac-maxItems limit.";
  if(isset($schema['maxItems'])&&count($value)>$schema['maxItems'])$errors[]="$path exceeds the maximum item count.";
  if(isset($schema['items'])&&is_array($schema['items']))foreach($value as $i=>$item){$e=lac_schema_validate_value($item,$schema['items'],$path.'['.$i.']',$depth+1,$maxDepth);$errors=array_merge($errors,$e);if(count($errors)>=10)break;}elseif(!isset($schema['items']))foreach($value as $i=>$item){$e=lac_schema_validate_untyped($item,$path.'['.$i.']',$depth+1,$maxDepth);$errors=array_merge($errors,$e);if(count($errors)>=10)break;}
 }
 $object=lac_schema_object_to_array($value);
 if($object!==null && (!is_array($value)||!array_is_list($value)||($depth===0&&$path==='arguments'&&$value===[]))){
  if(count($object)>$maxProperties)$errors[]="$path exceeds the default maximum property count ($maxProperties).";
  if(isset($schema['maxProperties'])&&count($object)>$schema['maxProperties'])$errors[]="$path exceeds the maximum property count.";
  $props=$schema['properties']??[];
  foreach($schema['required']??[] as $required)if(!array_key_exists($required,$object))$errors[]="$path is missing required field: $required.";
  foreach($object as $key=>$item){if(isset($props[$key])){$e=lac_schema_validate_value($item,$props[$key],$path.'.'.$key,$depth+1,$maxDepth);$errors=array_merge($errors,$e);}elseif(($schema['additionalProperties']??true)===false)$errors[]="$path contains an unsupported field: $key.";elseif(is_array($schema['additionalProperties']??null)){$e=lac_schema_validate_value($item,$schema['additionalProperties'],$path.'.'.$key,$depth+1,$maxDepth);$errors=array_merge($errors,$e);}else{$e=lac_schema_validate_untyped($item,$path.'.'.$key,$depth+1,$maxDepth);$errors=array_merge($errors,$e);}if(count($errors)>=10)break;}
 }
 return $errors;
}
/** Enforce global resource limits even where a tool intentionally leaves nested JSON open-ended. */
function lac_schema_validate_untyped(mixed $value,string $path,int $depth,int $maxDepth): array {
 if($depth>$maxDepth)return ["$path exceeds the maximum nesting depth ($maxDepth)."];
 if($value instanceof stdClass){$items=(array)$value;if(count($items)>50)return ["$path exceeds 50 properties."];$errors=[];foreach($items as $key=>$child){$errors=array_merge($errors,lac_schema_validate_untyped($child,$path.'.'.$key,$depth+1,$maxDepth));if(count($errors)>=10)break;}return $errors;}
 if(is_array($value)){if(array_is_list($value)){if(count($value)>100)return ["$path exceeds the default limit of 100 items."];$errors=[];foreach($value as $i=>$child){$errors=array_merge($errors,lac_schema_validate_untyped($child,$path.'['.$i.']',$depth+1,$maxDepth));if(count($errors)>=10)break;}return $errors;}if(count($value)>50)return ["$path exceeds 50 properties."];$errors=[];foreach($value as $key=>$child){$errors=array_merge($errors,lac_schema_validate_untyped($child,$path.'.'.$key,$depth+1,$maxDepth));if(count($errors)>=10)break;}return $errors;}
 return [];
}
/** Convert validator diagnostics into stable path/code records for repair prompts and logs. */
function lac_schema_errors_structured(array $errors): array {
 $out=[];foreach($errors as $message){$path='arguments';if(preg_match('/^(arguments(?:\.[A-Za-z0-9_-]+|\[[0-9]+\])*)/',$message,$m))$path=$m[1];$code=str_contains($message,'missing required field')?'REQUIRED':(str_contains($message,'invalid type')?'INVALID_TYPE':(str_contains($message,'nesting depth')?'MAX_DEPTH':(str_contains($message,'item')?'MAX_ITEMS':(str_contains($message,'property')?'MAX_PROPERTIES':'SCHEMA_CONSTRAINT'))));$out[]=['path'=>$path,'code'=>$code,'message'=>$message];if(count($out)>=20)break;}return $out;
}
/** Apply only explicit JSON-schema defaults; never invent values for fields without defaults. */
function lac_tool_apply_argument_defaults(array $args,array $meta): array {
 $schema=$meta['schema']??[];$properties=$schema['properties']??[];
 foreach($properties as $key=>$definition){if(!array_key_exists($key,$args)&&is_array($definition)&&array_key_exists('default',$definition))$args[$key]=$definition['default'];}
 return $args;
}
function lac_tool_validate_arguments(string $name,array $args,array $meta): array {
 $schema=$meta['schema']??['type'=>'object','properties'=>[]];
 $errors=lac_schema_validate_value($args,$schema);
 if($name==='manage_websites'&&($args['action']??'')==='read'&&(!isset($args['site'])||!is_string($args['site'])||trim($args['site'])===''))$errors[]='arguments.site is required and must be a non-empty string when action=read.';
 if($name==='python_generate_data'&&in_array(($args['distribution']??''),['integer','uniform'],true)&&isset($args['min'],$args['max'])&&$args['min']>=$args['max'])$errors[]='arguments.min must be less than arguments.max for integer or uniform distributions.';
 if($name==='python_dataframe'){
  $operation=(string)($args['operation']??'profile');
  if(in_array($operation,['filter','groupby'],true)&&(!isset($args['column'])||!is_string($args['column'])||trim($args['column'])===''))$errors[]='arguments.column is required for filter and groupby operations.';
  if($operation==='groupby'&&($args['aggregation']??'count')!=='count'&&(!isset($args['value_column'])||!is_string($args['value_column'])||trim($args['value_column'])===''))$errors[]='arguments.value_column is required for groupby aggregations other than count.';
  if($operation==='filter'&&!array_key_exists('value',$args))$errors[]='arguments.value is required for filter operations.';
 } if($name==='verify_result'){
  $kind=(string)($args['kind']??'');
  if($kind==='arithmetic'){
   if(!isset($args['expression'])||!is_string($args['expression'])||trim($args['expression'])==='')$errors[]='arguments.expression is required for arithmetic verification.';
   if(!array_key_exists('actual',$args)||(!is_int($args['actual'])&&!is_float($args['actual'])))$errors[]='arguments.actual must be a number for arithmetic verification.';
  }elseif($kind==='record_count'){
   if(!isset($args['records'])||!is_array($args['records'])||!array_is_list($args['records']))$errors[]='arguments.records must be an array for record_count verification.';
   if(!isset($args['reported_count'])||!is_int($args['reported_count']))$errors[]='arguments.reported_count must be an integer for record_count verification.';
  }
 }
 if($name==='python_statistics'){
  $method=(string)($args['method']??'');
  if(!in_array($method,['anova','one_way_anova'],true)&&(!isset($args['values'])||!is_array($args['values'])||!$args['values']))$errors[]='arguments.values is required and must be a non-empty array for this method.';
  if(in_array($method,['anova','one_way_anova'],true)&&empty($args['groups']))$errors[]='arguments.groups is required for ANOVA.';
  if(in_array($method,['pearson','spearman','kendall','correlation','linear_regression','two_sample_t_test','paired_t_test','mann_whitney','wilcoxon'],true)&&empty($args['y']))$errors[]='arguments.y is required for this method.';
  if(in_array($method,['chi_square','chi_square_goodness_of_fit'],true)&&empty($args['expected']))$errors[]='arguments.expected is required for Chi-square goodness-of-fit.';
  if(isset($args['values'])&&is_array($args['values'])&&count($args['values'])>100000)$errors[]='arguments.values exceeds 100,000 items.';
 }
 if($name==='python_plot'){
  $chart=(string)($args['chart_type']??'line');
  $hasSeries=array_key_exists('series',$args);
  if($hasSeries){
   if(!in_array($chart,['line','bar'],true))$errors[]='arguments.series supports only chart_type=line or bar.';
   if(array_key_exists('y',$args))$errors[]='Do not provide arguments.series and arguments.y at the same time.';
   foreach($args['series']??[] as $i=>$seriesValue){
    // Nested JSON objects remain stdClass after json_decode(..., false).
    // Normalize them exactly as the schema validator does before custom checks.
    $series=lac_schema_object_to_array($seriesValue);
    if($series===null||!isset($series['values'])||!is_array($series['values'])||!array_is_list($series['values'])||count($series['values'])<1)$errors[]="arguments.series[$i].values must be a non-empty numeric array.";
    elseif(count($series['values'])>10000)$errors[]="arguments.series[$i].values exceeds 10,000 items.";
    elseif(isset($series['x'])&&is_array($series['x'])&&count($series['x'])!==count($series['values']))$errors[]="arguments.series[$i].x and values must contain the same number of items.";
   }
  }else{
   if(!isset($args['y'])||!is_array($args['y'])||!array_is_list($args['y'])||count($args['y'])<1)$errors[]='arguments.y is required and must be a non-empty array when series is not used.';
   if(isset($args['x'])&&is_array($args['x'])&&isset($args['y'])&&is_array($args['y'])&&!in_array($chart,['histogram','boxplot'],true)&&count($args['x'])!==count($args['y']))$errors[]='arguments.x and arguments.y must contain the same number of items.';
  }
 }
 return $errors;
}


/**
 * Structured validation pipeline result. Custom tool constraints are classified
 * separately from JSON-schema shape errors so the Model can repair the right fields.
 */
function lac_tool_validate_arguments_result(string $name,array $args,array $meta=[]): array {
 $schema=$meta['schema']??[];
 $schemaErrors=lac_schema_validate_value($args,$schema,'arguments');
 if($schemaErrors)return ['valid'=>false,'stage'=>'schema_validation','violations'=>array_map(static function($message){
  $path='arguments';if(preg_match('/^(arguments(?:\\.[A-Za-z0-9_]+|\\[[0-9]+\\])*)/',$message,$m))$path=$m[1];
  return ['code'=>'SCHEMA_CONSTRAINT_FAILED','path'=>$path,'rule_id'=>'JSON_SCHEMA','message'=>$message,'retryable'=>true];
 },lac_schema_errors_structured($schemaErrors))];
 $baselineErrors=lac_schema_validate_value($args,$schema);
 $allErrors=lac_tool_validate_arguments($name,$args,$meta);
 $semanticErrors=array_values(array_diff($allErrors,$baselineErrors));
 if($semanticErrors)return ['valid'=>false,'stage'=>'semantic_validation','violations'=>array_map(static function($message){
  $path='arguments';if(preg_match('/arguments(?:\\.[A-Za-z0-9_]+|\\[[0-9]+\\])*/',$message,$m))$path=$m[0];
  return ['code'=>'ARGUMENT_CONSTRAINT_FAILED','path'=>$path,'rule_id'=>'TOOL_SEMANTIC_RULE','message'=>$message,'retryable'=>true];
 },$semanticErrors)];
 return ['valid'=>true,'stage'=>'preflight_complete','violations'=>[]];
}

/** Safe, model-visible validation guidance derived from registered constraints. */
function lac_tool_model_validation_rules(string $name): array {
 $catalog=function_exists('lac_tool_catalog')?lac_tool_catalog():[];
 $rules=$catalog[$name]['validation_rules']??[];
 return is_array($rules)?$rules:[];
}
