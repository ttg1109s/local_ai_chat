import sys
import json
from urllib.parse import urlparse, urljoin
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent / "web"))
from url_validator import validate_public_url
from playwright.sync_api import sync_playwright


def validate_target(value, allowed_host=None):
    parsed, host, _addresses, clean_url = validate_public_url(value, allowed_host)
    return parsed, host, clean_url


def scrape_website(target_url):
    parsed, allowed_host, clean_target = validate_target(target_url)
    target_url = clean_target
    with sync_playwright() as p:
        try:
            browser = p.chromium.launch(channel="msedge", headless=True)
        except Exception as exc:
            raise RuntimeError(
                "Could not launch Microsoft Edge through Playwright. "
                "Make sure Edge is installed for the Apache account, then run "
                "`python -m playwright install msedge`. Details: " + str(exc)[:500]
            ) from exc
        try:
            context = browser.new_context(
                java_script_enabled=True,
                ignore_https_errors=False,
                service_workers="block",
                accept_downloads=False,
                user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/156.0.0.0 Safari/537.36 Edg/156.0.0.0"
            )
            page = context.new_page()

            def guard(route):
                try:
                    request_url = route.request.url
                    req, request_host, _ips, _clean = validate_target(request_url, allowed_host)
                    # Enforce configured host on every request, including redirects and subresources.
                    if request_host != allowed_host or req.scheme not in ("http", "https"):
                        return route.abort()
                    route.continue_()
                except Exception:
                    route.abort()

            page.route("**/*", guard)
            response = page.goto(target_url, wait_until="domcontentloaded", timeout=30000)
            # Wait for the document and common content containers; do not require
            # networkidle because analytics/streaming pages may never become idle.
            try:
                page.wait_for_load_state("domcontentloaded", timeout=8000)
            except Exception:
                pass
            try:
                page.locator("body").wait_for(state="visible", timeout=8000)
            except Exception:
                pass
            try:
                page.wait_for_function(
                    "() => document.readyState === 'complete' || "
                    "(document.body && (document.body.innerText || '').trim().length > 80)",
                    timeout=10000
                )
            except Exception:
                pass
            # A short settle period allows client-rendered content to hydrate.
            page.wait_for_timeout(1400)
            if response is not None and response.status >= 400:
                raise RuntimeError(f"Website returned HTTP {response.status}.")
            final_url = page.url
            _final, final_host, _ips, final_url = validate_target(final_url, allowed_host)
            if final_host != allowed_host:
                raise ValueError("The page redirected to a different domain. The redirect was blocked.")

            data = page.evaluate("""() => {
                const clean = value => (value || '').replace(/\\s+/g, ' ').trim();
                const selectors = [
                    'main', '[role="main"]', 'article', '#content', '#mw-content-text',
                    '.mw-parser-output', '.article-content', '.entry-content',
                    '.post-content', '.content', 'body'
                ];
                let candidates = [];
                for (const selector of selectors) {
                    for (const node of document.querySelectorAll(selector)) {
                        const clone = node.cloneNode(true);
                        clone.querySelectorAll(
                            'script,style,noscript,svg,canvas,iframe,form,nav,footer,header,aside,button,'
                            + '[aria-hidden="true"],.mw-editsection,.navbox,.metadata,.reference'
                        ).forEach(x => x.remove());
                        const value = clean(clone.innerText || clone.textContent || '');
                        if (value.length > 0) candidates.push(value);
                    }
                }
                candidates.sort((a,b) => b.length - a.length);
                const text = candidates[0] || clean(document.body?.innerText || document.body?.textContent || '');
                const links = Array.from(document.querySelectorAll('a[href]')).map(a => ({
                    text: clean(a.innerText || a.textContent || ''),
                    url: a.href
                })).filter(x => x.text && x.url).slice(0, 150);
                return {
                    title: document.title || '',
                    text,
                    body_text_length: clean(document.body?.innerText || '').length,
                    ready_state: document.readyState,
                    links
                };
            }""")
            if not (data.get("text") or "").strip():
                # Diagnostic snapshot is deliberately bounded and excludes page HTML.
                raise RuntimeError(
                    "The page opened, but its DOM contains no readable text. "
                    f"(readyState={data.get('ready_state')}, bodyChars={data.get('body_text_length', 0)}). "
                    "The page may require sign-in, a CAPTCHA, or content rendered in an inaccessible area."
                )
            links = []
            for item in data.get("links", []):
                try:
                    link_url = urljoin(final_url, item.get("url", ""))
                    lp = urlparse(link_url)
                    if lp.hostname and lp.hostname.lower().rstrip(".") == allowed_host and lp.scheme in ("http", "https"):
                        links.append({"text": item.get("text", "")[:300], "url": link_url})
                except Exception:
                    pass
            return {
                "success": True,
                "url": final_url,
                "http_status": response.status if response else None,
                "title": data.get("title", "")[:500],
                "text": data.get("text", "")[:30000],
                "body_text_length": int(data.get("body_text_length", 0)),
                "ready_state": data.get("ready_state", ""),
                "links_count": len(links),
                "links": links[:60]
            }
        finally:
            browser.close()


if __name__ == "__main__":
    try:
        if len(sys.argv) < 2:
            raise ValueError("URL is required.")
        result = scrape_website(sys.argv[1])
        print(json.dumps(result, ensure_ascii=True))
    except Exception as exc:
        print(json.dumps({"success": False, "error": str(exc)}, ensure_ascii=True))
        sys.exit(1)
