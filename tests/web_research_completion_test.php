<?php
require_once __DIR__.'/../app/web/web_research_completion.php';
function cassert($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$quality=['quality_gate'=>['status'=>'needs_more_evidence'],'uncovered_aspects'=>['costs','risks'],'claim_coverage'=>[['coverage'=>'no_linked_evidence','source_ids_missing'=>['missing']]]];
$r=lac_web_research_completion(['quality_assessment'=>$quality,'sources'=>[['url'=>'https://example.org/a']],'claims'=>[['claim_id'=>'c1']],'required_aspects'=>['costs','risks'],'round_number'=>2,'max_rounds'=>5,'query_count'=>6,'max_queries'=>20]);
cassert($r['decision']==='continue','continue while gaps remain');
cassert(count($r['next_cycle_intents'])===3,'coverage and citation intents');
cassert($r['coverage']['uncovered_aspects']===['costs','risks'],'uncovered aspects surfaced');
$ready=lac_web_research_completion(['quality_assessment'=>['quality_gate'=>['status'=>'research_completeness_sufficient_for_draft'],'uncovered_aspects'=>[],'claim_coverage'=>[]],'sources'=>[['url'=>'https://example.org/a']],'round_number'=>1]);
cassert($ready['decision']==='stop' && $ready['status']==='ready_for_synthesis','ready gate');
$budget=lac_web_research_completion(['quality_assessment'=>$quality,'sources'=>[['url'=>'https://example.org/a']],'round_number'=>5,'max_rounds'=>5]);
cassert($budget['decision']==='stop_with_limitations','bounded stop');
cassert($budget['truth_verified']===false && $budget['semantic_entailment_verified']===false,'no semantic/truth claims');
echo "web_research_completion_test: PASS\n";
