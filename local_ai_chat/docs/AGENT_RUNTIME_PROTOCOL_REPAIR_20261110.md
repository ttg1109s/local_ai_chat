# Agent Runtime protocol repair — 2026-11-10

## Scope

This change updates the existing incremental Unified Agent JSON protocol. It does not introduce provider-native tool calling, a second tool protocol, a DAG planner in the Agent Loop, or an aggregate tool-call/time/round ceiling.

## Protocol v4.1

Model-to-runtime control messages remain:

- `tool_help_request`
- `tool_call`
- `final`

Runtime-to-model feedback remains:

- `tool_help_result`
- `tool_result`

A `tool_call` must contain exactly `type`, `tool`, `tool_call_id`, and `arguments`. `tool_call_id` is a required model-issued identifier of 1–128 ASCII characters (`A-Z`, `a-z`, digits, `.`, `_`, `:`, `-`). `arguments` must be a JSON object. Unknown or additional fields are rejected before execution.

## Invocation identity and retry

`tool_call_id` is the durable identity used by the in-memory retry budget, duplicate guard, execution ledger, executor context, and verified execution receipt.

- Retry the exact same tool and unchanged arguments with the same ID only when Runtime marks the failure retryable.
- Use a new ID whenever the tool or arguments change, or for a genuinely new invocation.
- Completed or exhausted IDs cannot be reused.
- An `UNKNOWN` side-effecting outcome is not retried until Runtime reconciliation establishes its state.
- Runtime does not synthesize an ID for a malformed or missing model ID; it rejects the request before execution.

Failure feedback now carries the `tool_call_id`, execution-started/committed flags, phase, retryability, attempt count, and remaining budget where applicable.

## Dispatch and protocol decoding

The exact compiled dispatch payload—including protocol instructions and the enabled-tool catalog—is retained as the base payload for every subsequent Agent round. Runtime interaction state is appended to that context rather than replacing it.

The decoder distinguishes ordinary text, a valid canonical JSON envelope, malformed protocol JSON, and a protocol-looking JSON object embedded in surrounding prose. Mixed/malformed protocol output is rejected and repaired; it is not silently accepted as a final response.

When tools are enabled, malformed dispatch output and provider-native-only output enter the Agent repair loop with the original enabled-tool snapshot intact. No provider-native call is executed.

## Provider-native tool calls remain disabled

The project deliberately uses its own Unified Agent JSON protocol. The runner strips provider-native request fields (`tools`, `tool_choice`, `functions`, `function_call`). The transport detects provider-native response metadata only to mark it unsupported; it returns no native calls for execution. The Agent loop rejects native-only responses and asks for a canonical project-protocol response. This preserves the existing security and execution boundary.

## Finalization and completion semantics

The former `$finalizationRequired` state had no transition that could set it. Its branches and matching unreachable prompt instructions were removed rather than introducing an arbitrary execution ceiling. The Agent Loop remains unbounded at the aggregate round/tool level, with existing per-invocation retry and protocol-repair limits.

A validated final response means the Runtime accepted the response after applicable protocol, provenance, and output-contract checks. It is not independent proof that the user's underlying goal was achieved. Terminal telemetry now uses `response_delivered`; task metadata records `final_response_validated=true` and `goal_completion_verified=false`. A future goal-specific validator can set goal completion only when an explicit success contract exists and is verified.

## Validation performed

- All 51 PHP regression tests passed.
- All 108 PHP files passed `php -l`.
- Regression coverage includes canonical envelope validation, required invocation IDs, mixed-output rejection, protocol context persistence, structured retry feedback, provider-native rejection, strict tool permissions, receipt-gated execution, and unbounded Agent rounds.
