<?php
require_once __DIR__ . '/../app/web/web_claim_review.php';
function check($ok, $message) { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$r = lac_web_review_claims([
 ['id'=>'c1','text'=>'The policy started in 2024','evidence'=>[
  ['source_id'=>'a','url'=>'https://primary.example/policy','text'=>'The policy started in 2024 and applies nationwide.','quote'=>'The policy started in 2024','assessment'=>'supports'],
  ['source_id'=>'b','url'=>'https://review.example/news','text'=>'The policy did not start in 2024; it began in 2025.','quote'=>'The policy did not start in 2024','assessment'=>'refutes'],
  ['source_id'=>'x','url'=>'javascript:alert(1)','text'=>'The policy started in 2024','quote'=>'The policy started in 2024','assessment'=>'supports']
 ]],
 ['id'=>'c2','text'=>'An unsupported statement','evidence'=>[
  ['url'=>'https://example.net/x','text'=>'Other text','quote'=>'Missing quote','assessment'=>'supports']
 ]]
]);
check($r['review_schema']==='lac-web-claim-review.v1','schema');
check($r['claims'][0]['review']['status']==='contested_model_assessment','contested');
check($r['claims'][0]['review']['contradiction_detected']===true,'contradiction flag');
check($r['claims'][0]['evidence'][2]['accepted_for_review']===false,'invalid URL rejected');
check($r['claims'][1]['review']['status']==='insufficient_evidence','insufficient evidence');
check($r['claims'][0]['review']['truth_verified']===false,'no truth claim');
echo "web_claim_review_test: OK\n";
