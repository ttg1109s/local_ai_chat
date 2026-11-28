<?php
declare(strict_types=1);

// Regression guard: partial task checkpoint writes must preserve previously recorded
// counters and execution metadata, including approval endpoints that pass sparse data.
$source=file_get_contents(__DIR__.'/../app/agent/task_store.php');
if(!is_string($source))throw new RuntimeException('Could not read task_store.php.');
$required=[
 "\$data['step_count']??\$old['step_count']??0",
 "\$data['tool_call_count']??\$old['tool_call_count']??0",
 "\$data['completed_tool_count']??\$old['completed_tool_count']??0",
 "\$data['failed_tool_count']??\$old['failed_tool_count']??0",
 "\$data['available_tools']??\$old['available_tools']??[]",
 "\$data['last_tool']??\$old['last_tool']??null",
 "\$data['last_error_code']??\$old['last_error_code']??null",
 "\$data['elapsed_ms']??\$old['elapsed_ms']??0",
 "\$data['plan']??\$old['plan']??null",
 "\$data['node_results']??\$old['node_results']??[]",
];
foreach($required as $guard)if(!str_contains($source,$guard))throw new RuntimeException('Partial checkpoint update must preserve prior field: '.$guard);
echo "agent_task_store_partial_update_preserves_checkpoint_test: PASS\n";
