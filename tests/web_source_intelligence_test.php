<?php
require_once __DIR__ . '/../app/web/web_source_intelligence.php';
function check($ok, $message) { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$r = lac_web_source_intelligence([
 ['source_id'=>'s1','url'=>'https://example.org/original?utm_source=x#top','title'=>'Original','text'=>'Same report text','publisher_id'=>'publisher-a','source_type'=>'primary','published_at'=>'2025-01-01'],
 ['source_id'=>'s2','url'=>'https://mirror.example/copy','title'=>'Copy','text'=>'Same report text','original_source_url'=>'https://example.org/original','publisher_id'=>'publisher-b','source_type'=>'secondary','published_at'=>'2025-01-02'],
 ['source_id'=>'s3','url'=>'https://example.org/other','title'=>'Other','text'=>'Different text','publisher_id'=>'publisher-a','source_type'=>'unknown']
]);
check($r['source_intelligence_schema']==='lac-web-source-intelligence.v1','schema');
check($r['source_count']===3,'source count');
check(count($r['lineage_clusters'])===1,'duplicate lineage cluster');
check(count($r['lineage_relationships'][0]['signals'])>=2,'multiple lineage signals');
check(count($r['publisher_or_owner_groups'])===1,'publisher grouping');
check($r['independence_assessment']==='not_verified','independence not claimed');
check($r['sources'][0]['reliability_score']===null,'no fabricated reliability score');
echo "web_source_intelligence_test: OK\n";
