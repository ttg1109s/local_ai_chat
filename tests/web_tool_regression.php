<?php
// Focused regression assertions for web tool handler/executor contracts.
require_once __DIR__ . '/../app/agent/tool_executor.php';
$handler = file_get_contents(__DIR__ . '/../app/agent/tool_handlers.php');
$connector = file_get_contents(__DIR__ . '/../app/website_connector.php');
$checks = [
 'no_undefined_rid_in_web_handler' => !preg_match('/\\$rid\\b/', $handler),
 'no_executor_local_fail_closure_in_handler' => !preg_match('/\\$fail\\s*\\(/', $handler),
 'structured_web_tool_exception' => strpos($handler, "LacToolException('WEB_SEARCH_FAILED'") !== false,
 'website_reader_reports_success' => strpos($connector, "'success' => " . '$success') !== false,
 'website_reader_reports_failures' => strpos($connector, "'pages_failed'") !== false,
];
$failed = [];
foreach ($checks as $name => $ok) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $failed[]=$name; }
exit($failed ? 1 : 0);
