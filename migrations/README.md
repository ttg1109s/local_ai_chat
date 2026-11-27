# Database migrations

Run numbered SQL migrations once, in order, against the Local AI Chat database before deploying the matching application archive.

`001_drop_message_artifacts.sql` is intentionally destructive. The message-owned `message_artifacts` table has been removed from the runtime and replaced by the independent Artifact Batch Registry plus Attachment Registry. No legacy rows are migrated.
