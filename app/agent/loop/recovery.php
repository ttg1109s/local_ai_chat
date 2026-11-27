<?php
/** Normalize a failed tool result into a model-facing, non-terminal recovery message. */
function lac_agent_loop_tool_failure_payload(array $failure,array $batchResults,int $attempt=0,int $remaining=0): array {
 $retryable=!empty($failure['retryable']);
 $rejected=array_key_exists('execution_started',$failure)&&$failure['execution_started']===false;$message=(string)($failure['phenomenon']??($rejected?'The invocation was rejected during preflight.':'The tool execution failed.'));
 return [
  'type'=>'tool_result',
  'tool'=>(string)($failure['tool']??''),
  'tool_call_id'=>(string)($failure['tool_call_id']??''),
  'status'=>$rejected?'rejected':($retryable?'execution_failed':'tool_unavailable'),
  'phase'=>$failure['phase']??null,
  'execution_started'=>!$rejected,
  'execution_committed'=>false,
  'error'=>[
   'code'=>(string)($failure['error_code']??'TOOL_EXECUTION_FAILED'),
   'message'=>$message,
   'details'=>$failure['validation']['violations']??[],
   'retryable'=>$retryable,
  ],
  'attempt'=>$attempt,
  'attempts_remaining'=>$remaining,
  'previous_results'=>$batchResults,
  'instruction'=>'This is a non-terminal tool failure, not a request to finish. Continue reasoning. You may use another enabled tool, correct the request if the error is actionable, or answer using available knowledge and verified results. Do not claim that the failed action succeeded.',
 ];
}
