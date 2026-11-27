<?php
require_once __DIR__.'/../app/agent/model_adapter.php';
require_once __DIR__.'/../app/agent/model_transport.php';
$normalized=lac_agent_choice_content(['message'=>['content'=>[['type'=>'text','text'=>'{"type":'],['type'=>'text','text'=>'"final"}']]],'finish_reason'=>'stop']);
if(($normalized['content']??'')!=='{"type":"final"}')throw new RuntimeException('OpenAI-compatible content parts were not normalized.');
$completion=lac_agent_completion_payload(['choices'=>[['message'=>['content'=>'{"type":"final","content":"ok"}'],'finish_reason'=>'stop']],'usage'=>['total_tokens'=>7]]);
if(empty($completion['recognized'])||($completion['usage']['total_tokens']??0)!==7)throw new RuntimeException('OpenAI-compatible completion envelope normalization failed.');
if(lac_agent_adapter_normalize_content(['unrecognized'=>true])!=='')throw new RuntimeException('Unsupported content shapes must not be interpreted as model text.');
echo "PASS: OpenAI-compatible provider adapter normalization\\n";
