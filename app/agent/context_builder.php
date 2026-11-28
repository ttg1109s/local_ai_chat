<?php
/**
 * Bounded per-inference context projection.
 * The canonical payload remains intact in the loop; only the copy sent to the model is compacted.
 * Execution truth and durable evidence remain in Execution Store / Data Pack.
 */
/** Install a model-specific tokenizer callback from the provider adapter. */
function lac_agent_context_set_tokenizer(?callable $counter,string $modelId=''): void {
    $GLOBALS['lac_context_tokenizer']=['counter'=>$counter,'model_id'=>$modelId,'configured_at'=>date(DATE_ATOM)];
}
function lac_agent_context_estimate_tokens(string $text,?string $modelId=null): int {
    $config=$GLOBALS['lac_context_tokenizer']??null;
    if(is_array($config)&&is_callable($config['counter']??null)&&(!$modelId||!($config['model_id']??'')||hash_equals((string)$config['model_id'],$modelId))){
        try{$n=($config['counter'])($text,$modelId);if(is_int($n)&&$n>=0)return $n;}catch(Throwable $e){if(function_exists('lac_agent_log'))lac_agent_log('context_tokenizer_failed',['model_id'=>$modelId,'error'=>$e->getMessage()]);}
    }
    return (int)ceil(strlen($text) / 3.6);
}
function lac_agent_context_relevance_score(array $message,string $objective): float {
    $text=strtolower(lac_agent_context_message_text($message));$terms=preg_split('/[^\pL\pN_]+/u',strtolower($objective),-1,PREG_SPLIT_NO_EMPTY)?:[];$terms=array_values(array_unique(array_filter($terms,static fn($t)=>strlen($t)>2)));if(!$terms||$text==='')return 0.0;$hits=0;foreach($terms as $term)if(str_contains($text,$term))$hits++;return $hits/count($terms);
}
function lac_agent_context_message_text(array $message): string {
    $content=$message['content']??'';
    if(is_string($content))return $content;
    $encoded=json_encode($content,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return is_string($encoded)?$encoded:'';
}
/** Truncate a protected message to a hard token ceiling. */
function lac_agent_context_truncate_message(array $message,int $maxTokens,?string $modelId=null): array {
 $text=lac_agent_context_message_text($message);$suffix=' [truncated by context budget]';$lo=0;$hi=strlen($text);$best='';
 while($lo<=$hi){$mid=intdiv($lo+$hi,2);$candidate=substr($text,0,$mid).$suffix;
  if(lac_agent_context_estimate_tokens($candidate,$modelId)<=$maxTokens){$best=$candidate;$lo=$mid+1;}else{$hi=$mid-1;}}
 if($best==='')$best=' [truncated]';$message['content']=$best;return $message;
}
function lac_agent_context_build(array $payload,array $options=[]): array {
    $messages=$payload['messages']??[];
    if(!is_array($messages)||count($messages)<1)return ['payload'=>$payload,'stats'=>['messages_in'=>count((array)$messages),'messages_out'=>count((array)$messages),'compacted'=>false]];
    $budget=max(2048,min(65536,(int)($options['token_budget']??12000)));
    $modelId=(string)($options['model_id']??'');
    $reserve=max(256,min((int)floor($budget*.35),(int)($options['reserve_tokens']??1800)));
    // Leave explicit headroom for JSON framing, role fields, and the compaction summary,
    // which are not included in the per-message estimates below.
    $serializationHeadroom=max(256,(int)ceil($budget*.12));
    $usable=max(0,$budget-$reserve-$serializationHeadroom);
    $n=count($messages);$keep=[];$protected=[];
    foreach($messages as $i=>$m){if(!is_array($m))continue;$role=(string)($m['role']??'');if(in_array($role,['system','developer'],true))$protected[$i]=true;}
    foreach($messages as $i=>$m){if(is_array($m)&&($m['role']??'')==='user'){$protected[$i]=true;break;}}
    $latest=max(6,min(24,(int)($options['recent_messages']??12)));
    for($i=max(0,$n-$latest);$i<$n;$i++)$keep[$i]=true;
    $objective='';foreach($messages as $m)if(is_array($m)&&($m['role']??'')==='user'){$objective=lac_agent_context_message_text($m);break;}
    $ranked=[];foreach($messages as $i=>$m){if(!is_array($m)||isset($keep[$i])||isset($protected[$i]))continue;$score=lac_agent_context_relevance_score($m,$objective);if($score>0)$ranked[]=['index'=>$i,'score'=>$score];}
    usort($ranked,static fn($a,$b)=>($b['score']<=>$a['score'])?:($b['index']<=>$a['index']));
    foreach(array_slice($ranked,0,8) as $candidate)$keep[$candidate['index']]=true;
    foreach($protected as $i=>$_)$keep[$i]=true;
    ksort($keep);$selected=[];$used=0;$omitted=[];
    $protectedIndices=array_keys($protected);sort($protectedIndices);$indices=array_values(array_diff(array_keys($keep),$protectedIndices));rsort($indices);$indices=array_merge($protectedIndices,$indices);
    foreach($indices as $i){
        $m=$messages[$i]??null;if(!is_array($m))continue;
        $text=lac_agent_context_message_text($m);$cost=lac_agent_context_estimate_tokens($text,$modelId)+8;$isProtected=isset($protected[$i]);
        if(!$isProtected&&$used+$cost>$usable){$omitted[]=$i;continue;}
        if($isProtected&&$used+$cost>$usable){$m=lac_agent_context_truncate_message($m,max(0,$usable-$used-8),$modelId);$cost=lac_agent_context_estimate_tokens(lac_agent_context_message_text($m),$modelId)+8;}
        if($used+$cost>$usable){$omitted[]=$i;continue;}
        $selected[$i]=$m;$used+=$cost;
    }
    $omitted=array_values(array_unique(array_merge($omitted,array_diff(range(0,$n-1),array_keys($selected)))));sort($omitted);
    if($omitted){
        $roles=[];$types=[];$digest=[];
        foreach($omitted as $i){$m=$messages[$i]??[];$role=(string)($m['role']??'unknown');$roles[$role]=($roles[$role]??0)+1;$txt=lac_agent_context_message_text(is_array($m)?$m:[]);
            if(preg_match('/"type"\s*:\s*"([a-zA-Z0-9_]+)"/',$txt,$mm))$types[$mm[1]]=($types[$mm[1]]??0)+1;
            if(count($digest)<4&&$txt!=='')$digest[]=['index'=>$i,'role'=>$role,'excerpt'=>substr(preg_replace('/\s+/u',' ',$txt),0,80)];
        }
        $summary=['type'=>'context_compaction_summary','source'=>'runtime_context_builder','omitted_message_count'=>count($omitted),'omitted_role_counts'=>$roles,'omitted_protocol_type_counts'=>$types,'older_message_excerpts'=>$digest,'authoritative_history'=>'Execution Store and Task Store are authoritative; retrieve Data Pack evidence when needed. This summary is not a substitute for source data.'];
        $selected[$n]=['role'=>'user','content'=>json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)];
    }
    ksort($selected);$bounded=$payload;$bounded['messages']=array_values($selected);
    $serialized=json_encode($bounded['messages'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    $stats=['messages_in'=>$n,'messages_out'=>count($bounded['messages']),'omitted_messages'=>count($omitted),'estimated_tokens'=>lac_agent_context_estimate_tokens((string)$serialized,$modelId),'tokenizer'=>is_array($GLOBALS['lac_context_tokenizer']??null)?'provider_callback':'byte_heuristic','model_id'=>$modelId?:null,'token_budget'=>$budget,'usable_budget'=>$usable,'compacted'=>(bool)$omitted];
    if(function_exists('lac_agent_log'))lac_agent_log('agent_context_built',$stats);
    return ['payload'=>$bounded,'stats'=>$stats];
}
