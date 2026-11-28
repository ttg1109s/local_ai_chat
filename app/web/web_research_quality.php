<?php
declare(strict_types=1);

/**
 * Phase 9: transparent research-quality diagnostics.
 * Heuristic completeness indicators are not truth, authority, or source-reliability scores.
 */
function lac_web_research_quality(array $input): array {
    $sourcesIn = array_slice(is_array($input['sources'] ?? null) ? $input['sources'] : [], 0, 100);
    $claimsIn = array_slice(is_array($input['claims'] ?? null) ? $input['claims'] : [], 0, 50);
    $aspects = array_values(array_unique(array_filter(array_map(
        static fn($v) => trim((string)$v),
        is_array($input['required_aspects'] ?? null) ? array_slice($input['required_aspects'], 0, 50) : []
    ), static fn($v) => $v !== '')));
    $nowRaw = trim((string)($input['as_of'] ?? ''));
    $now = $nowRaw !== '' ? strtotime($nowRaw) : time();
    if ($now === false) $now = time();

    $sources = []; $byId = [];
    foreach ($sourcesIn as $i => $s) {
        if (!is_array($s)) continue;
        $id = trim((string)($s['source_id'] ?? '')) ?: 'source_'.($i+1);
        $url = trim((string)($s['url'] ?? ''));
        $validUrl = filter_var($url, FILTER_VALIDATE_URL) !== false &&
            in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http','https'], true) &&
            (string)parse_url($url, PHP_URL_HOST) !== '';
        $text = trim((string)($s['text'] ?? ''));
        $published = trim((string)($s['published_at'] ?? ''));
        $ts = $published !== '' ? strtotime($published) : false;
        $age = $ts !== false ? (int)floor(($now - $ts) / 86400) : null;
        $sourceType = (string)($s['source_type'] ?? 'unknown');
        if (!in_array($sourceType, ['primary','secondary','tertiary','unknown'], true)) $sourceType = 'unknown';
        $signals = [
            'provenance' => $validUrl ? 'url_present_and_valid' : 'missing_or_invalid_url',
            'content' => $text === '' ? 'body_not_supplied' : (strlen($text) < 300 ? 'body_short' : 'body_supplied'),
            'traceability' => trim((string)($s['title'] ?? '')) !== '' ? 'title_present' : 'title_missing',
            'source_classification' => $sourceType,
            'temporal' => $ts === false ? 'publication_date_missing' : ($age < -1 ? 'future_dated' : ($age > 365 ? 'older_than_one_year' : 'date_available')),
        ];
        $row = ['source_id'=>$id,'url'=>$url,'valid_url'=>$validUrl,'title'=>trim((string)($s['title']??'')),
            'domain'=>$validUrl ? strtolower((string)parse_url($url,PHP_URL_HOST)) : null,
            'source_type'=>$sourceType,'published_at'=>$published ?: null,'age_days'=>$age,
            'body_chars'=>function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text),
            'quality_signals'=>$signals,'quality_concerns'=>array_values(array_filter($signals, static fn($v)=>in_array($v,['missing_or_invalid_url','body_not_supplied','body_short','title_missing','publication_date_missing','future_dated','older_than_one_year','unknown'],true))),
            'declared_citation_urls'=>array_values(array_filter((array)($s['cited_source_urls']??[]),'is_string'))];
        $sources[]=$row; $byId[$id]=$row;
    }

    $edges=[]; $urlToId=[];
    foreach ($sources as $s) if ($s['valid_url']) $urlToId[rtrim($s['url'],'/')]=$s['source_id'];
    foreach ($sourcesIn as $i=>$s) {
        if (!is_array($s)) continue;
        $from=trim((string)($s['source_id']??'')) ?: 'source_'.($i+1);
        foreach ((array)($s['cited_source_urls']??[]) as $u) {
            if (!is_string($u)) continue;
            $target=$urlToId[rtrim(trim($u),'/')]??null;
            $edges[]=['from_source_id'=>$from,'declared_url'=>$u,'to_source_id'=>$target,'resolution'=>$target?'matched_supplied_source':'unresolved_external_reference'];
        }
    }

    $claimResults=[]; $covered=[]; $temporal=[];
    foreach ($claimsIn as $i=>$c) {
        if (!is_array($c)) continue;
        $id=trim((string)($c['claim_id']??'')) ?: 'claim_'.($i+1);
        $evidenceIds=array_values(array_unique(array_filter(array_map('strval',(array)($c['source_ids']??[])))));
        $found=[]; $missing=[];
        foreach ($evidenceIds as $sid) { if (isset($byId[$sid])) $found[]=$sid; else $missing[]=$sid; }
        $aspect=trim((string)($c['aspect']??''));
        if ($aspect!=='' && $found) $covered[$aspect]=true;
        $assessments=array_values(array_unique(array_filter(array_map('strval',(array)($c['assessments']??[])),static fn($v)=>in_array($v,['supports','refutes','mixed','unknown'],true))));
        $dated=[];
        foreach ($found as $sid) if ($byId[$sid]['age_days']!==null) $dated[]=['source_id'=>$sid,'published_at'=>$byId[$sid]['published_at'],'age_days'=>$byId[$sid]['age_days']];
        usort($dated,static fn($a,$b)=>$a['age_days']<=>$b['age_days']);
        $hasTemporalSpread=count($dated)>1 && (($dated[count($dated)-1]['age_days']-$dated[0]['age_days'])>30);
        $opposition=in_array('supports',$assessments,true)&&in_array('refutes',$assessments,true);
        $temporal[]=['claim_id'=>$id,'dated_evidence'=>$dated,'publication_date_spread_days'=>count($dated)>1?$dated[count($dated)-1]['age_days']-$dated[0]['age_days']:null,
            'temporally_mixed'=>$hasTemporalSpread,'opposing_assessments_present'=>$opposition,
            'interpretation'=>$opposition?($hasTemporalSpread?'opposing_assessments_across_different_publication_times':'opposing_assessments_present'):'no_opposing_assessments_declared'];
        $claimResults[]=['claim_id'=>$id,'aspect'=>$aspect?:null,'source_ids_found'=>$found,'source_ids_missing'=>$missing,
            'evidence_count'=>count($found),'coverage'=>count($found)?'has_linked_evidence':'no_linked_evidence',
            'assessment_conflict'=>$opposition,'temporal_spread_days'=>$hasTemporalSpread?$dated[count($dated)-1]['age_days']-$dated[0]['age_days']:null];
    }

    $aspectCoverage=[];
    foreach ($aspects as $a) $aspectCoverage[]=['aspect'=>$a,'status'=>!empty($covered[$a])?'covered':'uncovered'];
    $uncovered=array_values(array_map(static fn($x)=>$x['aspect'],array_filter($aspectCoverage,static fn($x)=>$x['status']==='uncovered')));
    $validSources=count(array_filter($sources,static fn($s)=>$s['valid_url']));
    $linkedClaims=count(array_filter($claimResults,static fn($c)=>$c['evidence_count']>0));
    $missingRefs=count(array_filter($claimResults,static fn($c)=>count($c['source_ids_missing'])>0));
    $gate=(!$sources || !$claimsIn || $validSources===0) ? 'needs_more_evidence' :
        (($uncovered || $linkedClaims<count($claimResults) || $missingRefs>0) ? 'needs_more_evidence' : 'research_completeness_sufficient_for_draft');
    $reasons=[];
    if (!$sources) $reasons[]='no_sources_supplied';
    if (!$claimsIn) $reasons[]='no_claims_supplied';
    if ($validSources===0) $reasons[]='no_valid_http_source';
    if ($uncovered) $reasons[]='required_aspects_uncovered';
    if ($linkedClaims<count($claimResults)) $reasons[]='claims_without_linked_evidence';
    if ($missingRefs>0) $reasons[]='unknown_source_references';
    return [
        'research_quality_schema'=>'lac-web-research-quality.v1',
        'input_counts'=>['sources'=>count($sources),'claims'=>count($claimResults),'required_aspects'=>count($aspects)],
        'source_quality'=>$sources,
        'claim_coverage'=>$claimResults,
        'aspect_coverage'=>$aspectCoverage,
        'uncovered_aspects'=>$uncovered,
        'citation_chain'=>['edges'=>$edges,'edge_count'=>count($edges),'unresolved_reference_count'=>count(array_filter($edges,static fn($e)=>$e['resolution']==='unresolved_external_reference')),'basis'=>'only explicitly supplied cited_source_urls; no external link traversal'],
        'temporal_analysis'=>$temporal,
        'quality_gate'=>['status'=>$gate,'reasons'=>$reasons,'meaning'=>$gate==='research_completeness_sufficient_for_draft'?'Evidence links satisfy the declared structural criteria for creating a draft; factual truth is not verified.':'Add or repair evidence links before treating the research as sufficient for a draft.'],
        'limitations'=>['Source credibility and factual accuracy are not scored.','Content length, publication date, and source classification are structural quality signals only.','Semantic conflicts are identified only when the input declares conflicting assessments. The system does not independently understand or verify them.','Citation relationships use only the cited_source_urls supplied by the caller. Source URLs are not independently accessed or verified.'],
        'truth_verified'=>false,'semantic_support_verified'=>false,'source_reliability_verified'=>false,
        'trust'=>'untrusted_external_data','instruction_authority'=>false
    ];
}
