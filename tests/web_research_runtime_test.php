<?php
require_once __DIR__ . '/../app/web/web_research_runtime.php';
$run = 'stage5test' . bin2hex(random_bytes(5));
$path = lac_web_research_runtime_path($run);
try {
    $first = lac_web_research_runtime_reserve($run, ['  Market outlook 2026 ', 'Policy impact'], ['max_rounds'=>2,'max_queries'=>3,'max_queries_per_cycle'=>2]);
    if ($first['round_count'] !== 1 || $first['query_count'] !== 2 || count($first['accepted_queries']) !== 2) {
        throw new RuntimeException('Initial budget reservation failed.');
    }
    $second = lac_web_research_runtime_reserve($run, ['market outlook 2026', 'New evidence'], ['max_rounds'=>2,'max_queries'=>3,'max_queries_per_cycle'=>2]);
    if ($second['round_count'] !== 2 || $second['query_count'] !== 3 || count($second['accepted_queries']) !== 1 || count($second['duplicate_queries']) !== 1) {
        throw new RuntimeException('Duplicate suppression or cumulative budget failed.');
    }
    $blocked = false;
    try { lac_web_research_runtime_reserve($run, ['Another query'], ['max_rounds'=>2,'max_queries'=>3]); }
    catch (LengthException $e) { $blocked = true; }
    if (!$blocked) throw new RuntimeException('Expected round budget enforcement.');
    echo "PASS research runtime budget, deduplication and hard stop\n";
} finally {
    @unlink($path); @unlink($path . '.lock');
}
