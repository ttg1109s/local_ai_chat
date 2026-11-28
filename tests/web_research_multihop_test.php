<?php
require_once __DIR__.'/../app/web/web_research_multihop.php';
function lac_web_research_plan(string $question,array $queries,string $python,string $script,int $timeout,PDO $pdo): array {
    $sources=[];
    foreach($queries as $i=>$q) {
        if($q==='empty') continue;
        $url=($q==='duplicate')?'https://example.org/a':'https://example.org/'.rawurlencode($q);
        $sources[]=['source_id'=>'id_'.md5($url),'title'=>$q,'source_url'=>$url,'retrieved_at'=>'2026-11-07T00:00:00Z','content_hash'=>md5($url),'text'=>str_repeat($q.' ',40),'metadata'=>['matched_queries'=>[$q]]];
    }
    return ['success'=>true,'runs'=>array_map(static fn($q)=>['query'=>$q,'source_count'=>1],$queries),'evidence'=>['sources'=>$sources,'errors'=>[]]];
}
function massert($ok,$message){if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$pdo=(new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
$r=lac_web_research_multihop('Research question',[
 ['objective'=>'Overview','queries'=>['overview','duplicate']],
 ['objective'=>'Counter evidence','queries'=>['duplicate','counter']],
 ['objective'=>'Updates','queries'=>['latest']]
],'python','unused.php',240,$pdo);
massert($r['research_schema']==='lac-web-multihop-research.v1','schema');
massert($r['execution']['completed_round_count']===3,'all rounds executed');
massert($r['execution']['query_count']===4,'duplicate query removed across rounds');
massert($r['execution']['unique_source_count']===4,'source aggregation and deduplication');
massert($r['policy']['truth_verified']===false,'truth is not claimed');
$r2=lac_web_research_multihop('Research question',[
 ['objective'=>'First','queries'=>['empty'],'stop_if_no_new_sources'=>true],
 ['objective'=>'Second','queries'=>['later']]
],'python','unused.php',240,$pdo);
massert($r2['execution']['completed_round_count']===1,'stop after zero new sources');
massert($r2['execution']['stop_reason']==='no_new_sources_in_round_1','stop reason');
echo "web_research_multihop_test: PASS\n";
