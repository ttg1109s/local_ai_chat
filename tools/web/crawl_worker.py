"""Bounded same-origin crawler built on the guarded Playwright reader."""
from __future__ import annotations
from collections import deque
from urllib.parse import urlsplit, urlunsplit
import time


def crawl_site(start_url: str, max_pages: int = 8, max_depth: int = 1,
               max_chars_per_page: int = 20000, delay_seconds: float = 0.5) -> dict:
    # Import lazily so non-web Python tools do not require Playwright at import time.
    from website_connector import scrape_website, validate_target

    max_pages = max(1, min(int(max_pages), 20))
    max_depth = max(0, min(int(max_depth), 3))
    max_chars_per_page = max(1000, min(int(max_chars_per_page), 30000))
    _parsed, allowed_host, clean_start = validate_target(start_url)
    queue = deque([(clean_start, 0)])
    seen = set()
    pages = []
    errors = []
    started = time.monotonic()

    while queue and len(pages) < max_pages:
        url, depth = queue.popleft()
        try:
            _p, host, clean = validate_target(url, allowed_host)
            if host != allowed_host or clean in seen:
                continue
            seen.add(clean)
            result = scrape_website(clean)
            if not result.get("success"):
                errors.append({"url": clean, "error": "Page fetch failed"})
                continue
            pages.append({
                "title": str(result.get("title", ""))[:500],
                "text": str(result.get("text", ""))[:max_chars_per_page],
                "url": result.get("url", clean),
                "http_status": result.get("http_status"),
                "depth": depth,
                "content_type": "text/html",
                "provider": "bounded_playwright_crawler",
            })
            if depth < max_depth:
                for link in result.get("links", []):
                    candidate = str(link.get("url", "")).strip()
                    try:
                        _lp, link_host, canonical = validate_target(candidate, allowed_host)
                        if link_host == allowed_host and canonical not in seen:
                            queue.append((canonical, depth + 1))
                    except Exception:
                        continue
            # Avoid hammering a configured origin.
            if queue and len(pages) < max_pages:
                time.sleep(delay_seconds)
        except Exception as exc:
            errors.append({"url": str(url)[:1000], "error": str(exc)[:500]})
    return {
        "success": bool(pages),
        "error": None if pages else "No pages were successfully crawled.",
        "start_url": clean_start,
        "host": allowed_host,
        "pages": pages,
        "page_count": len(pages),
        "visited_count": len(seen),
        "errors": errors[:50],
        "limits": {"max_pages": max_pages, "max_depth": max_depth,
                   "max_chars_per_page": max_chars_per_page},
        "trust": "untrusted_external_data",
        "instruction_authority": False,
        "duration_ms": int((time.monotonic() - started) * 1000),
    }
