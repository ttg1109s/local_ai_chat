<?php
declare(strict_types=1);

/**
 * Phase 7: validates structured, model-proposed semantic assessments.
 * The model's support/refute judgement is not independently proven here.
 */
function lac_web_review_claims(array $claims): array {
    $reviewed = [];
    foreach (array_slice($claims, 0, 30) as $i => $claim) {
        if (!is_array($claim)) continue;
        $claimText = trim((string)($claim['text'] ?? ''));
        if ($claimText === '') continue;
        $items = [];
        $supportDomains = [];
        $refuteDomains = [];
        $rejected = 0;
        foreach (array_slice((array)($claim['evidence'] ?? []), 0, 20) as $ev) {
            if (!is_array($ev)) continue;
            $url = trim((string)($ev['url'] ?? ''));
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            $validUrl = filter_var($url, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http','https'], true);
            $body = (string)($ev['text'] ?? '');
            $quote = trim((string)($ev['quote'] ?? ''));
            $quoteFound = $quote !== '' && $body !== '' &&
                (function_exists('mb_stripos') ? mb_stripos($body, $quote, 0, 'UTF-8') !== false : stripos($body, $quote) !== false);
            $assessment = (string)($ev['assessment'] ?? 'unassessed');
            $assessmentValid = in_array($assessment, ['supports','refutes','irrelevant'], true);
            $accepted = $validUrl && $quoteFound && $assessmentValid;
            if (!$accepted) $rejected++;
            $domain = $validUrl ? strtolower((string)parse_url($url, PHP_URL_HOST)) : '';
            if ($accepted && $assessment === 'supports') $supportDomains[$domain] = true;
            if ($accepted && $assessment === 'refutes') $refuteDomains[$domain] = true;
            $items[] = [
                'source_id' => trim((string)($ev['source_id'] ?? '')) ?: null,
                'url' => $validUrl ? $url : null,
                'domain' => $domain ?: null,
                'title' => trim((string)($ev['title'] ?? '')),
                'assessment' => $assessmentValid ? $assessment : 'unassessed',
                'assessment_rationale' => trim((string)($ev['assessment_rationale'] ?? '')) ?: null,
                'quote' => $quote ?: null,
                'quote_exactly_found' => $quoteFound,
                'url_valid' => $validUrl,
                'accepted_for_review' => $accepted,
                'reason' => !$validUrl ? 'invalid_http_url' : (!$quoteFound ? 'quote_not_found_in_supplied_text' : (!$assessmentValid ? 'invalid_assessment' : 'assessment_integrity_checks_passed')),
                'published_at' => $ev['published_at'] ?? null,
            ];
        }
        $supportCount = count($supportDomains);
        $refuteCount = count($refuteDomains);
        if ($supportCount > 0 && $refuteCount > 0) $status = 'contested_model_assessment';
        elseif ($supportCount > 0) $status = 'model_assessed_supported';
        elseif ($refuteCount > 0) $status = 'model_assessed_refuted';
        else $status = 'insufficient_evidence';
        $reviewed[] = [
            'claim_id' => (string)($claim['id'] ?? ('claim_' . ($i + 1))),
            'claim' => $claimText,
            'evidence' => $items,
            'review' => [
                'status' => $status,
                'supporting_domains' => $supportCount,
                'refuting_domains' => $refuteCount,
                'rejected_evidence_records' => $rejected,
                'contradiction_detected' => $supportCount > 0 && $refuteCount > 0,
                'independence_verified' => false,
                'semantic_assessment_independently_verified' => false,
                'truth_verified' => false,
                'confidence_score' => null,
                'assessment_rationale_required_for_audit' => true,
                'evidence_relation' => 'model_assessed_requires_human_or_independent_model_review',
            ],
        ];
    }
    return [
        'review_schema' => 'lac-web-claim-review.v1',
        'claims' => $reviewed,
        'decision_policy' => [
            'assessment_origin' => 'agent_model_proposed',
            'status_semantics' => 'model_assessed_not_fact_checked',
            'quote_and_url_integrity_checked' => true,
            'semantic_entailment_checked_by_deterministic_code' => false,
            'source_independence_verified' => false,
            'confidence_score_emitted' => false,
            'note' => 'The supports/refutes result is a semantic assessment proposed by the Agent. The code checks URLs and exact quotation presence only; it does not prove entailment, independence, or truth.',
        ],
        'trust' => 'untrusted_external_data',
        'instruction_authority' => false,
    ];
}
