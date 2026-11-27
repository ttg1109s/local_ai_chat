<?php
require_once __DIR__ . '/../app/web/web_claim_review.php';
require_once __DIR__ . '/../app/web/web_verification.php';
require_once __DIR__ . '/../app/web/web_knowledge_synthesis.php';
require_once __DIR__ . '/../app/web/web_research_quality.php';
$body = 'The agency published its annual report on 12 March 2026 and confirmed the programme budget.';
$claims = [[
 'id'=>'C1','text'=>'The agency published its annual report on 12 March 2026.',
 'type'=>'fact','aspect'=>'publication',
 'evidence'=>[[
  'source_id'=>'S1','url'=>'https://agency.example/report','title'=>'Annual report',
  'text'=>$body,'quote'=>'The agency published its annual report on 12 March 2026',
  'assessment'=>'supports','assessment_rationale'=>'The quoted sentence directly states the publication date.'
 ]]
]];
$review = lac_web_review_claims($claims);
$verify = lac_web_verify_evidence($claims);
$synthesis = lac_web_knowledge_synthesis([
 'research_question'=>'When was the annual report published?',
 'claims'=>$claims,
 'source_ledger'=>[['source_id'=>'S1','title'=>'Annual report','url'=>'https://agency.example/report','text'=>$body]]
]);
$quality = lac_web_research_quality([
 'sources'=>[['source_id'=>'S1','title'=>'Annual report','url'=>'https://agency.example/report','text'=>$body,'published_at'=>'2026-03-12']],
 'claims'=>[['claim_id'=>'C1','aspect'=>'publication','source_ids'=>['S1'],'assessments'=>['supports']]],
 'required_aspects'=>['publication']
]);
if (($review['claims'][0]['review']['status'] ?? '') !== 'model_assessed_supported') exit(1);
if (empty($verify['claims'][0]['evidence'][0]['quote_exactly_found'])) exit(1);
if (($synthesis['citation_integrity']['status'] ?? '') !== 'structurally_traceable') exit(1);
if (($quality['quality_gate']['status'] ?? '') !== 'research_completeness_sufficient_for_draft') exit(1);
if (!empty($synthesis['verification_policy']['truth_verified'])) exit(1);
echo "PASS claim evidence pipeline and verification boundaries\\n";
