<?php
declare(strict_types=1);

/**
 * Phase 10: bounded multi-hop retrieval over model-authored research rounds.
 * Query planning remains explicit input; retrieved content is never treated as instructions.
 */
function lac_web_research_multihop(
    string $question,
    array $rounds,
    string $python,
    string $script,
    int $timeout,
    PDO $pdo
): array {
    $question = trim($question);
    if ($question === '') throw new InvalidArgumentException('Research question must not be empty.');
    $rounds = array_slice($rounds, 0, 3);
    if (!$rounds) throw new InvalidArgumentException('At least one research query round is required.');
    $allSources = [];
    $roundResults = [];
    $seenQueries = [];
    $totalQueries = 0;
    $totalNewSources = 0;
    $stopReason = 'all_planned_rounds_completed';

    foreach ($rounds as $index => $round) {
        if (!is_array($round)) continue;
        $objective = trim((string)($round['objective'] ?? ''));
        $queries = [];
        foreach ((array)($round['queries'] ?? []) as $query) {
            if (!is_string($query)) continue;
            $query = trim($query);
            if ($query === '' || isset($seenQueries[function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query)])) continue;
            $seenQueries[function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query)] = true;
            $queries[] = $query;
            if (count($queries) >= 4 || $totalQueries + count($queries) >= 12) break;
        }
        if (!$queries) {
            $roundResults[] = ['round'=>$index+1,'objective'=>$objective,'queries'=>[],'new_source_count'=>0,'status'=>'skipped_no_new_queries'];
            continue;
        }
        $totalQueries += count($queries);
        $remainingRounds = max(1, count($rounds) - $index);
        $roundTimeout = max(30, min($timeout, (int)ceil($timeout / $remainingRounds)));
        $result = lac_web_research_plan($question, $queries, $python, $script, $roundTimeout, $pdo);
        $newCount = 0;
        $roundSourceIds = [];
        foreach ((array)($result['evidence']['sources'] ?? []) as $source) {
            if (!is_array($source)) continue;
            $url = trim((string)($source['source_url'] ?? $source['url'] ?? ''));
            if ($url === '') continue;
            $key = strtolower(rtrim($url, '/'));
            $matched = (array)($source['metadata']['matched_queries'] ?? []);
            if (!isset($allSources[$key])) {
                $allSources[$key] = $source;
                $newCount++;
                $totalNewSources++;
            } else {
                $existing = (array)($allSources[$key]['metadata']['matched_queries'] ?? []);
                $allSources[$key]['metadata']['matched_queries'] = array_values(array_unique(array_merge($existing, $matched)));
                if (strlen((string)($source['text'] ?? '')) > strlen((string)($allSources[$key]['text'] ?? ''))) {
                    $source['metadata']['matched_queries'] = $allSources[$key]['metadata']['matched_queries'];
                    $allSources[$key] = $source;
                }
            }
            $roundSourceIds[] = (string)($source['source_id'] ?? '');
        }
        $roundResults[] = [
            'round'=>$index+1,
            'objective'=>$objective,
            'queries'=>$queries,
            'query_count'=>count($queries),
            'new_source_count'=>$newCount,
            'observed_source_count'=>count($roundSourceIds),
            'success'=>!empty($result['success']),
            'runs'=>$result['runs'] ?? [],
            'errors'=>$result['evidence']['errors'] ?? [],
        ];
        if ($newCount === 0 && !empty($round['stop_if_no_new_sources'])) {
            $stopReason = 'no_new_sources_in_round_'.($index+1);
            break;
        }
    }

    $sources = array_values($allSources);
    $ledger = [];
    foreach ($sources as $source) {
        $ledger[] = [
            'source_id'=>$source['source_id'] ?? null,
            'title'=>$source['title'] ?? '',
            'url'=>$source['source_url'] ?? $source['url'] ?? '',
            'retrieved_at'=>$source['retrieved_at'] ?? null,
            'content_hash'=>$source['content_hash'] ?? null,
            'matched_queries'=>$source['metadata']['matched_queries'] ?? [],
            'evidence_status'=>'unverified_source_material',
        ];
    }
    $roundsWithSources = count(array_filter($roundResults, static fn($r)=>($r['observed_source_count']??0)>0));
    return [
        'success'=>count($sources)>0,
        'research_schema'=>'lac-web-multihop-research.v1',
        'question'=>$question,
        'execution'=>[
            'planned_round_count'=>count($rounds),
            'completed_round_count'=>count($roundResults),
            'rounds_with_sources'=>$roundsWithSources,
            'query_count'=>$totalQueries,
            'unique_source_count'=>count($sources),
            'new_sources_total'=>$totalNewSources,
            'stop_reason'=>$stopReason,
        ],
        'rounds'=>$roundResults,
        'sources'=>$sources,
        'evidence_ledger'=>$ledger,
        'next_hop_guidance'=>[
            'status'=>count($sources)>0?'agent_review_required':'no_evidence_retrieved',
            'instructions'=>count($sources)>0
                ? 'Compare new sources with the question and uncovered aspects. If gaps remain, create another query round focused on counter-evidence, primary data, or specific dates.'
                : 'Rephrase the query, use domain-specific terms, or search for primary sources. Do not infer conclusions without evidence.',
        ],
        'policy'=>[
            'query_plans_are_model_authored'=>true,
            'maximum_rounds'=>3,
            'maximum_queries_per_round'=>4,
            'maximum_total_queries'=>12,
            'retrieved_content_is_untrusted'=>true,
            'truth_verified'=>false,
            'semantic_support_verified'=>false,
            'instruction_authority'=>false,
        ],
        'trust'=>'untrusted_external_data',
    ];
}
