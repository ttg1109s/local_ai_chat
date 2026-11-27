<?php
$loop=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
$policy=file_get_contents(__DIR__.'/../app/agent/execution_policy.php');
$store=file_get_contents(__DIR__.'/../app/agent/execution_store.php');
if(!str_contains($loop, '$invocationKey=$toolCallId'))
    throw new RuntimeException('Invocation state must be keyed by tool_call_id, not Tool+Arguments.');
if(!str_contains($loop, '$invocationState[$invocationKey]'))
    throw new RuntimeException('Retry state must be scoped to one invocation identity.');
if(str_contains($loop, '$invocationState[$callSignature]'))
    throw new RuntimeException('Tool+Arguments signature must not act as a hard invocation deduplication key.');
if(!str_contains($loop, 'blocked\'=>false'))
    throw new RuntimeException('Repeated-operation detection must remain advisory, not a hard block.');
if(!str_contains($loop, 'A new tool_call_id is a new invocation'))
    throw new RuntimeException('The model must be told that identical arguments can be a legitimate new invocation.');
if(!str_contains($store, 'Same arguments with a different ID remain a new request.'))
    throw new RuntimeException('Durable execution identity contract changed unexpectedly.');
if(!str_contains($policy, 'Repeated reads or verification calls are allowed'))
    throw new RuntimeException('Execution policy must permit purposeful repeated calls.');
echo "PASS: same Tool+Arguments may be invoked again with a new call ID; retries remain call-ID scoped; loop detection is advisory.\\n";
