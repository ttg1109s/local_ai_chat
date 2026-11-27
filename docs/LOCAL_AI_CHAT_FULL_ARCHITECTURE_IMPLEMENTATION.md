# Local AI Chat — Architecture Implementation

## Runtime flow

The Stateful Incremental Agent Loop remains the only orchestration path. Managed Tool calls commit their result through Result Store and Execution Store. Manifest Manager writes run-scoped field metadata and JSONL segments. The model receives a compact receipt and `data_ref`; it never receives a hydrated Data Pack payload.

`data_access` and `verify_result` are Runtime-managed system tools, not user-selectable permissions. Runtime injects them into the model-callable catalog. `data_access` routes `inspect`, `read`, `query`, and `verify` through Data Flow Controller into Data Access; Data Access enforces run ownership and performs bounded selective operations. Runtime requires access to each committed Data Pack before finalization. `verify_result` independently recomputes arithmetic and recounts exact returned row arrays; applicable checks are enforced before finalization.

## Data Pack contracts

- `lac_data_pack_put(runId, tool, executionId, result)` commits the canonical result and builds its manifest.
- `lac_data_pack_manifest_get(ref, runId)` enforces run scope.
- `lac_data_pack_inspect(ref, runId)` returns metadata without reading the canonical payload.
- `lac_data_pack_read_segmented(ref, runId, field, offset, limit)` reads intersecting JSONL segments, validates names, rejects symlinks, and verifies SHA-256.
- `lac_data_pack_query(ref, runId, field, query)` streams the complete segmented field for filtering and aggregates. Sorting is bounded by a byte budget; page materialization is bounded by response bytes.
- `lac_data_pack_resolve_value()` rejects bare references. It permits only selective reads from segmented manifest fields or explicit query descriptors.

## Presentation contracts

Attachment descriptors are independent registry records. Conversation ownership is required; message ownership is optional when a run or execution owns the result. Conversation history returns the registry independently from messages. The Frontend consumes descriptors through the attachment-content API. Data Pack attachments display bounded previews; image artifacts are registered as image attachments and served through the existing artifact byte endpoint.

## Storage and lifecycle

- `storage/agent-results/`: canonical JSON result envelopes.
- `storage/agent-manifests/`: manifests and JSONL segments.
- `storage/attachments/`: attachment descriptors only.
- Run cleanup removes canonical results and their manifest/segment sidecars.

## Operational requirements and limits

The PHP process must be able to write all three storage directories. Data Pack result JSON remains canonical; JSONL segments are a selective-access index, not a Parquet/DuckDB backend. Filtering and aggregates are streaming; sorting retains matched rows under an explicit byte cap. There is no SQL planner, Parquet backend, external-memory sort, or universal native PDF/Office renderer. These are intentionally not claimed as implemented.

The large CSS bundle has been split into ordered base, UI, and feature stylesheets. Attachment presentation logic has been extracted from the main application script into `assets/attachments.js`. The Agent Loop runner remains a single state machine because its recovery and execution transitions share mutable invariants; it was not mechanically split into brittle fragments.
