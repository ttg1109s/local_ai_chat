<?php
require_once __DIR__.'/../app/web/web_knowledge_synthesis.php';
function expect_s11(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$ledger=[
 ['source_id'=>'S1','title'=>'Official report','url'=>'https://example.org/report','text'=>'The programme began in 2024 and covered 12 districts.'],
 ['source_id'=>'S2','title'=>'Independent review','url'=>'https://review.example.net/a','text'=>'The review found implementation delays in two districts.']
];
$result=lac_web_knowledge_synthesis([
 'research_question'=>'Assess programme rollout',
 'source_ledger'=>$ledger,
 'claims'=>[
  ['id'=>'C1','text'=>'The programme began in 2024.','type'=>'fact','evidence'=>[['source_id'=>'S1','quote'=>'The programme began in 2024','assessment'=>'supports']]],
  ['id'=>'C2','text'=>'Implementation was uneven.','type'=>'assessment','evidence'=>[['source_id'=>'S2','quote'=>'implementation delays in two districts','assessment'=>'supports'],['source_id'=>'S1','quote'=>'covered 12 districts','assessment'=>'refutes']]],
  ['id'=>'C3','text'=>'The programme improved outcomes.','type'=>'fact','evidence'=>[['source_id'=>'S1','quote'=>'not present','assessment'=>'supports']]],
  ['id'=>'C4','text'=>'Further study is needed.','type'=>'inference','evidence'=>[['source_id'=>'missing','quote'=>'some quote']]]
 ]
]);
expect_s11($result['success']===true,'success');
expect_s11($result['citation_integrity']['status']==='integrity_issues_detected','integrity issues expected');
expect_s11($result['citation_integrity']['contested_claim_ids']===['C2'],'contested claim detection');
expect_s11($result['citation_integrity']['missing_citation_claim_ids']===['C3'],'fact citation gap');
expect_s11($result['citation_integrity']['untraceable_claim_ids']===['C3','C4'],'untraceable claim detection');
expect_s11(count($result['synthesis']['bibliography'])===2,'bibliography dedup');
expect_s11($result['verification_policy']['truth_verified']===false,'truth must remain unverified');
$bad=false;try{lac_web_knowledge_synthesis(['research_question'=>'x','claims'=>array_fill(0,51,['text'=>'claim'])]);}catch(InvalidArgumentException $e){$bad=true;}
expect_s11($bad,'claim limit');
echo "web_knowledge_synthesis_test: OK\n";
