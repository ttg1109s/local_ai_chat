<?php
/** Runtime-enforced autonomy policy. Model output can never grant itself permissions. */
function lac_agent_autonomy_policy(array $context=[]): array {
 $configured=is_array($context['autonomy_policy']??null)?$context['autonomy_policy']:[];
 return array_replace_recursive([
  'level'=>2,'mode'=>'autonomous',
  'approval'=>['external'=>true,'irreversible'=>true,'sensitive'=>true],
  'execution'=>['max_total_seconds'=>300,'max_concurrency'=>4,'max_retries'=>2],
  'safety'=>['enforce_tool_permissions'=>true,'enforce_argument_schema'=>true,'enforce_workspace_scope'=>true,'prevent_duplicate_execution'=>true,'allow_permission_escalation'=>false]
 ],$configured);
}
function lac_agent_action_risks(string $tool,array $args,array $meta=[]): array {
 $risks=[];
 $declared=$meta['autonomy']['risk']??$meta['risk']??'';
 if(in_array($declared,['external','irreversible','sensitive'],true))$risks[]=$declared;
 // Conservative argument-sensitive checks for file operations, where applicable.
 $joined=strtolower($tool.' '.json_encode($args,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
 if(preg_match('/\b(delete|remove|purge|overwrite|drop|truncate)\b/',$joined))$risks[]='irreversible';
 if(preg_match('/\b(password|secret|credential|private_key|api_key|payment|bank|personal_data)\b/',$joined))$risks[]='sensitive';
 return array_values(array_unique($risks));
}
function lac_agent_autonomy_decision(string $tool,array $args,array $meta=[],array $context=[]): array {
 $policy=lac_agent_autonomy_policy($context);
 if((int)$policy['level']<2)return ['allowed'=>false,'approval_required'=>false,'reason'=>'AUTONOMY_LEVEL_TOO_LOW','risks'=>[]];
 $risks=lac_agent_action_risks($tool,$args,$meta);
 $required=array_values(array_filter($risks,static fn($risk)=>!empty($policy['approval'][$risk])));
 return ['allowed'=>!$required,'approval_required'=>(bool)$required,'reason'=>$required?'APPROVAL_REQUIRED':null,'risks'=>$risks,'required_approvals'=>$required];
}
