<?php
declare(strict_types=1);

/**
 * Stage 5 runtime-enforced research budget.
 * Counts attempted research cycles and distinct normalized queries per Agent run.
 * Failed retrieval attempts still consume budget; audit-only reuse does not.
 */
function lac_web_research_runtime_path(string $runId): string {
    $safe = preg_replace('/[^a-f0-9]/i', '', $runId);
    if ($safe === '') $safe = 'unscoped';
    return dirname(__DIR__, 2) . '/storage/agent-tasks/web-research-' . $safe . '.json';
}
function lac_web_research_normalize_query(string $query): string {
    $query = trim(function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query));
    $query = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query) ?? $query;
    return trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
}
function lac_web_research_runtime_snapshot(string $runId): array {
    $path = lac_web_research_runtime_path($runId);
    if (!is_file($path)) return ['round_count'=>0,'query_count'=>0,'queries'=>[],'updated_at'=>null];
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? array_replace(['round_count'=>0,'query_count'=>0,'queries'=>[],'updated_at'=>null], $data) : ['round_count'=>0,'query_count'=>0,'queries'=>[],'updated_at'=>null];
}
function lac_web_research_runtime_reserve(string $runId, array $queries, array $policy=[]): array {
    $maxRounds = max(1, min(8, (int)($policy['max_rounds'] ?? 5)));
    $maxQueries = max(1, min(40, (int)($policy['max_queries'] ?? 20)));
    $maxPerCycle = max(1, min(12, (int)($policy['max_queries_per_cycle'] ?? 12)));
    $normalized = [];
    foreach ($queries as $query) {
        if (!is_string($query)) continue;
        $raw = trim($query);
        $key = lac_web_research_normalize_query($raw);
        if ($key === '' || isset($normalized[$key])) continue;
        $normalized[$key] = $raw;
    }
    if (!$normalized) throw new InvalidArgumentException('Research requires at least one non-empty query.');
    $path = lac_web_research_runtime_path($runId);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not create the research budget directory.');
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock) throw new RuntimeException('Could not lock the research budget.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Could not acquire the research budget lock.');
        $old = json_decode((string)@file_get_contents($path), true);
        if (!is_array($old)) $old = ['round_count'=>0,'query_count'=>0,'queries'=>[]];
        $seen = is_array($old['queries'] ?? null) ? $old['queries'] : [];
        $roundCount = (int)($old['round_count'] ?? 0);
        $queryCount = (int)($old['query_count'] ?? 0);
        if ($roundCount >= $maxRounds) throw new LengthException('The task has reached its research-round limit.');
        $fresh = [];
        $duplicates = [];
        foreach ($normalized as $key=>$raw) {
            if (isset($seen[$key])) { $duplicates[] = $raw; continue; }
            $fresh[$key] = $raw;
        }
        if (!$fresh) throw new DomainException('All research queries duplicate queries already used in this task.');
        if (count($fresh) > $maxPerCycle) $fresh = array_slice($fresh, 0, $maxPerCycle, true);
        $remaining = max(0, $maxQueries - $queryCount);
        if ($remaining < 1) throw new LengthException('The task has reached its total research-query limit.');
        if (count($fresh) > $remaining) $fresh = array_slice($fresh, 0, $remaining, true);
        if (!$fresh) throw new LengthException('No research-query budget remains.');
        foreach ($fresh as $key=>$raw) $seen[$key] = ['query'=>$raw,'reserved_at'=>date(DATE_ATOM)];
        $next = [
            'run_id'=>$runId,
            'round_count'=>$roundCount + 1,
            'query_count'=>$queryCount + count($fresh),
            'queries'=>$seen,
            'updated_at'=>date(DATE_ATOM),
            'limits'=>['max_rounds'=>$maxRounds,'max_queries'=>$maxQueries,'max_queries_per_cycle'=>$maxPerCycle],
        ];
        $tmp = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
        $json = json_encode($next, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp); throw new RuntimeException('Could not save the research budget.');
        }
        return ['round_count'=>$next['round_count'],'query_count'=>$next['query_count'],'accepted_queries'=>array_values($fresh),'duplicate_queries'=>$duplicates,'limits'=>$next['limits'],'remaining_rounds'=>max(0,$maxRounds-$next['round_count']),'remaining_queries'=>max(0,$maxQueries-$next['query_count'])];
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}
