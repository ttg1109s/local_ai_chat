# Unified Trace Schema v2

## Purpose

Schema v2 records Agent Runtime lifecycle, protocol decisions, tool execution, and terminal outcomes in one append-only JSONL stream. Each line is an independent event record.

## Event envelope

- `schema_version`: integer schema version (`2`).
- `event_id`: unique event identifier.
- `sequence`: monotonically increasing sequence within the trace writer context.
- `timestamp`, `recorded_at`, `elapsed_ms`: event time and elapsed runtime.
- `trace_id`, `conversation_id`, `message_id`, `run_id`: request and runtime scope.
- `round_id`, `request_id`: model round and model request correlation.
- `tool_call_id`, `execution_id`, `batch_id`: invocation, concrete execution attempt, and batch correlation.
- `source`, `category`, `event`, `severity`, `outcome`: event classification.
- `duration_ms`: operation duration when available.
- `payload_bytes`, `payload_hash`: pre-sanitization payload size and SHA-256 digest.
- `data`: bounded, sanitized event-specific fields.

## Outcomes

The recorder uses explicit outcomes including `proposed`, `validated`, `rejected`, `started`, `transition`, `success`, `failed`, `denied`, `partial`, `cancelled`, `unknown`, and `observed`. Event classification is centralized in `lac_trace_event_defaults()`; events with a runtime-dependent outcome, such as `tool_result`, are classified from structured fields.

## Correlation model

- `request_id` identifies one model transport request.
- `tool_call_id` identifies one logical tool invocation across retries and ledger transitions.
- `execution_id` identifies one concrete execution attempt.
- `round_id` identifies the Agent model round.
- `batch_id` identifies a grouped set of tool calls when supplied.

A missing identifier remains null; the recorder does not fabricate a correlation relationship.

## Persistence behavior

The writer appends one JSON line under an exclusive file lock and confirms the byte count and flush result. A failed append does not advance the in-memory sequence. Failures set the process-local trace health state and emit a PHP error-log warning. Trace persistence is best-effort observability and does not replace the durable Execution Ledger.

## Privacy and truncation

Sensitive keys are redacted. Model output, final text, and serialized arguments remain bounded and credential-scrubbed for diagnostics. Streaming `delta` events are intentionally omitted. `payload_hash` and `payload_bytes` describe the original event payload before field-level sanitization; they are diagnostic metadata, not proof of payload integrity after redaction.

## Compatibility

New records use schema version 2. Existing schema version 1 JSONL files are not rewritten. Consumers should branch on `schema_version` and tolerate null correlation identifiers.
