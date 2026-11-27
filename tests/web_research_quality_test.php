<?php
require_once __DIR__.'/../app/web/web_research_quality.php';
function qassert($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$input=['as_of'=>'2026-11-07','required_aspects'=>['economy','environment'],
'sources'=>[
['source_id'=>'s1','url'=>'https://example.org/report','title'=>'Report','text'=>str_repeat('evidence ',60),'source_type'=>'primary','published_at'=>'2026-10-01','cited_source_urls'=>['https://example.net/original']],
['source_id'=>'s2','url'=>'https://example.net/original','title'=>'Original','text'=>str_repeat('data ',100),'source_type'=>'primary','published_at'=>'2025-01-01']],
'claims'=>[
['claim_id'=>'c1','text'=>'Claim one','aspect'=>'economy','source_ids'=>['s1','s2'],'assessments'=>['supports','refutes']],
['claim_id'=>'c2','text'=>'Claim two','aspect'=>'environment','source_ids'=>['missing']]]];
$r=lac_web_research_quality($input);
qassert($r['research_quality_schema']==='lac-web-research-quality.v1','schema');
qassert($r['aspect_coverage'][0]['status']==='covered','covered aspect');
qassert($r['aspect_coverage'][1]['status']==='uncovered','uncovered aspect');
qassert($r['citation_chain']['edge_count']===1,'citation edge');
qassert($r['citation_chain']['edges'][0]['to_source_id']==='s2','citation resolution');
qassert($r['claim_coverage'][1]['source_ids_missing']===['missing'],'missing source ref');
qassert($r['temporal_analysis'][0]['opposing_assessments_present']===true,'opposing assessments');
qassert($r['temporal_analysis'][0]['temporally_mixed']===true && $r['temporal_analysis'][0]['publication_date_spread_days']>30,'temporal spread');
qassert($r['quality_gate']['status']==='needs_more_evidence','gate blocks incomplete research');
qassert($r['truth_verified']===false && $r['source_reliability_verified']===false,'no truth claims');
$empty=lac_web_research_quality(['sources'=>[],'claims'=>[]]);
qassert($empty['quality_gate']['status']==='needs_more_evidence','empty input');
echo "web_research_quality_test: PASS\n";
