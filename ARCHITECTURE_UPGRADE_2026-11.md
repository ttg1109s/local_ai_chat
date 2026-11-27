# Local AI Chat — Architecture Upgrade (2026-11)

## Implemented in this build

- Added `app/agent/tool_output.php` with a renderer-neutral Tool Output Envelope (`schema_version: 1.0`), result-type detection, bounded preview projection, and Artifact/Data Pack references.
- Reworked `attachment_manager.php` to schema v2. Presentation is independent from MIME/format and supports: `data_table`, `chart`, `image`, `document`, `json_viewer`, `code`, `sources`, `evidence`, `math`, `model_report`, `media`, and `file`.
- Tool results committed by the Agent Runtime are registered as user-visible Attachment descriptors when conversation/message ownership context is present. Registration failures are logged without invalidating a committed Tool execution.
- Conversation history now returns attachment descriptors. The UI renders typed attachment cards and provides an ownership-checked preview endpoint. Large segmented data previews read only the first bounded page rather than hydrating the full Data Pack.
- Added bounded JSONL query operations: equality/inequality, numeric comparisons, `in`, `contains`, global sort within a configured scan budget, pagination, and count/sum/avg/min/max aggregation.
- `verify_data_pack` now hashes every segment referenced by the manifest and reports the verification scope and per-segment failures.
- Added Data Flow plan validation and a Runtime-owned plan dispatcher for inspect/search/read/query/verify/attach operations.
- Context Builder now accepts a model-specific tokenizer callback and uses relevance scoring to retain selected older evidence. The byte-based estimate remains an explicit fallback.
- Manifest segment writes now loop until all bytes are written and manifests are versioned as schema v2.

## Verification performed in the development container

- PHP syntax lint: all PHP files passed.
- Agent PHP suite: passed, including new Data Engine and Tool Output/Attachment tests.
- Python test files: all available tests passed.
- JavaScript syntax: `node --check assets/app.js` passed.
- Scheduler test remains SKIP because the container does not provide PHP `mbstring`.

## Explicit remaining production limitations

1. **Not a full SQL/columnar engine.** Query execution currently scans JSONL segments into bounded memory and refuses datasets above `max_scan_rows` (default 50,000; hard maximum 200,000). It supports basic filters, sort and scalar aggregations but does not yet provide persistent indexes, joins, window functions, distributed execution, or external-memory aggregation.
2. **Legacy canonical JSON remains.** Result Store continues to retain its canonical JSON record for backward compatibility, while JSONL segments are sidecar storage. This release does not eliminate duplicate physical storage.
3. **Exact model tokenizer is an integration point, not preconfigured.** Deployments must register the matching tokenizer callback for each model. Without it, the builder reports and uses the byte heuristic; this is not exact token accounting.
4. **Context relevance is lexical.** It is a deterministic relevance heuristic, not an embedding/reranking model and not a semantic confidence estimator.
5. **Attachment binary delivery is not universal yet.** The new preview route supports run-owned Data Pack content. Existing Artifact Manager image delivery remains separate; uploads and arbitrary document binaries still need source-specific resolvers and download authorization.
6. **Data Flow plan dispatcher is available as a Runtime API but is not a new model-callable tool.** This preserves the existing Agent protocol and permission boundary. A future model-facing data-access contract should be explicitly designed and permissioned rather than exposing arbitrary plans.
7. **No production concurrency/crash campaign was run.** Existing durable execution reconciliation and file locks remain; this build adds tests for the new features but cannot establish filesystem semantics, multi-worker contention, power-loss durability, or deployment-specific recovery behavior.
8. **Production deployment is not certified.** PHP extensions, web-server configuration, storage permissions, worker lifecycle and model-specific tokenizer integration must be validated in the target environment.

## Storage hygiene

The distributable archive contains source code, tests and documentation only. Runtime storage, execution ledgers, task state, traces, user data, generated artifacts and Python bytecode are excluded.
