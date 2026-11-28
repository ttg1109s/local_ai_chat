<?php
require_once __DIR__ . '/../app/web/web_verification.php';
function check($ok, $message) { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$result = lac_web_verify_evidence([
 ['id'=>'c1','text'=>'Vietnam exports coffee to many countries','evidence'=>[
   ['source_id'=>'s1','url'=>'https://example.org/report','title'=>'Report','text'=>'Vietnam exports coffee to many countries each year.','quote'=>'Vietnam exports coffee to many countries','source_type'=>'primary'],
   ['source_id'=>'s2','url'=>'javascript:alert(1)','text'=>'Vietnam coffee export report']
 ]],
 ['id'=>'c2','text'=>'A claim with no matching evidence','evidence'=>[
   ['url'=>'https://other.example/article','text'=>'Completely unrelated material']
 ]]
]);
check($result['verification_schema']==='lac-web-evidence-audit.v1','schema');
check(count($result['claims'])===2,'claim count');
check($result['claims'][0]['audit']['exact_quote_count']===1,'exact quote count');
check($result['claims'][0]['evidence'][0]['url_valid']===true,'https URL accepted');
check($result['claims'][0]['evidence'][1]['url_valid']===false,'javascript URL rejected');
check($result['claims'][1]['audit']['status']==='insufficient_evidence','weak evidence flagged');
check($result['claims'][0]['audit']['truth_verified']===false,'truth not asserted');
echo "web_verification_test: OK\n";
