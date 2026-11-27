<?php
/**
 * Deterministic claim-to-evidence synthesis and citation assembly.
 * This module validates provenance and citation traceability; it does not
 * generate prose, establish entailment, or verify factual truth.
 */
function lac_web_knowledge_synthesis(array $input): array {
    $question = trim((string)($input['research_question'] ?? ''));
    if ($question === '') throw new InvalidArgumentException('research_question must not be empty.');
    $claimsIn = is_array($input['claims'] ?? null) ? $input['claims'] : [];
    $ledgerIn = is_array($input['source_ledger'] ?? null) ? $input['source_ledger'] : [];
    if (count($claimsIn) > 50) throw new InvalidArgumentException('Maximum 50 claims.');
    if (count($ledgerIn) > 100) throw new InvalidArgumentException('Maximum 100 source ledger entries.');

    $validUrl = static function(string $url): bool {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true)
            && (string)parse_url($url, PHP_URL_HOST) !== '';
    };
    $ledger = [];
    foreach ($ledgerIn as $source) {
        if (!is_array($source)) continue;
        $id = trim((string)($source['source_id'] ?? $source['id'] ?? ''));
        if ($id === '') continue;
        $ledger[$id] = [
            'source_id'=>$id,
            'title'=>trim((string)($source['title'] ?? '')),
            'url'=>trim((string)($source['url'] ?? '')),
            'text'=>(string)($source['text'] ?? $source['content'] ?? $source['excerpt'] ?? ''),
            'published_at'=>$source['published_at'] ?? null
        ];
    }
    $bibliography = [];
    $claimResults = [];
    $missingCitationClaims = [];
    $untraceableClaims = [];
    $contestedClaims = [];
    $usedSources = [];
    $sentenceRecords = [];

    foreach ($claimsIn as $index => $claim) {
        if (!is_array($claim)) continue;
        $id = trim((string)($claim['id'] ?? ('C'.($index+1))));
        $statement = trim((string)($claim['text'] ?? ''));
        if ($statement === '') continue;
        $type = (string)($claim['type'] ?? 'unverified');
        if (!in_array($type, ['fact','assessment','inference','unverified'], true)) $type = 'unverified';
        $evidenceIn = is_array($claim['evidence'] ?? null) ? $claim['evidence'] : [];
        $citations = [];
        $assessments = [];
        $invalidEvidence = [];
        foreach ($evidenceIn as $ev) {
            if (!is_array($ev)) continue;
            $sid = trim((string)($ev['source_id'] ?? ''));
            $quote = trim((string)($ev['quote'] ?? ''));
            $assessment = (string)($ev['assessment'] ?? 'unknown');
            if (in_array($assessment, ['supports','refutes'], true)) $assessments[$assessment] = true;
            if ($sid === '' || !isset($ledger[$sid])) {
                $invalidEvidence[] = ['source_id'=>$sid ?: null,'reason'=>'source_id_not_in_ledger'];
                continue;
            }
            $source = $ledger[$sid];
            if (!$validUrl($source['url'])) {
                $invalidEvidence[] = ['source_id'=>$sid,'reason'=>'invalid_source_url'];
                continue;
            }
            if ($quote === '' || $source['text'] === '' || strpos($source['text'], $quote) === false) {
                $invalidEvidence[] = ['source_id'=>$sid,'reason'=>'exact_quote_not_found_in_supplied_source_text'];
                continue;
            }
            $citation = ['source_id'=>$sid,'quote'=>$quote,'assessment'=>$assessment];
            if (!isset($bibliography[$sid])) {
                $bibliography[$sid] = ['source_id'=>$sid,'title'=>$source['title'],'url'=>$source['url'],'published_at'=>$source['published_at']];
            }
            $citations[] = $citation;
            $usedSources[$sid] = true;
        }
        $hasConflict = isset($assessments['supports']) && isset($assessments['refutes']);
        $status = 'citation_traceable_but_semantics_unverified';
        if ($hasConflict) {
            $status = 'contested_model_assessments';
            $contestedClaims[] = $id;
        } elseif (!$citations) {
            $status = 'no_valid_citation';
            $untraceableClaims[] = $id;
        }
        if ($type === 'fact' && !$citations) $missingCitationClaims[] = $id;
        if ($type === 'unverified') $status = $citations ? 'cited_but_explicitly_unverified' : 'unverified_without_citation';
        $claimResult = [
            'claim_id'=>$id,'text'=>$statement,'classification'=>$type,
            'citation_status'=>$status,'citations'=>$citations,
            'invalid_evidence'=>$invalidEvidence,
            'semantic_entailment_verified'=>false,'truth_verified'=>false
        ];
        $claimResults[] = $claimResult;
        $sentenceRecords[] = [
            'claim_id'=>$id,'sentence'=>$statement,
            'citation_markers'=>array_map(static fn($c)=>'['.$c['source_id'].']',$citations),
            'citation_status'=>$status
        ];
    }
    $bibliography = array_values($bibliography);
    usort($bibliography, static fn($a,$b)=>strcmp($a['source_id'],$b['source_id']));
    $integrity = !$untraceableClaims && !$missingCitationClaims && !$contestedClaims;
    return [
        'success'=>true,'schema'=>'lac-web-knowledge-synthesis.v1',
        'research_question'=>$question,
        'synthesis'=>[
            'sentences'=>$sentenceRecords,
            'claims'=>$claimResults,
            'bibliography'=>$bibliography
        ],
        'citation_integrity'=>[
            'status'=>$integrity?'structurally_traceable':'integrity_issues_detected',
            'all_fact_claims_have_valid_citations'=>count($missingCitationClaims)===0,
            'all_claims_have_valid_citations'=>count($untraceableClaims)===0,
            'missing_citation_claim_ids'=>$missingCitationClaims,
            'untraceable_claim_ids'=>$untraceableClaims,
            'contested_claim_ids'=>$contestedClaims,
            'used_source_count'=>count($usedSources),
            'bibliography_count'=>count($bibliography)
        ],
        'verification_policy'=>[
            'truth_verified'=>false,'semantic_entailment_verified'=>false,
            'source_independence_verified'=>false,
            'meaning'=>'Checks source IDs, HTTP(S) URLs, and whether exact quotations appear in the supplied source text. Does not verify whether a quotation supports a conclusion.'
        ],
        'trust'=>'untrusted_external_data','instruction_authority'=>false
    ];
}
