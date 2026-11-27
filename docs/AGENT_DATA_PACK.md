# Agent Data Pack and Data Access

Data Packs are durable, run-scoped outputs of managed tool executions. The canonical JSON result is stored in the Result Store; the Manifest Manager commits a field index and JSONL segments for list-valued fields. Every segment carries a SHA-256 checksum and can be independently paginated.

## Model boundary

The Agent Loop never hydrates a Data Pack into model context. A tool receipt contains the `data_ref`, execution receipt, compact preview, and manifest metadata only. Full-payload reference substitution is disabled. A `$data_ref` argument must specify a selective `$path` that resolves to a segmented field, or a `$query` plus `$field`; the Runtime enforces the active run scope before access.

The model-facing `data_access` tool is the supported interface. It exposes `inspect`, `read`, `query`, and `verify`. Query filtering and aggregation stream through every committed segment; sorting retains only matched rows within an explicit scan/memory budget. Pagination returns bounded pages. Unsupported operators, oversized scans, missing manifests, and integrity failures are explicit errors, never partial-success responses.

## Storage and integrity

- `storage/agent-results`: canonical committed result envelopes.
- `storage/agent-manifests`: run-scoped manifests and JSONL segments.
- Result references are validated as `agent-results/<hex>.json`; no arbitrary filesystem paths are accepted.
- Manifest access verifies run ownership. Segment reads validate the generated filename, reject symlinks, and verify SHA-256 before decoding.
- Run cleanup removes canonical results, manifests, and owned segments.

## Presentation

Attachment descriptors are independent records in `storage/attachments`. They may be owned by a conversation, execution, run, message, or a combination; a message ID is optional when execution/run ownership is present. Conversation history returns the conversation-level attachment registry separately from message history. Artifact-backed images are registered as image attachments and rendered through the same attachment UI.

The Frontend consumes attachment descriptors and calls the attachment-content API. It does not read Result Store files directly. The assistant message is not the owner of the attachment; it is optional provenance only.
