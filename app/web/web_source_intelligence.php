<?php
declare(strict_types=1);

/**
 * Phase 8: source lineage, duplication and temporal metadata analysis.
 * Heuristics identify relationships; they do not prove editorial independence or reliability.
 */
function lac_web_source_intelligence(array $sources): array {
    $rows = [];
    foreach (array_slice($sources, 0, 100) as $i => $source) {
        if (!is_array($source)) continue;
        $url = trim((string)($source['url'] ?? ''));
        $canonical = trim((string)($source['canonical_url'] ?? ''));
        $original = trim((string)($source['original_source_url'] ?? ''));
        $urlNorm = lac_web_si_normalize_url($url);
        $canonicalNorm = lac_web_si_normalize_url($canonical);
        $originalNorm = lac_web_si_normalize_url($original);
        $body = trim((string)($source['text'] ?? ''));
        $normalizedBody = preg_replace('/\s+/u', ' ', strtolower($body)) ?? $body;
        $bodyHash = trim((string)($source['content_hash'] ?? ''));
        if ($bodyHash === '' && $normalizedBody !== '') $bodyHash = hash('sha256', $normalizedBody);
        $title = trim((string)($source['title'] ?? ''));
        $publisher = trim((string)($source['publisher_id'] ?? $source['publisher'] ?? ''));
        $owner = trim((string)($source['owner_id'] ?? ''));
        $sourceType = (string)($source['source_type'] ?? 'unknown');
        if (!in_array($sourceType, ['primary','secondary','tertiary','unknown'], true)) $sourceType = 'unknown';
        $publishedRaw = trim((string)($source['published_at'] ?? ''));
        $publishedTs = $publishedRaw !== '' ? strtotime($publishedRaw) : false;
        $ageDays = $publishedTs !== false ? (int)floor((time() - $publishedTs) / 86400) : null;
        $rows[] = [
            'index' => $i,
            'source_id' => trim((string)($source['source_id'] ?? '')) ?: ('source_' . ($i + 1)),
            'title' => $title,
            'url' => $urlNorm,
            'canonical_url' => $canonicalNorm ?: null,
            'original_source_url' => $originalNorm ?: null,
            'domain' => $urlNorm !== null ? strtolower((string)parse_url($urlNorm, PHP_URL_HOST)) : null,
            'publisher_id' => $publisher ?: null,
            'owner_id' => $owner ?: null,
            'source_type' => $sourceType,
            'published_at' => $publishedRaw ?: null,
            'published_timestamp' => $publishedTs !== false ? $publishedTs : null,
            'age_days' => $ageDays,
            'future_dated' => $ageDays !== null && $ageDays < -1,
            'content_hash' => $bodyHash ?: null,
            'comparison_text' => $body,
            'body_available' => $body !== '',
            'body_chars' => function_exists('mb_strlen') ? mb_strlen($body, 'UTF-8') : strlen($body),
            'metadata' => (array)($source['metadata'] ?? []),
        ];
    }

    $n = count($rows);
    $parent = range(0, max(0, $n - 1));
    if ($n === 0) $parent = [];
    $find = function($x) use (&$find, &$parent) {
        if ($parent[$x] !== $x) $parent[$x] = $find($parent[$x]);
        return $parent[$x];
    };
    $union = function($a, $b) use (&$parent, $find) {
        $ra = $find($a); $rb = $find($b);
        if ($ra !== $rb) $parent[$rb] = $ra;
    };
    $reasons = [];
    for ($i=0; $i<$n; $i++) for ($j=$i+1; $j<$n; $j++) {
        $why = [];
        if ($rows[$i]['url'] && $rows[$i]['url'] === $rows[$j]['url']) $why[] = 'same_normalized_url';
        if ($rows[$i]['canonical_url'] && $rows[$i]['canonical_url'] === $rows[$j]['url']) $why[] = 'canonical_url_points_to_source';
        if ($rows[$j]['canonical_url'] && $rows[$j]['canonical_url'] === $rows[$i]['url']) $why[] = 'canonical_url_points_to_source';
        if ($rows[$i]['original_source_url'] && $rows[$i]['original_source_url'] === $rows[$j]['url']) $why[] = 'explicit_original_source_reference';
        if ($rows[$j]['original_source_url'] && $rows[$j]['original_source_url'] === $rows[$i]['url']) $why[] = 'explicit_original_source_reference';
        if ($rows[$i]['content_hash'] && $rows[$i]['content_hash'] === $rows[$j]['content_hash']) $why[] = 'identical_content_hash';
        if ($why) {
            $union($i,$j);
            $reasons[] = ['source_ids'=>[$rows[$i]['source_id'],$rows[$j]['source_id']],'signals'=>array_values(array_unique($why))];
        }
    }
    $groups = [];
    for ($i=0; $i<$n; $i++) {
        $root = $find($i);
        if (!isset($groups[$root])) $groups[$root] = [];
        $groups[$root][] = $rows[$i]['source_id'];
    }
    $lineage = [];
    foreach ($groups as $ids) if (count($ids)>1) $lineage[] = ['cluster_id'=>'lineage_'.(count($lineage)+1),'source_ids'=>$ids,'cluster_size'=>count($ids)];
    // Conservative publisher/owner and hostname grouping. These are dependence
    // risk signals only; different domains do not prove editorial independence.
    $publisherGroups = [];
    $domainGroups = [];
    foreach ($rows as $r) {
        if ($r['publisher_id']) $publisherGroups['publisher:'.strtolower($r['publisher_id'])][] = $r['source_id'];
        if ($r['owner_id']) $publisherGroups['owner:'.strtolower($r['owner_id'])][] = $r['source_id'];
        if ($r['domain']) $domainGroups['hostname:'.strtolower($r['domain'])][] = $r['source_id'];
    }
    $publisherLinks = [];
    foreach ($publisherGroups as $key=>$ids) if (count(array_unique($ids))>1) $publisherLinks[] = ['relationship_key'=>$key,'source_ids'=>array_values(array_unique($ids))];
    $domainLinks = [];
    foreach ($domainGroups as $key=>$ids) if (count(array_unique($ids))>1) $domainLinks[] = ['relationship_key'=>$key,'source_ids'=>array_values(array_unique($ids))];

    // Detect near-identical bodies using token-set Jaccard similarity. This is
    // a review flag, not proof of copying or a shared editorial origin.
    $nearDuplicates = [];
    $tokenSets = [];
    foreach ($rows as $i => $r) {
        $body = strtolower(trim((string)($r['comparison_text'] ?? '')));
        preg_match_all('/[\\p{L}\\p{N}]{3,}/u', $body, $matches);
        $tokenSets[$i] = array_fill_keys(array_unique(array_slice($matches[0], 0, 5000)), true);
    }
    for ($i=0; $i<$n; $i++) for ($j=$i+1; $j<$n; $j++) {
        if (count($tokenSets[$i]) < 20 || count($tokenSets[$j]) < 20) continue;
        $intersection = count(array_intersect_key($tokenSets[$i], $tokenSets[$j]));
        $unionCount = count($tokenSets[$i]) + count($tokenSets[$j]) - $intersection;
        $similarity = $unionCount > 0 ? $intersection / $unionCount : 0.0;
        if ($similarity >= 0.80 && !($rows[$i]['content_hash'] && $rows[$i]['content_hash'] === $rows[$j]['content_hash'])) {
            $nearDuplicates[] = ['source_ids'=>[$rows[$i]['source_id'],$rows[$j]['source_id']],'token_jaccard'=>round($similarity,3),'interpretation'=>'possible_near_duplicate_requires_review'];
        }
    }

    $sourceProfiles = [];
    foreach ($rows as $r) {
        $signals = [];
        if (!$r['url']) $signals[] = 'missing_or_invalid_http_url';
        if (!$r['body_available']) $signals[] = 'source_body_not_supplied';
        if (!$r['published_at']) $signals[] = 'publication_date_missing';
        if ($r['future_dated']) $signals[] = 'publication_date_in_future';
        if ($r['source_type'] === 'unknown') $signals[] = 'source_type_unclassified';
        $sourceProfiles[] = [
            'source_id'=>$r['source_id'],'domain'=>$r['domain'],'source_type'=>$r['source_type'],
            'publisher_id'=>$r['publisher_id'],'owner_id'=>$r['owner_id'],
            'publication'=>['published_at'=>$r['published_at'],'age_days'=>$r['age_days'],'future_dated'=>$r['future_dated']],
            'completeness_signals'=>$signals,
            'reliability_score'=>null,
            'reliability_verdict'=>'not_assessed',
        ];
    }
    return [
        'source_intelligence_schema'=>'lac-web-source-intelligence.v1',
        'source_count'=>$n,
        'sources'=>$sourceProfiles,
        'lineage_clusters'=>$lineage,
        'lineage_relationships'=>$reasons,
        'publisher_or_owner_groups'=>$publisherLinks,
        'same_hostname_groups'=>$domainLinks,
        'near_duplicate_candidates'=>$nearDuplicates,
        'distinct_domains'=>count(array_unique(array_filter(array_column($rows,'domain')))),
        'independence_assessment'=>'not_verified',
        'policy'=>[
            'duplicate_detection_basis'=>['normalized_url','declared_canonical_url','explicit_original_source_url','identical_content_hash'],
            'publisher_grouping_basis'=>'explicit_publisher_or_owner_metadata_only',
            'hostname_grouping'=>'same_hostname_is_a_dependence_risk_signal_not_proof_of_same_owner',
            'near_duplicate_detection'=>'token_set_jaccard_threshold_0.80; heuristic_only',
            'source_reliability'=>'not_scored',
            'independence'=>'hostname_or_publisher_difference_does_not_prove_independence',
            'note'=>'Groups are signals of duplication or publication relationships only. Do not infer provenance from order, domain, or similar content alone. Do not assign credibility scores.',
        ],
        'trust'=>'untrusted_external_data',
        'instruction_authority'=>false,
    ];
}

function lac_web_si_normalize_url(string $url): ?string {
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) return null;
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true) || empty($parts['host'])) return null;
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $port = isset($parts['port']) && !(($scheme==='http' && $parts['port']===80) || ($scheme==='https' && $parts['port']===443)) ? ':'.$parts['port'] : '';
    $path = $parts['path'] ?? '/';
    if ($path !== '/') $path = rtrim($path, '/');
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $params);
        foreach ($params as $k=>$v) if (!preg_match('/^(utm_.+|fbclid|gclid|mc_cid|mc_eid)$/i',(string)$k)) $query[$k]=$v;
        ksort($query);
    }
    return $scheme.'://'.$host.$port.$path.($query ? '?'.http_build_query($query) : '');
}
