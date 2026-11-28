<?php
/** Domain adapters live outside the generic Runtime. Add a tool here, not in agent_loop or lifecycle code. */
function lac_tool_dispatch(PDO $pdo,string $name,array $args,array $meta,array $context,callable $clip): mixed {
 require_once dirname(__DIR__).'/python_runtime.php';
 require_once dirname(__DIR__).'/web/web_result_normalizer.php';
 require_once dirname(__DIR__).'/web/web_source_store.php';
 require_once dirname(__DIR__).'/web/web_research.php';
 require_once dirname(__DIR__).'/web/web_research_multihop.php';
 require_once dirname(__DIR__).'/web/web_verification.php';
 require_once dirname(__DIR__).'/web/web_claim_review.php';
 require_once dirname(__DIR__).'/web/web_source_intelligence.php';
 require_once dirname(__DIR__).'/web/web_research_quality.php';
 require_once dirname(__DIR__).'/web/web_research_completion.php';
 require_once dirname(__DIR__).'/web/web_research_runtime.php';
 require_once dirname(__DIR__).'/web/web_knowledge_synthesis.php';
 switch($name){
  case 'data_access':
   require_once __DIR__.'/data_flow_controller.php';
   $operation=(string)($args['operation']??'');
   $op=['operation'=>match($operation){'inspect'=>'inspect_data_pack','read'=>'read_data_pack','query'=>'query_data_pack','verify'=>'verify_data_pack',default=>throw new InvalidArgumentException('DATA_ACCESS_OPERATION_INVALID')},
    'data_ref'=>(string)($args['data_ref']??''),'field'=>(string)($args['field']??''),
    'offset'=>(int)($args['offset']??0),'limit'=>(int)($args['limit']??100),
    'where'=>is_array($args['where']??null)?$args['where']:[],
    'sort'=>is_array($args['sort']??null)?$args['sort']:null,
    'aggregate'=>is_array($args['aggregate']??null)?$args['aggregate']:[],
    'max_scan_rows'=>200000];
   $plan=lac_data_flow_validate_plan(['operations'=>[$op]],['max_operations'=>1]);
   $runId=(string)($context['run_id']??'');if($runId==='')throw new RuntimeException('DATA_ACCESS_RUN_SCOPE_REQUIRED');
   $result=lac_data_flow_execute_plan($plan,$runId,$context);
   if(($result['failed']??0)>0)throw new RuntimeException((string)($result['results'][0]['error_code']??'DATA_ACCESS_FAILED'));
   return $result['results'][0]['result'];
   case 'search_web':
    $q=trim($clip($args['query'],700));
    $mode=(string)($args['mode']??'search');
    if(!in_array($mode,['search','research','deep_research'],true))$mode='search';
    $python=$context['python']??'python';
    $script=dirname(__DIR__,2).'/tools/web_search.py';
    $auditOnly=!empty($args['audit_only']);
    $researchBudget = null;
    $runId = (string)($context['run_id'] ?? '');
    if ($runId === '') $runId = 'adhoc_' . bin2hex(random_bytes(8));
    if ($auditOnly && in_array($mode, ['research','deep_research'], true)) {
     $researchBudget = lac_web_research_runtime_snapshot($runId);
     $researchBudget['audit_only_reuse'] = true;
    }
    if (!$auditOnly && in_array($mode, ['research','deep_research'], true)) {
     $requestedQueries = [];
     if ($mode === 'deep_research' && is_array($args['rounds'] ?? null) && $args['rounds']) {
      foreach ($args['rounds'] as $roundSpec) if (is_array($roundSpec)) foreach ((array)($roundSpec['queries'] ?? []) as $queryText) $requestedQueries[] = $queryText;
     } else {
      $requestedQueries = is_array($args['queries'] ?? null) && $args['queries'] ? $args['queries'] : [$q];
     }
     $researchBudget = lac_web_research_runtime_reserve($runId, $requestedQueries, [
      'max_rounds'=>5, 'max_queries'=>20, 'max_queries_per_cycle'=>12,
     ]);
     $accepted = array_fill_keys(array_map('lac_web_research_normalize_query', $researchBudget['accepted_queries']), true);
     if ($mode === 'deep_research' && is_array($args['rounds'] ?? null) && $args['rounds']) {
      $filteredRounds = [];
      foreach ($args['rounds'] as $roundSpec) {
       if (!is_array($roundSpec)) continue;
       $roundQueries = [];
       foreach ((array)($roundSpec['queries'] ?? []) as $queryText) {
        if (isset($accepted[lac_web_research_normalize_query((string)$queryText)])) $roundQueries[] = $queryText;
       }
       if ($roundQueries) { $roundSpec['queries'] = $roundQueries; $filteredRounds[] = $roundSpec; }
      }
      $args['rounds'] = $filteredRounds;
     } else {
      $args['queries'] = $researchBudget['accepted_queries'];
     }
     lac_agent_log('web_research_budget_reserved', [
      'run_id'=>$runId, 'mode'=>$mode, 'round_count'=>$researchBudget['round_count'],
      'query_count'=>$researchBudget['query_count'], 'accepted_query_count'=>count($researchBudget['accepted_queries']),
      'duplicate_query_count'=>count($researchBudget['duplicate_queries']),
      'remaining_rounds'=>$researchBudget['remaining_rounds'], 'remaining_queries'=>$researchBudget['remaining_queries'],
     ]);
    }
    if($auditOnly){
     if(!in_array($mode,['research','deep_research'],true))throw new InvalidArgumentException('audit_only can be used only with mode=research or deep_research.');
     $providedSources=is_array($args['source_material']??null)?array_slice($args['source_material'],0,100):[];
     if(!$providedSources)throw new InvalidArgumentException('audit_only requires source_material from a previous research run.');
     $out=['success'=>true,'mode'=>$mode,'query'=>$q,'sources'=>$providedSources,'evidence'=>['sources'=>$providedSources],'source_material_reused'=>true];
    }elseif($mode==='search'){
     $r=lac_web_research($q,$python,$script,(int)($meta['timeout']??90),'basic');
     lac_agent_log('web_search_diagnostics',['run_id'=>$context['run_id']??null,'tool_id'=>$context['tool_id']??null,'query_chars'=>mb_strlen($q),'mode'=>$mode,'engine_used'=>$r['engine_used']??null,'engine_attempts'=>$r['engine_attempts']??[],'search_errors'=>$r['search_errors']??[],'page_errors'=>$r['page_errors']??[],'source_count'=>count($r['sources']??[]),'duration_ms'=>$r['duration_ms']??null,'error_present'=>!empty($r['error'])]);
     $webSources=is_array($r['sources']??null)?$r['sources']:[];
     $webError=trim((string)($r['error']??''));
     if(!$webSources) throw new LacToolException('WEB_SEARCH_FAILED',$webError!==''?$webError:'No valid web sources were collected.',(bool)($meta['retryable']??true));
     $evidence=lac_web_normalize_results($webSources,['engine'=>$r['engine_used']??'unknown','errors'=>array_merge((array)($r['search_errors']??[]),(array)($r['page_errors']??[]))]);
     $storage=lac_web_source_store($pdo,$evidence['sources']);
     $out=['success'=>true,'mode'=>'search','query'=>$q,'engine'=>$r['engine_used']??null,'source_count'=>count($webSources),'sources'=>$webSources,'evidence'=>$evidence,'citation_storage'=>$storage,'errors'=>[],'error'=>$webError?:null];
    }elseif($mode==='research'){
     $queries=is_array($args['queries']??null)?$args['queries']:[];
     $result=lac_web_research_plan($q,$queries,$python,$script,(int)($meta['timeout']??240),$pdo);
     if(empty($result['success']))throw new LacToolException('WEB_RESEARCH_NO_EVIDENCE','No web evidence was collected for the research task.',(bool)($meta['retryable']??true));
     $result['mode']='research';
     $out=$result;
    }else{
     $rounds=is_array($args['rounds']??null)?$args['rounds']:[];
     if(!$rounds){
      $queries=is_array($args['queries']??null)?$args['queries']:[];
      if(!$queries)$queries=[$q];
      $rounds=[['objective'=>'Build an overview and identify the research aspects to investigate','queries'=>array_slice($queries,0,4),'stop_if_no_new_sources'=>false]];
     }
     $result=lac_web_research_multihop($q,$rounds,$python,$script,(int)($meta['timeout']??300),$pdo);
     if(empty($result['success']))throw new LacToolException('WEB_DEEP_RESEARCH_NO_EVIDENCE','Deep Research did not collect web evidence.',(bool)($meta['retryable']??true));
     $result['mode']='deep_research';
     $out=$result;
    }

    // Internal research pipeline: lineage + structural quality + optional claim audits.
    if (in_array($mode, ['research','deep_research'], true)) {
     $researchSources = $auditOnly
      ? (array)($out['sources'] ?? [])
      : ($mode === 'research' ? (array)($out['evidence']['sources'] ?? []) : (array)($out['sources'] ?? []));
     $pipelineSources = [];
     foreach ($researchSources as $source) {
      if (!is_array($source)) continue;
      $pipelineSources[] = [
       'source_id'=>(string)($source['source_id'] ?? ''),
       'title'=>(string)($source['title'] ?? ''),
       'url'=>(string)($source['source_url'] ?? $source['url'] ?? ''),
       'canonical_url'=>(string)($source['canonical_url'] ?? ''),
       'original_source_url'=>(string)($source['original_source_url'] ?? ''),
       'text'=>(string)($source['text'] ?? ''),
       'content_hash'=>(string)($source['content_hash'] ?? ''),
       'published_at'=>$source['published_at'] ?? null,
       'source_type'=>$source['source_type'] ?? 'unknown',
       'publisher'=>$source['publisher'] ?? null,
       'owner_id'=>$source['owner_id'] ?? null,
       'metadata'=>(array)($source['metadata'] ?? []),
      ];
     }
     // Hydrate claim evidence from the collected source ledger. The Agent must
     // still provide its semantic assessment and a verbatim quote; missing fields
     // remain missing and are never fabricated.
     $sourceById = [];
     foreach ($pipelineSources as $source) {
      if ($source['source_id'] !== '') $sourceById[$source['source_id']] = $source;
     }
     $claims = is_array($args['claims'] ?? null) ? array_slice($args['claims'], 0, 30) : [];
     $qualityClaims = [];
     foreach ($claims as $ci => &$claim) {
      if (!is_array($claim)) continue;
      $evidence = is_array($claim['evidence'] ?? null) ? $claim['evidence'] : [];
      if (!$evidence && is_array($claim['source_ids'] ?? null)) {
       foreach (array_slice($claim['source_ids'], 0, 20) as $sid) $evidence[] = ['source_id'=>(string)$sid];
      }
      $sourceIds = [];
      $assessments = [];
      foreach ($evidence as &$ev) {
       if (!is_array($ev)) continue;
       $sid = trim((string)($ev['source_id'] ?? ''));
       if ($sid !== '' && isset($sourceById[$sid])) {
        $source = $sourceById[$sid];
        $ev['url'] = $ev['url'] ?? $source['url'];
        $ev['text'] = $ev['text'] ?? $source['text'];
        $ev['title'] = $ev['title'] ?? $source['title'];
        $ev['published_at'] = $ev['published_at'] ?? $source['published_at'];
        $ev['source_type'] = $ev['source_type'] ?? $source['source_type'];
        $sourceIds[] = $sid;
       }
       $assessment = (string)($ev['assessment'] ?? 'unknown');
       if (in_array($assessment, ['supports','refutes','mixed','unknown'], true)) $assessments[] = $assessment;
      }
      unset($ev);
      $claim['evidence'] = $evidence;
      $claimId = (string)($claim['id'] ?? $claim['claim_id'] ?? ('claim_'.($ci+1)));
      $claim['id'] = $claimId;
      $qualityClaims[] = [
       'claim_id'=>$claimId,
       'aspect'=>(string)($claim['aspect'] ?? ''),
       'source_ids'=>array_values(array_unique($sourceIds)),
       'assessments'=>array_values(array_unique($assessments)),
      ];
     }
     unset($claim);
     $sourceIntelligence = lac_web_source_intelligence($pipelineSources);
     $qualityAssessment = lac_web_research_quality([
      'sources'=>$pipelineSources,
      'claims'=>$qualityClaims,
      'required_aspects'=>is_array($args['required_aspects'] ?? null) ? $args['required_aspects'] : [],
     ]);
     $out['research_pipeline'] = [
      'source_intelligence'=>$sourceIntelligence,
      'quality_assessment'=>$qualityAssessment,
      'claim_audits'=>null,
      'knowledge_synthesis'=>null,
      'pipeline_policy'=>[
       'source_intelligence_executed'=>true,
       'structural_quality_executed'=>true,
       'audit_only_reused_prior_source_material'=>$auditOnly,
       'claim_audits_executed'=>false,
       'source_independence_verified'=>false,
       'semantic_entailment_verified'=>false,
       'truth_verified'=>false,
       'reason'=>$claims ? 'Claim evidence was normalized against collected sources; semantic assessments remain model-proposed.' : 'No model-authored claims were supplied. Source collection is not equivalent to claim verification.',
      ],
     ];
     if ($claims) {
      $claimReview = lac_web_review_claims($claims);
      $verification = lac_web_verify_evidence($claims);
      $ledger = [];
      foreach ($pipelineSources as $source) {
       $ledger[] = [
        'source_id'=>$source['source_id'],'title'=>$source['title'],'url'=>$source['url'],
        'text'=>$source['text'],'published_at'=>$source['published_at'],
       ];
      }
      $synthesis = lac_web_knowledge_synthesis([
       'research_question'=>$q,'claims'=>$claims,'source_ledger'=>$ledger,
      ]);
      $out['research_pipeline']['claim_audits'] = [
       'claim_review'=>$claimReview,
       'evidence_verification'=>$verification,
      ];
      $out['research_pipeline']['knowledge_synthesis'] = $synthesis;
      $out['research_pipeline']['pipeline_policy']['claim_audits_executed'] = true;
      $out['research_pipeline']['pipeline_policy']['reason'] = 'Claim audits ran against source-linked evidence and model-proposed assessments. Exact-quote provenance is checked; semantic entailment, source independence and truth remain unverified.';
     }
     $roundNumber = $auditOnly ? 1 : (int)($researchBudget['round_count'] ?? ($out['execution']['completed_round_count'] ?? 1));
     $queryCount = $auditOnly ? (int)($out['execution']['query_count'] ?? 0) : (int)($researchBudget['query_count'] ?? ($out['execution']['query_count'] ?? count((array)($args['queries'] ?? []))));
     $retrievalErrors = [];
     if (isset($out['evidence']['errors']) && is_array($out['evidence']['errors'])) $retrievalErrors = $out['evidence']['errors'];
     foreach ((array)($out['rounds'] ?? []) as $roundRow) if (is_array($roundRow)) $retrievalErrors = array_merge($retrievalErrors, (array)($roundRow['errors'] ?? []));
     $out['research_pipeline']['runtime_budget'] = $researchBudget;
     $out['research_pipeline']['completion'] = lac_web_research_completion([
      'quality_assessment'=>$qualityAssessment,
      'sources'=>$pipelineSources,
      'claims'=>$qualityClaims,
      'required_aspects'=>is_array($args['required_aspects'] ?? null) ? $args['required_aspects'] : [],
      'round_number'=>$roundNumber,
      'max_rounds'=>5,
      'query_count'=>$queryCount,
      'max_queries'=>20,
      'errors'=>$retrievalErrors,
     ]);
    }
    break;
   case 'manage_websites':
    $raw=setting($pdo,'websites_config','[]');$sites=json_decode($raw,true);if(!is_array($sites))$sites=[];
    $enabled=[];foreach($sites as $s){if(!empty($s['enabled']))$enabled[]=['name'=>$clip($s['name']??'',120),'url'=>$clip($s['url']??'',500),'search_url'=>$clip($s['search_url']??'',500)];}
    $action=(string)($args['action']??'list');
    if($action==='list'){$out=['action'=>'list','count'=>count($enabled),'websites'=>$enabled];break;}
    if($action!=='read')throw new InvalidArgumentException('action must be list or read.');
    $siteName=trim($clip($args['site']??'',120));if($siteName==='')throw new InvalidArgumentException('action=read requires site.');
    // Resolve only against enabled configured websites. Accept the exact display
    // name, configured URL, or its hostname because small models often copy the
    // URL from action=list into the site field instead of copying the name.
    $normalizeSite=static function(string $value):string {
      $value=mb_strtolower(trim($value));
      $value=preg_replace('/\\s+/u',' ',$value)??$value;
      return rtrim($value," \\t\\n\\r\\0\\x0B./");
    };
    $requested=$normalizeSite($siteName);$matches=[];
    foreach($sites as $candidate){
      if(empty($candidate['enabled']))continue;
      $candidateName=(string)($candidate['name']??'');
      $candidateUrl=trim((string)($candidate['url']??''));
      $candidateHost=(string)(parse_url($candidateUrl,PHP_URL_HOST)?:'');
      $nameKey=$normalizeSite($candidateName);
      $urlKey=$normalizeSite($candidateUrl);
      $hostKey=$normalizeSite($candidateHost);
      $requestedHost=(string)(parse_url(preg_match('~^https?://~i',$siteName)?$siteName:'https://'.$siteName,PHP_URL_HOST)?:'');
      $isMatch=($requested!=='' && ($requested===$nameKey || $requested===$urlKey))
        || ($requestedHost!=='' && $hostKey!=='' && strcasecmp($requestedHost,$candidateHost)===0);
      if($isMatch)$matches[]=$candidate;
    }
    // A unique, case-insensitive partial name match is a last-resort convenience;
    // ambiguous matches are rejected rather than selecting an arbitrary site.
    if(!$matches){
      foreach($sites as $candidate){
        if(empty($candidate['enabled']))continue;
        $candidateName=$normalizeSite((string)($candidate['name']??''));
        if($requested!=='' && $candidateName!=='' && (str_contains($candidateName,$requested)||str_contains($requested,$candidateName)))$matches[]=$candidate;
      }
    }
    if(count($matches)!==1){
      $available=[];
      foreach($sites as $candidate)if(!empty($candidate['enabled']))$available[]=(string)($candidate['name']??'').' ('.(string)($candidate['url']??'').')';
      $reason=count($matches)>1?'Website name matches multiple configurations':'No matching website found';
      throw new InvalidArgumentException($reason.' for site="'.$siteName.'". Enabled websites: '.($available?implode('; ',$available):'no websites are enabled').'. Use the exact name or URL returned by action=list.');
    }
    $site=$matches[0];$siteName=(string)($site['name']??$siteName);
    require_once dirname(__DIR__).'/website_connector.php';$query=$clip($args['query']??'',500);$url=$clip($args['url']??'',1500);
    $readResult=wc_site_read($site,$query,$url);
    if(empty($readResult['success']) || empty($readResult['pages'])) throw new LacToolException('WEBSITE_READ_FAILED',(string)($readResult['error']??'Could not extract website content.'),(bool)($meta['retryable']??true));
    $pageEvidence=lac_web_normalize_results($readResult['pages']??[],['provider'=>'configured_website','errors'=>$readResult['errors']??[]]);
    $storage=lac_web_source_store($pdo,$pageEvidence['sources']);
    $readResult['evidence']=$pageEvidence;
    $readResult['citation_storage']=$storage;
    $out=['action'=>'read','site'=>$siteName,'content'=>$readResult];break;
   case 'crawl_website':
    $raw=setting($pdo,'websites_config','[]');$sites=json_decode($raw,true);if(!is_array($sites))$sites=[];
    $siteName=$clip($args['site']??'',120);$site=null;
    foreach($sites as $candidate)if(!empty($candidate['enabled'])&&strcasecmp((string)($candidate['name']??''),$siteName)===0){$site=$candidate;break;}
    if(!$site)throw new InvalidArgumentException('Website does not exist or is not enabled. Call manage_websites with action=list first.');
    $siteUrl=trim((string)($site['url']??''));
    if($siteUrl==='')throw new InvalidArgumentException('Website does not have a valid base URL.');
    $crawl=lac_python_runtime(['action'=>'web_crawl','arguments'=>[
      'url'=>$siteUrl,
      'max_pages'=>max(1,min(20,(int)($args['max_pages']??8))),
      'max_depth'=>max(0,min(3,(int)($args['max_depth']??1))),
      'max_chars_per_page'=>20000
    ]],(int)($meta['timeout']??180),$context);
    $pages=is_array($crawl['pages']??null)?$crawl['pages']:[];
    if(empty($crawl['success'])||!$pages)throw new LacToolException('WEBSITE_CRAWL_FAILED',(string)($crawl['error']??'No pages were successfully crawled.'),(bool)($meta['retryable']??true));
    $evidence=lac_web_normalize_results($pages,['provider'=>'bounded_playwright_crawler','errors'=>$crawl['errors']??[]]);
    $storage=lac_web_source_store($pdo,$evidence['sources']);
    $out=['success'=>true,'site'=>$siteName,'crawl'=>$crawl,'evidence'=>$evidence,'citation_storage'=>$storage];break;
   case 'get_server_info':
    $out=['php'=>PHP_VERSION,'os'=>PHP_OS_FAMILY,'server'=>$_SERVER['SERVER_SOFTWARE']??'','timezone'=>date_default_timezone_get(),'memory_limit'=>ini_get('memory_limit'),'max_execution_time'=>ini_get('max_execution_time')];break;
   case 'list_models':
    $modelsUrl=(string)setting($pdo,'models_endpoint','');if($modelsUrl===''){$endpoint=rtrim((string)setting($pdo,'endpoint',$context['endpoint']??''),'/');$modelsUrl=preg_replace('~/chat/completions$~','/models',$endpoint);}if(!filter_var($modelsUrl,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($modelsUrl,PHP_URL_SCHEME)),['http','https'],true))throw new RuntimeException('models endpoint is not configured correctly.');$ch=curl_init($modelsUrl);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8]);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);$out=['http_status'=>$code,'models'=>json_decode((string)$body,true)??[],'error'=>$err?:null];break;
   case 'physics_calculate':
    $out=lac_python_runtime(['action'=>'physics_calculate','arguments'=>$args],(int)$meta['timeout'],$context);break;
   case 'python_unit_converter':
    $unitArgs=['value'=>$args['value'],'from_unit'=>$args['from_unit'],'to_unit'=>$args['to_unit']];
    if(array_key_exists('dimension',$args))$unitArgs['dimension']=$args['dimension'];
    $out=lac_python_runtime(['action'=>'unit_converter','arguments'=>$unitArgs],(int)$meta['timeout'],$context);break;
   case 'python_calculate':
    $expr=trim((string)($args['expression']??''));
    if(preg_match('/^(?:import\s|from\s+\w+\s+import|def\s|class\s|for\s|while\s|print\s*\(|[A-Za-z_]\w*\s*=|\[.*\bfor\b.*\])/s',$expr))throw new InvalidArgumentException('python_calculate accepts one mathematical expression only and does not run Python code. Select an appropriate data/Python tool.');
    // Forward every schema-validated argument; dropping mode/variable/substitutions
    // silently forced the worker into evaluate mode and broke symbolic operations.
    $out=lac_python_runtime(['action'=>'calculate','arguments'=>$args],(int)$meta['timeout'],$context);break;
   case 'python_generate_number':
    $genArgs=$args;
    $out=lac_python_runtime(['action'=>'generate_number','arguments'=>$genArgs],(int)$meta['timeout'],$context);break;
   case 'python_statistics':
    $method=(string)$args['method'];$statArgs=['method'=>$method];foreach(['values','y','groups','expected','population_mean','confidence_level','alternative','equal_var'] as $key)if(array_key_exists($key,$args))$statArgs[$key]=$args[$key];
    $out=lac_python_runtime(['action'=>'statistics','arguments'=>$statArgs],(int)$meta['timeout'],$context);break;
   case 'python_plot':
    $plotArgs=['chart_type'=>$args['chart_type']??'line','title'=>$args['title']??'Chart'];foreach(['x','y','series'] as $key)if(array_key_exists($key,$args))$plotArgs[$key]=$args[$key];
    $out=lac_python_runtime(['action'=>'plot','arguments'=>$plotArgs],(int)$meta['timeout'],$context);if(!empty($out['file'])&&preg_match('/^chart_[a-f0-9]{32}\.(?:jpg|png)$/D',(string)$out['file']))$out['image_url']='api.php?action=python_output&file='.rawurlencode((string)$out['file']);break;
   case 'python_dataframe':
    $dfArgs=['data'=>$args['data'],'operation'=>$args['operation']??'profile'];
    foreach(['column','operator','value','aggregation','value_column'] as $key)if(array_key_exists($key,$args))$dfArgs[$key]=$args[$key];
    $out=lac_python_runtime(['action'=>'dataframe','arguments'=>$dfArgs],(int)$meta['timeout'],$context);break;
   case 'verify_result':
    $verifyArgs=$args;$out=lac_python_runtime(['action'=>'verify','arguments'=>$verifyArgs],(int)$meta['timeout'],$context);break;
   default: throw new RuntimeException('Tool has no registered executor.');
  }

 return $out??null;
}
