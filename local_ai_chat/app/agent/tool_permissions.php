<?php
function lac_agent_permissions(mixed $raw, bool $sandbox=false): array {
 $catalog=lac_tool_catalog();$requested=is_array($raw)?$raw:[];$allowed=[];
 foreach($catalog as $id=>$meta){if(!empty($meta['internal']))continue;if(!$sandbox && in_array($id,$requested,true))$allowed[]=$id;}
 // Permissions are strict opt-ins: never infer or auto-enable a sibling tool.
 return $allowed;
}
function lac_agent_has_permission(array $permissions,string $tool): bool {return in_array($tool,$permissions,true);}

/**
 * Server-side preflight against the immutable user-enabled tool snapshot. This is a
 * security boundary only; it does not infer intent or grant capabilities.
 */
function lac_agent_tool_selection_violations(array $calls,array $catalog,array $permissions): array {
 $violations=[];
 foreach($calls as $index=>$call){
  if(!is_array($call)||!empty($call['invalid']))continue;
  $name=(string)($call['tool']??'');
  if($name===''||!isset($catalog[$name])){
   $violations[]=['index'=>$index,'tool'=>$name!==''?$name:'unknown_tool','error_code'=>'UNKNOWN_TOOL'];
  }elseif(!lac_agent_has_permission($permissions,$name)){
   $violations[]=['index'=>$index,'tool'=>$name,'error_code'=>'TOOL_NOT_ALLOWED'];
  }
 }
 return $violations;
}
