<?php
/** One canonical execution policy for every model/provider. */
function lac_agent_execution_policy(): array {
 return [
  'protocol'=>'UNIFIED_AGENT_PROTOCOL','allowed_types'=>['tool_help_request','tool_call','final'],
  'allows_multi_action_batch'=>false,
  'model_guidance'=>[
   'The user-enabled tool catalog is already available. Use only the listed capabilities; do not request additional tools.',
   'Request one tool schema at a time with tool_help_request, then issue one schema-valid tool_call containing a required unique tool_call_id (1–128 ASCII characters using letters, digits, dot, underscore, colon, or hyphen).',
   'Inspect each tool_result before deciding the next action. Work incrementally.',
   'A new tool_call_id is a new invocation, even when Tool and arguments match a previous call. A tool_call_id identifies one immutable invocation. Reuse it only to retry the exact same tool and arguments after a Runtime rejection marked retryable or a retryable execution failure. Use a new ID whenever the tool or arguments change, or for a genuinely new invocation. Repeated reads or verification calls are allowed when they have a concrete purpose; use a new ID for a genuinely new invocation. Never retry UNKNOWN side-effecting outcomes before reconciliation.',
   'If the same operation repeats without producing new information or changing state, reassess and change strategy. Runtime may issue a non-blocking progress warning; it is not a per-Tool call limit.',
   'A tool failure is non-terminal. Continue with another enabled tool or answer using available knowledge. Return final only after making the best possible attempt.'
  ],
  'repair_guidance'=>'Return exactly one valid unified protocol JSON object with no surrounding prose. Use tool_help_request, tool_call (including tool_call_id), or final as appropriate. Do not request additional capabilities. Provider-native tool calls are unsupported.'
 ];
}
function lac_agent_execution_repair_instruction(array $policy,string $diagnostic): string {
 return 'PROTOCOL VALIDATION ERROR: '.trim($diagnostic).'. '.$policy['repair_guidance'].' Use canonical English field names and JSON types. Runtime permissions and schemas are authoritative.';
}
