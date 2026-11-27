<?php
$loop=file_get_contents(__DIR__.'/../app/agent/loop/runner.php');
$api=file_get_contents(__DIR__.'/../api_routes/chat_stream.php');
$policy=file_get_contents(__DIR__.'/../app/agent/autonomy_policy.php');
if(!str_contains($loop,'function lac_agent_run(PDO $pdo,string $endpoint,array $payload,array $enabledToolIds,array $context,callable $emit): array'))
    throw new RuntimeException('Agent runtime still accepts a fixed step/round limit.');
foreach (['$maxModelRounds','$maxToolCalls','$maxRunSeconds','$finalizationReserveSeconds'] as $forbidden) {
    if(str_contains($loop,$forbidden)) throw new RuntimeException('Agent Loop must not impose a global round, time, or aggregate tool-call cap: '.$forbidden);
}
if(!str_contains($loop,'$maxRetryPerInvocation=2') || !str_contains($loop,'$maxProtocolRepairAttempts=2'))
    throw new RuntimeException('Invocation retry and protocol repair limits must remain independently enforced.');
foreach (['$toolRecoveryAttempts'] as $forbidden) {
    if(str_contains($loop,$forbidden)) throw new RuntimeException('Shared tool retry counter remains: '.$forbidden);
}
if(str_contains($api,'$agentMode===\'fast\'?4'))
    throw new RuntimeException('Fast mode still has a hard-coded four-step limit.');
if(str_contains($api,"agent_max_tool_calls"))
    throw new RuntimeException('API still passes a configurable per-turn tool-call cap.');
if(str_contains($policy,"'max_steps'"))
    throw new RuntimeException('Autonomy policy still exposes max_steps.');
if(!str_contains($loop,'$modelRound++') || !str_contains($loop,'\'round\'=>$modelRound'))
    throw new RuntimeException('Model-round telemetry counter is missing.');
if(str_contains($loop,'$requestTimeout') || !str_contains($loop,'lac_agent_model_request($endpoint,$requestPayload,0,'))
    throw new RuntimeException('Agent model request must rely on hosting-server timeout and explicit user cancellation.');
echo "PASS: no Agent-level round/time/tool-call cap; invocation-scoped retry and protocol repair limits remain.\n";
