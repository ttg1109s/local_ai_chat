<?php
declare(strict_types=1);

/**
 * Convert provider-specific web records into a stable, explicitly untrusted
 * evidence envelope. This module does not fetch URLs and is not an SSRF guard.
 */
function lac_web_text_slice(string $value, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

function lac_web_normalize_results(array $items, array $context = []): array {
    $normalized = [];
    $now = gmdate('c');
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $url = trim((string)($item['source_url'] ?? $item['url'] ?? $item['link'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) continue;
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) continue;
        $title = trim((string)($item['title'] ?? $item['name'] ?? ''));
        $content = (string)($item['text'] ?? $item['content'] ?? $item['snippet'] ?? $item['description'] ?? '');
        $content = lac_web_text_slice($content, 120000);
        $hash = hash('sha256', $content);
        $canonical = preg_replace('/#.*$/', '', $url);
        $sourceId = 'src_' . substr(hash('sha256', strtolower($canonical) . "\n" . $hash), 0, 32);
        $normalized[] = [
            'source_id' => $sourceId,
            'title' => lac_web_text_slice($title, 500),
            'text' => $content,
            'source_url' => $url,
            'canonical_url' => $canonical,
            'provider' => lac_web_text_slice((string)($item['provider'] ?? $context['provider'] ?? $context['engine'] ?? 'unknown'), 80),
            'retrieved_at' => (string)($item['retrieved_at'] ?? $context['retrieved_at'] ?? $now),
            'http_status' => isset($item['http_status']) ? (int)$item['http_status'] : null,
            'content_type' => lac_web_text_slice((string)($item['content_type'] ?? ''), 120),
            'content_hash' => $hash,
            'trust' => 'untrusted_external_data',
            'instruction_authority' => false,
            'metadata' => is_array($item['metadata'] ?? null) ? $item['metadata'] : [],
        ];
    }
    return [
        'schema_version' => 'web-evidence.v1',
        'retrieved_at' => $now,
        'count' => count($normalized),
        'sources' => $normalized,
        'errors' => is_array($context['errors'] ?? null) ? $context['errors'] : [],
    ];
}
