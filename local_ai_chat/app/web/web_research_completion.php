<?php
declare(strict_types=1);

/**
 * Stage 4: deterministic completion gate and next-cycle planning hints.
 * This module does not generate queries, judge semantic entailment, or verify truth.
 * The Agent remains responsible for wording the next queries and deciding whether to call again.
 */
function lac_web_research_completion(array $input): array {
    $quality = is_array($input['quality_assessment'] ?? null) ? $input['quality_assessment'] : [];
    $sources = array_slice(is_array($input['sources'] ?? null) ? $input['sources'] : [], 0, 100);
    $claims = array_slice(is_array($input['claims'] ?? null) ? $input['claims'] : [], 0, 50);
    $required = array_values(array_unique(array_filter(array_map('strval', (array)($input['required_aspects'] ?? [])), static fn($v) => trim($v) !== '')));
    $round = max(1, (int)($input['round_number'] ?? 1));
    $maxRounds = max(1, min(8, (int)($input['max_rounds'] ?? 5)));
    $queryCount = max(0, (int)($input['query_count'] ?? 0));
    $maxQueries = max(1, min(40, (int)($input['max_queries'] ?? 20)));
    $errors = array_slice(is_array($input['errors'] ?? null) ? $input['errors'] : [], 0, 30);

    $uncovered = array_values(array_unique(array_filter(array_map('strval', (array)($quality['uncovered_aspects'] ?? [])))));
    $gate = (string)($quality['quality_gate']['status'] ?? 'needs_more_evidence');
    $sourceCount = count($sources);
    $validSourceCount = 0;
    $domains = [];
    foreach ($sources as $source) {
        if (!is_array($source)) continue;
        $url = trim((string)($source['url'] ?? $source['source_url'] ?? ''));
        if (filter_var($url, FILTER_VALIDATE_URL) === false) continue;
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host !== '') { $validSourceCount++; $domains[$host] = true; }
    }
    $claimCount = count($claims);
    $conflicts = 0;
    foreach ((array)($quality['claim_coverage'] ?? []) as $claim) if (is_array($claim) && !empty($claim['assessment_conflict'])) $conflicts++;
    $missingEvidence = 0;
    foreach ((array)($quality['claim_coverage'] ?? []) as $claim) {
        if (is_array($claim) && (($claim['coverage'] ?? '') !== 'has_linked_evidence' || !empty($claim['source_ids_missing']))) $missingEvidence++;
    }

    $budgetExhausted = $round >= $maxRounds || $queryCount >= $maxQueries;
    $hardBlockers = [];
    if ($sourceCount === 0) $hardBlockers[] = 'no_sources';
    if ($validSourceCount === 0 && $sourceCount > 0) $hardBlockers[] = 'no_valid_source_urls';
    if ($uncovered) $hardBlockers[] = 'uncovered_required_aspects';
    if ($missingEvidence > 0) $hardBlockers[] = 'claims_missing_linked_evidence';
    if ($conflicts > 0) $hardBlockers[] = 'conflicting_claim_assessments_need_resolution';
    if ($errors) $hardBlockers[] = 'retrieval_errors_present';

    if ($gate === 'research_completeness_sufficient_for_draft' && !$hardBlockers) {
        $status = 'ready_for_synthesis';
        $decision = 'stop';
        $reason = 'The declared structural criteria are satisfied. A draft can be synthesized, but this does not mean the facts have been verified.';
    } elseif ($budgetExhausted) {
        $status = 'budget_exhausted_with_gaps';
        $decision = 'stop_with_limitations';
        $reason = 'The round or query limit has been reached. Disclose remaining gaps instead of continuing indefinitely.';
    } else {
        $status = 'more_research_recommended';
        $decision = 'continue';
        $reason = 'Structural gaps or conflicts remain. The Agent should prioritize additional searches.';
    }

    $intents = [];
    foreach ($uncovered as $aspect) $intents[] = ['type'=>'coverage_gap','aspect'=>$aspect,'objective'=>'Find direct sources for the uncovered aspect'];
    if ($conflicts > 0) $intents[] = ['type'=>'counter_evidence','objective'=>'Find independent evidence that explains or adjudicates conflicting assessments'];
    if ($missingEvidence > 0) $intents[] = ['type'=>'citation_repair','objective'=>'Find sources or evidence that can be linked directly to claims with insufficient support'];
    if (!$intents && $errors) $intents[] = ['type'=>'retrieval_recovery','objective'=>'Try alternative queries to recover from source collection errors'];

    return [
        'completion_schema'=>'lac-web-research-completion.v1',
        'status'=>$status,
        'decision'=>$decision,
        'reason'=>$reason,
        'budget'=>['round_number'=>$round,'max_rounds'=>$maxRounds,'query_count'=>$queryCount,'max_queries'=>$maxQueries,'exhausted'=>$budgetExhausted],
        'coverage'=>['required_aspects'=>$required,'uncovered_aspects'=>$uncovered],
        'evidence_diagnostics'=>['source_count'=>$sourceCount,'valid_source_url_count'=>$validSourceCount,'distinct_host_count'=>count($domains),'claim_count'=>$claimCount,'claims_missing_evidence'=>$missingEvidence,'assessment_conflict_count'=>$conflicts,'retrieval_error_count'=>count($errors)],
        'next_cycle_intents'=>$decision === 'continue' ? array_slice($intents, 0, 12) : [],
        'agent_policy'=>[
            'agent_must_author_queries'=>true,
            'avoid_repeating_prior_queries'=>true,
            'prioritize_primary_sources_and_counter_evidence'=>true,
            'stop_when_ready_or_budget_exhausted'=>true,
            'report_unresolved_gaps'=>true,
            'semantic_entailment_verified'=>false,
            'truth_verified'=>false,
        ],
        'limitations'=>['This completion gate uses the supplied structural signals and assessments. It is not an independent semantic evaluator.','Different hostnames do not prove that sources are independent in ownership or data.','Next-round intents are guidance, not queries that have already been generated or executed.'],
        'truth_verified'=>false,
        'semantic_entailment_verified'=>false,
    ];
}
