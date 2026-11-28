<?php
require_once __DIR__ . '/../app/agent/tool_registry.php';
$tools = lac_tool_catalog();
$checks = [
 'search_web_has_three_task_modes' => isset($tools['search_web']['schema']['properties']['mode']['enum'])
   && $tools['search_web']['schema']['properties']['mode']['enum'] === ['search','research','deep_research'],
 'crawler_is_not_agent_tool' => !isset($tools['crawl_website']),
 'web_search_in_website_group' => ($tools['search_web']['group'] ?? '') === 'Website',
 'research_processors_are_not_agent_tools' => !isset($tools['web_research']) && !isset($tools['web_research_multihop']) && !isset($tools['verify_web_evidence']) && !isset($tools['synthesize_web_research']),
 'configured_web_read_remains_separate' => isset($tools['manage_websites']),
 'research_schema_exposes_coverage_and_claim_audits' => isset($tools['search_web']['schema']['properties']['required_aspects']) && isset($tools['search_web']['schema']['properties']['claims']),
 'source_independence_is_not_assumed' => strpos(file_get_contents(__DIR__ . '/../app/web/web_source_intelligence.php'), 'same_hostname_groups') !== false
   && strpos(file_get_contents(__DIR__ . '/../app/web/web_source_intelligence.php'), 'near_duplicate_candidates') !== false,
 'search_web_supports_reuse_audit_pass' => isset($tools['search_web']['schema']['properties']['audit_only']) && isset($tools['search_web']['schema']['properties']['source_material']),
 'claim_evidence_is_hydrated_from_source_ledger' => strpos(file_get_contents(__DIR__ . '/../app/agent/tool_handlers.php'), 'sourceById') !== false
   && strpos(file_get_contents(__DIR__ . '/../app/agent/tool_handlers.php'), 'ev[\'url\'] =') !== false,
 'internal_research_pipeline_is_wired' => strpos(file_get_contents(__DIR__ . '/../app/agent/tool_handlers.php'), "'source_intelligence'=>\$sourceIntelligence") !== false
   && strpos(file_get_contents(__DIR__ . '/../app/agent/tool_handlers.php'), "'quality_assessment'=>\$qualityAssessment") !== false
   && strpos(file_get_contents(__DIR__ . '/../app/agent/tool_handlers.php'), 'lac_web_knowledge_synthesis') !== false,

];
$failed=[];
foreach($checks as $name=>$ok){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$failed[]=$name;}
exit($failed?1:0);
