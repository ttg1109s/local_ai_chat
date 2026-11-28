<?php
$loop=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
$recovery=file_get_contents(__DIR__.'/../app/agent/loop/recovery.php');
$api=file_get_contents(__DIR__.'/../api_routes/chat_stream.php');
if(!str_contains($recovery,"'execution_failed'"))throw new RuntimeException('Tool execution failures must be returned as structured protocol messages.');
if(!str_contains($recovery,"'tool_unavailable'"))throw new RuntimeException('Exhausted tool failure must be returned as a non-terminal diagnostic.');
if(!str_contains($recovery, '$retryable'))throw new RuntimeException('Retryability must come from the actual execution result.');
if(!str_contains($recovery,'This is a non-terminal tool failure'))throw new RuntimeException('Model must be explicitly instructed to continue after tool failure.');
if(!str_contains($loop,'$emit(' . "'tool_error'"))throw new RuntimeException('Tool error event missing.');
if(str_contains($api,'TOOL_FAILURE:'))throw new RuntimeException('Tool failures must not mark the whole Agent response as failed.');
echo "PASS: tool failures are non-terminal and preserve executor retryability".PHP_EOL;
