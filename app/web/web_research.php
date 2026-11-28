<?php
declare(strict_types=1);

/**
 * Deterministic multi-query research orchestration.
 * It aggregates source evidence; it does not claim to fact-check truth.
 */
function lac_web_research_plan(string $question, array $queries, string $python, string $script, int $timeout, PDO $pdo): array {
    $question = trim($question);
    if ($question === '') throw new InvalidArgumentException('Research question must not be empty.');
    $cleanQueries = [];
    foreach ($queries as $query) {
        if (!is_string($query)) continue;
        $query = trim($query);
        if ($query !== '') $cleanQueries[$query] = true;
        if (count($cleanQueries) >= 4) break;
    }
    if (!$cleanQueries) $cleanQueries[$question] = true;

    $rawSources = [];
    $runs = [];
    $errors = [];
    $perQueryTimeout = max(20, min(90, (int)ceil($timeout / count($cleanQueries))));
    foreach (array_keys($cleanQueries) as $query) {
        $result = lac_web_research($query, $python, $script, $perQueryTimeout);
        $sources = is_array($result['sources'] ?? null) ? $result['sources'] : [];
        $runs[] = [
            'query' => $query,
            'engine' => $result['engine_used'] ?? null,
            'source_count' => count($sources),
            'error' => $result['error'] ?? null,
        ];
        if (!empty($result['error'])) $errors[] = ['query' => $query, 'error' => (string)$result['error']];
        foreach ($sources as $source) {
            if (!is_array($source)) continue;
            $source['metadata'] = array_merge((array)($source['metadata'] ?? []), ['matched_queries' => [$query]]);
            $key = strtolower((string)($source['url'] ?? ''));
            if ($key === '') continue;
            if (!isset($rawSources[$key])) $rawSources[$key] = $source;
            else {
                $existing = (array)($rawSources[$key]['metadata']['matched_queries'] ?? []);
                $rawSources[$key]['metadata']['matched_queries'] = array_values(array_unique(array_merge($existing, [$query])));
                if (strlen((string)($source['text'] ?? '')) > strlen((string)($rawSources[$key]['text'] ?? ''))) {
                    $source['metadata'] = $rawSources[$key]['metadata'];
                    $rawSources[$key] = $source;
                }
            }
        }
    }
    $evidence = lac_web_normalize_results(array_values($rawSources), [
        'provider' => 'multi_query_web_research',
        'errors' => $errors,
    ]);
    $ledger = [];
    foreach ($evidence['sources'] as $source) {
        $ledger[] = [
            'source_id' => $source['source_id'],
            'title' => $source['title'],
            'url' => $source['source_url'],
            'retrieved_at' => $source['retrieved_at'],
            'content_hash' => $source['content_hash'],
            'matched_queries' => $source['metadata']['matched_queries'] ?? [],
            'evidence_status' => 'unverified_source_material',
        ];
    }
    $storage = lac_web_source_store($pdo, $evidence['sources']);
    return [
        'success' => count($evidence['sources']) > 0,
        'research_schema' => 'lac-web-research.v1',
        'question' => $question,
        'plan' => ['queries' => array_keys($cleanQueries), 'query_count' => count($cleanQueries)],
        'runs' => $runs,
        'evidence' => $evidence,
        'evidence_ledger' => $ledger,
        'citation_storage' => $storage,
        'verification_policy' => [
            'status' => 'not_fact_checked',
            'note' => 'Matching source or query counts are supporting signals only; they do not prove a claim. The Agent must compare content, publication dates, and independent provenance.',
        ],
        'trust' => 'untrusted_external_data',
        'instruction_authority' => false,
    ];
}
