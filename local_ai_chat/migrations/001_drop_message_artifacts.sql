-- Destructive cleanup: artifact ownership now lives in the file-backed batch registry.
-- Run once against the application database during deployment.
DROP TABLE IF EXISTS message_artifacts;
