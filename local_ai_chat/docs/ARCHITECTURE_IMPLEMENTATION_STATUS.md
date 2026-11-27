# Local AI Chat — Architecture Upgrade Status

Date: 2026-11-10

This status describes the code delivered in this archive. The active orchestrator remains the stateful incremental Agent Loop; DAG planning and scheduler orchestration have been removed.

## Implemented

### Data Pack and model boundary
- Tool outputs are committed to the Result Store and receive run-scoped references.
- `lac_data_pack_model_receipt()` returns reference metadata and compact previews only. It does not return `data_pack_source` or hydrate the canonical payload into inference context.
- Full-payload `$data_ref` substitution is disabled. Selective reference resolution requires a segmented `$path` page or a `$query` with a field.
- Manifest Manager writes run-scoped field metadata and JSONL segments for list-valued fields, including lists smaller than the previous segmentation threshold.
- Segment reads validate filenames, reject symlinks, verify SHA-256, and return bounded pages.
- Query filtering and aggregate calculations stream over the complete segmented field. Sorted results retain matched rows only within the explicit scan budget; queries fail closed when limits or integrity checks fail.
- Run cleanup removes the canonical result, manifest, and owned JSONL segments.

### Model-facing Data Access protocol
- `data_access` is registered as a normal model-facing tool with `inspect`, `read`, `query`, and `verify` operations.
- `tool_handlers.php` routes the tool call through Data Flow Controller, which validates the operation and dispatches to Data Access.
- Data Access enforces the current run scope and never accepts arbitrary filesystem paths.

### Attachment and presentation registry
- Attachment descriptors and artifact batches are independent file-backed registries. Message IDs are optional provenance; artifact bytes are resolved from batch manifests rather than message database rows.
- Conversation history returns the attachment registry separately from the message list.
- The attachment-content API resolves Data Pack pages or artifact-backed image previews.
- Generated images are registered as image attachments. The Frontend renders conversation-level attachment cards instead of binding attachment presentation to assistant message bubbles.
- `app.css` was split into ordered base and UI continuation stylesheets (`app-ui.css`) without changing cascade order.

### Legacy removal
- Removed the DAG planner, its dedicated unit test, and the obsolete DAG scheduler test.
- Removed the stale runtime backup copy.
- Updated model protocol instructions and architecture documentation so they no longer describe automatic Data Pack hydration.

## Validation

- All PHP source files pass `php -l`.
- `assets/app.js` and `assets/attachments.js` pass `node --check`.
- All 22 Python source files parse successfully with Python's AST parser.
- `tests/run_agent_suite.sh`: passed (56 PHP regression tests).
- All 6 Python test files passed individually.
- Tool Registry count verified at exactly 16 tools; no tool names were added or removed.

### Final-pass corrections applied in this delivery
- Added a stable `result_id` to the canonical Data Pack record, the manifest provenance, the execution result, and the Tool Output Envelope. The identifier is deterministic for a run/tool/execution tuple.
- Removed the Data Pack reference from the Tool Output Envelope `artifacts` collection. `result.data_ref` is the only Data Pack locator; `artifacts` is reserved for independently managed binary outputs.
- Corrected Data Pack attachment descriptors so `artifact_ref` remains null. Binary chart images are represented by their Artifact attachment only; the redundant, non-renderable Data Pack image card is not registered.
- Passed registered binary artifact descriptors into the Tool Output Envelope.
- Corrected successful envelope status to `succeeded`.
- Corrected Runner handling of `reference_only` receipts. A reference-only receipt is the expected compact-context state and no longer blocks finalization by itself. The Agent uses `data_access` when source rows are needed; actual Data Access failures remain tool failures and follow normal recovery.
- Rejected symlink-backed Result Store and manifest reads to prevent storage references from traversing filesystem links.
- Added regression assertions for result identity, Data Pack/Artifact separation, binary-only attachment behavior, and success status.

## Explicit limits

- Canonical tool results are still committed as JSON envelopes. JSONL segments are a secondary indexed representation for list fields, not a replacement for canonical storage.
- Query filtering and aggregates are streaming. Sorting requires retaining the matched rows and is bounded by `max_scan_rows`; it is not an external-memory sort.
- Parquet and DuckDB are not yet implemented as storage backends. Their introduction requires a separately tested backend adapter and lifecycle policy.
- The Frontend currently provides generic renderers for the supported presentation types; PDF/Office native document rendering and Parquet-specific grid rendering are not claimed as complete.
- `runner.php` remains a large orchestration function because its mutable execution state and recovery transitions are tightly coupled. Splitting it mechanically would increase regression risk; the new Data Access and Attachment boundaries are extracted into dedicated modules instead.
