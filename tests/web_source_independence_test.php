<?php
require_once __DIR__ . '/../app/web/web_source_intelligence.php';
$sources = [
 ['source_id'=>'a','url'=>'https://news.example.com/a','text'=>str_repeat('A common report says the project launched in March 2026 with new infrastructure and public funding after officials announced a major regional partnership and a multi year development programme. ',4),'publisher'=>'Example Media'],
 ['source_id'=>'b','url'=>'https://news.example.com/b','text'=>str_repeat('A common report says the project launched in March 2026 with new infrastructure and public funding after officials announced a major regional partnership and a multi year development programme. ',4).' Additional editorial context and a short update.','publisher'=>'Example Media'],
 ['source_id'=>'c','url'=>'https://independent.test/story','text'=>'An entirely separate report with unrelated wording and content.'],
];
$result = lac_web_source_intelligence($sources);
if (count($result['same_hostname_groups'] ?? []) !== 1) {
    fwrite(STDERR, "Expected same-hostname relationship group.\\n"); exit(1);
}
if (empty($result['near_duplicate_candidates'])) {
    fwrite(STDERR, "Expected near-duplicate candidate.\\n"); exit(1);
}
if (($result['independence_assessment'] ?? '') !== 'not_verified') {
    fwrite(STDERR, "Independence must remain unverified.\\n"); exit(1);
}
echo "PASS source independence heuristics remain conservative\\n";
