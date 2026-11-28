<?php
/** Assemble the modular Agent tool catalog and expose discovery helpers. */
function lac_tool_catalog(): array {
 $base=__DIR__;
 $tools=require $base.'/definitions/core.php';
 $tools=array_merge($tools,require $base.'/definitions/web.php',require $base.'/definitions/system.php',require $base.'/definitions/math.php',require $base.'/definitions/data.php');
 $catalogDescriptions=require $base.'/catalog_descriptions.php';
  foreach($catalogDescriptions as $toolId=>$shortDescription){if(isset($tools[$toolId]))$tools[$toolId]['catalog_description']=$shortDescription;}
 foreach($tools as $id=>&$tool){
  $tool['domain']=$tool['domain']??match($tool['group']??''){
   'Website'=>'web','System'=>'system','Python','Math & Statistics'=>'data',
   default=>'general'
  };
  // Generic runtime contract. Side effects default conservatively for unknown tools.
  $tool['side_effects']=$tool['side_effects']??false;
  $tool['idempotent']=$tool['idempotent']??!$tool['side_effects'];
  $tool['cache_policy']=$tool['cache_policy']??((($tool['risk']??'')==='read'&&$tool['idempotent'])?'conditional':'never');
  $tool['timeout']=$tool['timeout']??60;
  $tool['retry_policy']=$tool['retry_policy']??['max_attempts'=>((!empty($tool['retryable'])&&$tool['idempotent'])?2:1),'retry_on'=>((!empty($tool['retryable'])&&$tool['idempotent'])?['TRANSIENT','TIMEOUT']:[])];
  $tool['concurrency_limit']=$tool['concurrency_limit']??(($tool['risk']??'')==='compute'?4:1);
 }
 unset($tool);
 $guidance=require $base.'/guidance.php';
  foreach($guidance as $id=>$guide){if(isset($tools[$id])){$tools[$id]['when_to_use']=$guide['when'];$tools[$id]['when_not_to_use']=$guide['avoid'];$tools[$id]['examples']=$guide['examples'];}}
 $relationships=require $base.'/relationships.php';
 foreach($tools as $id=>&$tool){
  // Level-2 defaults: local read/compute tools run autonomously. Tools must
  // explicitly declare external/irreversible/sensitive risk to require approval.
  $tool['autonomy']=$tool['autonomy']??['risk'=>in_array(($tool['risk']??''),['read','compute'],true)?'local':'sensitive','min_level'=>2];
  $tool['capability_id']=$id;
  $tool['can_feed']=$relationships[$id]['can_feed']??[];
  $tool['selection_policy']='task_match_then_schema_validate';
 }
 unset($tool);
 $validationRules=require $base.'/validation_rules.php';
 foreach($validationRules as $toolName=>$rules)if(isset($tools[$toolName]))$tools[$toolName]['validation_rules']=$rules;
 return $tools;
}
/** Build a compact, model-agnostic capability graph for planning and diagnostics. */
function lac_tool_capability_graph(?array $catalog=null): array {
 $catalog=$catalog??lac_tool_catalog();$nodes=[];$edges=[];
 foreach($catalog as $id=>$tool){
  $nodes[$id]=['id'=>$id,'group'=>$tool['group']??'General','domain'=>$tool['domain']??'general','purpose'=>$tool['when_to_use']??$tool['description']??$id,'avoid'=>$tool['when_not_to_use']??'','required'=>$tool['schema']['required']??[]];
  foreach(($tool['can_feed']??[]) as $target)if(isset($catalog[$target]))$edges[]=['from'=>$id,'to'=>$target,'type'=>'data_handoff'];
 }
 return ['version'=>1,'protocol'=>'LAC_JSON_CALLING','nodes'=>$nodes,'edges'=>$edges];
}
/** Domain-aware discovery: compact summaries first, detailed schema only for selected tools. */
function lac_tool_discover(string $query='',array $domains=[],int $limit=12): array {
 $lower=static fn(string $v):string=>function_exists('mb_strtolower')?mb_strtolower($v,'UTF-8'):strtolower($v);
 $contains=static fn(string $hay,string $needle):bool=>(function_exists('mb_strpos')?mb_strpos($hay,$needle):strpos($hay,$needle))!==false;
 $catalog=lac_tool_catalog();$query=$lower(trim($query));$domains=array_values(array_filter(array_map('strtolower',$domains)));
 $hits=[];
 foreach($catalog as $id=>$tool){
  if(!empty($tool['internal']))continue;
  if($domains&&!in_array(strtolower((string)$tool['domain']),$domains,true))continue;
  $hay=$lower($id.' '.$tool['label'].' '.$tool['description'].' '.($tool['when_to_use']??'').' '.($tool['when_not_to_use']??'').' '.$tool['group'].' '.$tool['domain']);
  $score=$query===''?1:0;
  foreach(preg_split('/\\s+/u',$query,-1,PREG_SPLIT_NO_EMPTY) as $term){if($contains($hay,$term))$score+=2;if($contains($lower($id),$term))$score+=2;}
  if($score>0)$hits[]=['score'=>$score,'tool'=>$tool];
 }
 usort($hits,static fn($a,$b)=>$b['score']<=>$a['score']);
 return array_slice(array_map(static fn($x)=>$x['tool'],$hits),0,max(1,min(50,$limit)));
}
function lac_tool_domains(): array {
 $domains=[];foreach(lac_tool_catalog() as $tool){if(!empty($tool['internal']))continue;$domains[$tool['domain']]=($domains[$tool['domain']]??0)+1;}
 return $domains;
}

