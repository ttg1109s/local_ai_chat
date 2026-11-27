# Agent Context Builder

`builder.php` builds a bounded projection of the accumulated conversation payload for each Agent inference. The canonical in-process payload and durable execution records are not modified.

## Policy

- Preserve leading system/developer instructions and the original user objective.
- Prefer the newest messages within the configured budget.
- Replace omitted older messages with a compact, explicitly non-authoritative summary.
- Execution Store, Task Store and Data Pack remain authoritative; compaction summaries are never treated as source evidence.
- Default budget: 12,000 estimated tokens; default output reserve: 1,800 estimated tokens; recent-message window: 12.

The current estimator uses a conservative byte-based approximation (`ceil(bytes / 3.6)`) because the model tokenizer is not exposed at this layer. It is a guardrail, not an exact tokenizer. A provider-specific tokenizer can replace this estimator without changing the builder contract.

## Integration

`loop/runner.php` invokes `lac_agent_context_build()` on the payload copy immediately before each Agent model request. Runtime interaction state is appended after compaction so the model receives fresh execution truth every round.

## Recovery integration

`lac_invocation_reconcile()` also checks the deterministic Data Pack locator derived from `execution_id`. If a valid, run-scoped result exists and its tool, execution ID, call ID and success flag match the ledger record, Runtime can safely complete the interrupted ledger commit. If it cannot prove the match, the invocation remains `UNKNOWN` and must not be blindly retried.
