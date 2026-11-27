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
    // Explicit fallback only: byte heuristic is not represented as exact tokenization.
    return (int)ceil(strlen($text) / 3.6);
}
function lac_agent_context_relevance_score(array $message,string $objective): float {
    $text=strtolower(lac_agent_context_message_text($message));$terms=preg_split('/[^\pL\pN_]+/u',strtolower($objective),-1,PREG_SPLIT_NO_EMPTY)?:[];$terms=array_values(array_unique(array_filter($terms,static fn($t)=>strlen($t)>2)));if(!$terms||$text==='')return 0.0;$hits=0;foreach($terms as $term)if(str_contains($text,$term))$hits++;return $hits/count($terms);
}
function lac_agent_context_message_text(array $message): string {
    $content=$message['content']??'';
    if(is_string($content))return $content;
    $encoded=json_encode($content,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($encoded)?$encoded:'';
}
function lac_agent_context_build(array $payload,array $options=[]): array {
    $messages=$payload['messages']??[];
    if(!is_array($messages)||count($messages)<1)return ['payload'=>$payload,'stats'=>['messages_in'=>count((array)$messages),'messages_out'=>count((array)$messages),'compacted'=>false]];
    $budget=max(2048,min(65536,(int)($options['token_budget']??12000)));
    $modelId=(string)($options['model_id']??'');
    $reserve=max(256,min((int)floor($budget*.35),(int)($options['reserve_tokens']??1800)));
    $usable=$budget-$reserve;
    $n=count($messages);$keep=[];$protected=[];
    // Preserve leading system/developer instructions and the original user objective.
    foreach($messages as $i=>$m){
        if(!is_array($m))continue;
        $role=(string)($m['role']??'');
        if(in_array($role,['system','developer'],true))$protected[$i]=true;
    }
    foreach($messages as $i=>$m){if(is_array($m)&&($m['role']??'')==='user'){$protected[$i]=true;break;}}
    $latest=max(6,min(24,(int)($options['recent_messages']??12)));
    for($i=max(0,$n-$latest);$i<$n;$i++)$keep[$i]=true;
    // Relevance-aware retention: older evidence can outrank low-value chatter.
    $objective='';foreach($messages as $m)if(is_array($m)&&($m['role']??'')==='user'){$objective=lac_agent_context_message_text($m);break;}
    $ranked=[];foreach($messages as $i=>$m){if(!is_array($m)||isset($keep[$i])||isset($protected[$i]))continue;$score=lac_agent_context_relevance_score($m,$objective);if($score>0)$ranked[]=['index'=>$i,'score'=>$score];}
    usort($ranked,static fn($a,$b)=>($b['score']<=>$a['score'])?:($b['index']<=>$a['index']));
    foreach(array_slice($ranked,0,8) as $candidate)$keep[$candidate['index']]=true;
    foreach($protected as $i=>$_)$keep[$i]=true;
    ksort($keep);$selected=[];$used=0;$omitted=[];
    // Newest messages win within the budget; protected instructions/objective are retained.
    $indices=array_keys($keep);rsort($indices);
    foreach($indices as $i){
        $m=$messages[$i]??null;if(!is_array($m))continue;
        $text=lac_agent_context_message_text($m);$cost=lac_agent_context_estimate_tokens($text,$modelId)+8;
        $isProtected=isset($protected[$i]);
        if(!$isProtected&&$used+$cost>$usable){$omitted[]=$i;continue;}
        if($isProtected&&$used+$cost>$budget){
            // Keep instructions but bound pathological oversized user content.
            $m['content']=substr($text,0,12000).' [objective/instruction truncated by context budget]';
            $cost=lac_agent_context_estimate_tokens((string)$m['content'],$modelId)+8;
        }
        $selected[$i]=$m;$used+=$cost;
    }
    $omitted=array_values(array_unique(array_merge($omitted,array_diff(range(0,$n-1),array_keys($selected)))));
    sort($omitted);
    if($omitted){
        $roles=[];$types=[];$digest=[];
        foreach($omitted as $i){$m=$messages[$i]??[];$role=(string)($m['role']??'unknown');$roles[$role]=($roles[$role]??0)+1;
            $txt=lac_agent_context_message_text(is_array($m)?$m:[]);
            if(preg_match('/"type"\s*:\s*"([a-zA-Z0-9_]+)"/',$txt,$mm))$types[$mm[1]]=($types[$mm[1]]??0)+1;
            if(count($digest)<8&&$txt!=='')$digest[]=['index'=>$i,'role'=>$role,'excerpt'=>substr(preg_replace('/\s+/u',' ',$txt),0,180)];
        }
        $summary=['type'=>'context_compaction_summary','source'=>'runtime_context_builder','omitted_message_count'=>count($omitted),'omitted_role_counts'=>$roles,'omitted_protocol_type_counts'=>$types,'older_message_excerpts'=>$digest,'authoritative_history'=>'Execution Store and Task Store are authoritative; retrieve Data Pack evidence when needed. This summary is not a substitute for source data.'];
        $summaryMessage=['role'=>'user','content'=>json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)];
        $selected[$n]=$summaryMessage;
    }
    ksort($selected);$bounded=$payload;$bounded['messages']=array_values($selected);
    $serialized=json_encode($bounded['messages'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    $stats=['messages_in'=>$n,'messages_out'=>count($bounded['messages']),'omitted_messages'=>count($omitted),'estimated_tokens'=>lac_agent_context_estimate_tokens((string)$serialized,$modelId),'tokenizer'=>is_array($GLOBALS['lac_context_tokenizer']??null)?'provider_callback':'byte_heuristic','model_id'=>$modelId?:null,'token_budget'=>$budget,'usable_budget'=>$usable,'compacted'=>(bool)$omitted];
    if(function_exists('lac_agent_log'))lac_agent_log('agent_context_built',$stats);
    return ['payload'=>$bounded,'stats'=>$stats];
}
