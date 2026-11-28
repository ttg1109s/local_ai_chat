<?php
declare(strict_types=1);

/**
 * Evidence audit primitives. These checks validate provenance and textual overlap,
 * not the truth of a claim or the semantic entailment of a source.
 */
function lac_web_verify_evidence(array $claims): array {
    $out = [];
    foreach (array_slice($claims, 0, 30) as $index => $claim) {
        if (!is_array($claim)) continue;
        $claimText = trim((string)($claim['text'] ?? ''));
        if ($claimText === '') continue;
        $claimTokens = lac_web_verify_tokens($claimText);
        $evidenceOut = [];
        $domains = [];
        foreach (array_slice((array)($claim['evidence'] ?? []), 0, 20) as $item) {
            if (!is_array($item)) continue;
            $url = trim((string)($item['url'] ?? ''));
            $sourceId = trim((string)($item['source_id'] ?? ''));
            $body = (string)($item['text'] ?? $item['excerpt'] ?? '');
            $quote = trim((string)($item['quote'] ?? ''));
            $validUrl = filter_var($url, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true);
            $domain = $validUrl ? strtolower((string)parse_url($url, PHP_URL_HOST)) : '';
            if ($domain !== '') $domains[$domain] = true;
            $bodyTokens = lac_web_verify_tokens($body);
            $matched = $claimTokens ? count(array_intersect($claimTokens, $bodyTokens)) : 0;
            $coverage = $claimTokens ? round($matched / count($claimTokens), 3) : 0.0;
            $quotePresent = $quote !== '' && $body !== '' && (function_exists('mb_stripos') ? mb_stripos($body, $quote, 0, 'UTF-8') !== false : stripos($body, $quote) !== false);
            $status = $quotePresent ? 'quote_exactly_found' : ($coverage >= 0.55 ? 'candidate_textual_overlap' : 'weak_or_no_textual_match');
            $evidenceOut[] = [
                'source_id' => $sourceId !== '' ? $sourceId : null,
                'title' => trim((string)($item['title'] ?? '')),
                'url' => $validUrl ? $url : null,
                'url_valid' => $validUrl,
                'domain' => $domain !== '' ? $domain : null,
                'source_type' => in_array(($item['source_type'] ?? ''), ['primary','secondary','tertiary'], true) ? $item['source_type'] : 'unknown',
                'published_at' => $item['published_at'] ?? null,
                'textual_coverage' => $coverage,
                'quote' => $quote !== '' ? $quote : null,
                'quote_exactly_found' => $quotePresent,
                'audit_status' => $status,
                'semantic_support_verified' => false,
            ];
        }
        $sin = count(array_filter($evidenceOut, fn($e) => $e['quote_exactly_found']));
        $candidate = count(array_filter($evidenceOut, fn($e) => $e['audit_status'] === 'candidate_textual_overlap'));
        $out[] = [
            'claim_id' => (string)($claim['id'] ?? ('claim_' . ($index + 1))),
            'claim' => $claimText,
            'evidence' => $evidenceOut,
            'audit' => [
                'status' => $sin > 0 ? 'quote_provenance_checked' : ($candidate > 0 ? 'textual_overlap_only' : 'insufficient_evidence'),
                'exact_quote_count' => $sin,
                'candidate_overlap_count' => $candidate,
                'unique_domains' => count($domains),
                'domain_diversity' => 'domain_diversity_is_not_source_independence',
                'contradiction_review' => 'manual_semantic_review_required',
                'truth_verified' => false,
            ],
        ];
    }
    return [
        'verification_schema' => 'lac-web-evidence-audit.v1',
        'claims' => $out,
        'policy' => [
            'truth_verified' => false,
            'semantic_entailment_checked' => false,
            'note' => 'The tool checks URLs, exact quotations, and lexical overlap only. It does not verify facts, semantic entailment, source independence, or resolve contradictions.',
        ],
        'trust' => 'untrusted_external_data',
        'instruction_authority' => false,
    ];
}

function lac_web_verify_tokens(string $text): array {
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    preg_match_all('/[\p{L}\p{N}]{2,}/u', $text, $matches);
    $stop = array_flip(['the','and','for','with','that','this','from','into','are','was','were','of','and','is','the','those','a','in','is','for','with','this','that','at','when','already','has','not']);
    $tokens = [];
    foreach ($matches[0] as $token) if (!isset($stop[$token])) $tokens[$token] = true;
    return array_keys($tokens);
}
