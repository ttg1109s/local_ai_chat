<?php
$runner=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
$assert=function($ok,$message){if(!$ok)throw new RuntimeException($message);};
$assert(str_contains($runner,"'code'=>'TOOL_HELP_REQUIRED'"),'Missing TOOL_HELP_REQUIRED error code.');
$assert(str_contains($runner,"'status'=>'rejected'"),'Invalid tool invocation must return a rejected tool_result.');
$assert(str_contains($runner,"'execution_started'=>false"),'Rejected call must explicitly state execution did not start.');
$assert(str_contains($runner,"'retryable'=>true"),'Help-required rejection must be retryable.');
$assert(str_contains($runner,'tool_call_rejected_help_required'),'Missing structured rejection audit event.');
$assert(!str_contains($runner,'TOOL_DISABLED_AFTER_PROTOCOL_FAILURE'),'A protocol violation must not disable the tool.');
$assert(!str_contains($runner,'$blockedTools'),'No per-run tool blacklist may be maintained.');
$assert(!str_contains($runner,'$pendingToolHelp'),'A failed call is returned as tool_result; it must not hold the task hostage.');
echo "PASS: missing-help invocation returns retryable tool failure without disabling tool or blocking task finalization\n";
