<?php
require_once __DIR__.'/../app/agent/model_transport.php';
function transport_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$stream=lac_agent_choice_content(['delta'=>['content'=>'hello'],'finish_reason'=>null]);
transport_assert($stream['content']==='hello','SSE delta content must be extracted from choices[0].delta.content');
$message=lac_agent_choice_content(['message'=>['content'=>'final answer'],'finish_reason'=>'stop']);
transport_assert($message['content']==='final answer'&&$message['finish_reason']==='stop','Non-stream message content and finish reason must be normalized');
$nativeChoice=['delta'=>['tool_calls'=>[['id'=>'provider_call_1']]]];
transport_assert(lac_agent_choice_has_native_tool_output($nativeChoice),'Provider-native tool metadata must be detected without being parsed as a project tool call.');
transport_assert(!lac_agent_choice_has_native_tool_output(['delta'=>['content'=>'ordinary text']]),'Ordinary content must not be misclassified as provider-native tool output.');
$parts=lac_agent_choice_content(['message'=>['content'=>[['type'=>'text','text'=>'one'],['type'=>'text','text'=>' two']]]]);
transport_assert($parts['content']==='one two','Array-form text content must be normalized');
$completion=lac_agent_completion_payload(['choices'=>[['message'=>['content'=>'answer'],'finish_reason'=>'stop']],'usage'=>['total_tokens'=>12]]);
transport_assert(!empty($completion['recognized'])&&$completion['content']==='answer'&&$completion['usage']['total_tokens']===12,'Standard completion envelope and usage must be extracted');
$unsupported=lac_agent_completion_payload(['forices'=>[['message'=>['content'=>'wrong']]]]);
transport_assert(empty($unsupported['recognized'])&&$unsupported['content']==='','Non-standard/malformed response fields must not be mistaken for valid content');
$source=file_get_contents(__DIR__.'/../app/agent/model_transport.php');
transport_assert(!str_contains($source,"['forices']"),'Transport must not reference the misspelled response field');
transport_assert(str_contains($source,"['choices'][0]"),'Transport must use the standard choices envelope');
transport_assert(str_contains($source,"'native_tool_calls'=>[]"),'Transport must return no provider-native tool calls for execution');
transport_assert(str_contains($source,'first non-whitespace byte proves this is not a JSON control/envelope'),'Transport must stream direct text without requiring a final envelope.');
transport_assert(str_contains($source,'function lac_agent_stream_final_delta')&&str_contains($source,'$emit(\'delta\',[\'text\'=>$safeText])'),'Transport must stream decoded final content incrementally.');
transport_assert(!str_contains($source,'$emit(\'delta\',[\'text\'=>$content]'),'Transport must never stream the raw protocol envelope.');
transport_assert(str_contains($source,'$unsupportedNativeToolOutput'),'Provider-native tool output must be detected but never executed.');
echo "PASS: model response normalization, direct text streaming, OpenAI-compatible choices, and project-owned tool protocol\n";
