<?php
declare(strict_types=1);

/**
 * Optional PDO-backed evidence persistence. Source IDs are content-addressed;
 * stored website text is evidence only and must never be promoted to instructions.
 */
function lac_web_source_store(PDO $pdo, array $sources): array {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['sqlite', 'mysql'], true)) {
        return ['stored' => 0, 'available' => false, 'reason' => 'unsupported_driver'];
    }
    try {
        if ($driver === 'sqlite') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS web_sources (
                source_id VARCHAR(64) PRIMARY KEY, source_url TEXT NOT NULL,
                canonical_url TEXT NOT NULL, title TEXT NOT NULL, content_text TEXT NOT NULL,
                content_hash VARCHAR(64) NOT NULL, provider VARCHAR(80) NOT NULL,
                retrieved_at VARCHAR(40) NOT NULL, metadata_json TEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL
            )");
            $sql = "INSERT OR IGNORE INTO web_sources
                (source_id,source_url,canonical_url,title,content_text,content_hash,provider,retrieved_at,metadata_json,created_at)
                VALUES (:id,:url,:canonical,:title,:content,:hash,:provider,:retrieved,:metadata,:created)";
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS web_sources (
                source_id VARCHAR(64) PRIMARY KEY, source_url TEXT NOT NULL,
                canonical_url TEXT NOT NULL, title VARCHAR(500) NOT NULL, content_text MEDIUMTEXT NOT NULL,
                content_hash CHAR(64) NOT NULL, provider VARCHAR(80) NOT NULL,
                retrieved_at VARCHAR(40) NOT NULL, metadata_json TEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL
            ) CHARACTER SET utf8mb4");
            $sql = "INSERT IGNORE INTO web_sources
                (source_id,source_url,canonical_url,title,content_text,content_hash,provider,retrieved_at,metadata_json,created_at)
                VALUES (:id,:url,:canonical,:title,:content,:hash,:provider,:retrieved,:metadata,:created)";
        }
        $stmt = $pdo->prepare($sql);
        $stored = 0;
        foreach ($sources as $source) {
            if (!is_array($source) || empty($source['source_id'])) continue;
            $stmt->execute([
                ':id' => $source['source_id'], ':url' => $source['source_url'] ?? '',
                ':canonical' => $source['canonical_url'] ?? '', ':title' => $source['title'] ?? '',
                ':content' => $source['text'] ?? '', ':hash' => $source['content_hash'] ?? '',
                ':provider' => $source['provider'] ?? 'unknown', ':retrieved' => $source['retrieved_at'] ?? gmdate('c'),
                ':metadata' => json_encode($source['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ':created' => gmdate('c')
            ]);
            $stored += $stmt->rowCount() > 0 ? 1 : 0;
        }
        return ['stored' => $stored, 'available' => true];
    } catch (Throwable $e) {
        // Evidence persistence must not make otherwise successful web research fail.
        return ['stored' => 0, 'available' => false, 'reason' => 'storage_error'];
    }
}
