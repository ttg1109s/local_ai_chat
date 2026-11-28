#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""Bounded web research via Playwright + installed Microsoft Edge."""
import argparse, json, re, socket, ipaddress, time, sys
from urllib.parse import urlparse, parse_qs, quote, urljoin
from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout

BLOCKED_HOSTS={"localhost","localhost.localdomain"}
def public_http_url(raw):
    try:
        p=urlparse(raw)
        if p.scheme not in ("http","https") or not p.hostname or p.username or p.password:return False
        host=p.hostname.lower().rstrip(".")
        if host in BLOCKED_HOSTS or host.endswith((".local",".internal",".localhost",".test")):return False
        infos=socket.getaddrinfo(host,p.port or (443 if p.scheme=="https" else 80),type=socket.SOCK_STREAM)
        return bool(infos) and all(ipaddress.ip_address(x[4][0].split("%")[0]).is_global for x in infos)
    except Exception:return False

def normalize_result(href,base):
    if not href:return ""
    href=urljoin(base,href)
    p=urlparse(href)
    if p.path=="/url" and p.netloc.endswith("google.com"):
        href=parse_qs(p.query).get("q",[""])[0]
    if p.netloc.endswith("bing.com") and p.path=="/ck/a":
        encoded=parse_qs(p.query).get("u",[""])[0]
        if encoded.startswith("a1"):
            try:
                import base64
                encoded=encoded[2:]
                encoded += "=" * (-len(encoded) % 4)
                href=base64.urlsafe_b64decode(encoded).decode("utf-8","ignore")
            except Exception:return ""
        else:
            return ""
    return href.split("#",1)[0]

SEARCH_ERRORS = []

def emit_json(payload):
    # JSON transported over subprocess stdout must be ASCII-safe. ensure_ascii=True
    # escapes Vietnamese characters as \\uXXXX and PHP json_decode restores UTF-8.
    line = json.dumps(payload, ensure_ascii=True, separators=(",", ":"))
    sys.stdout.buffer.write(line.encode("ascii") + b"\n")
    sys.stdout.buffer.flush()

def collect_search(page,engine,query,limit):
    urls={
      "google":"https://www.google.com/search?q="+quote(query),
      "bing":"https://www.bing.com/search?q="+quote(query),
      "duckduckgo":"https://html.duckduckgo.com/html/?q="+quote(query)
    }
    started=time.perf_counter()
    try:
        response=page.goto(urls[engine],wait_until="domcontentloaded",timeout=22000)
        if response and response.status>=400:
            SEARCH_ERRORS.append({"engine":engine,"http_status":response.status,"duration_ms":round((time.perf_counter()-started)*1000)})
            return []
        page.wait_for_timeout(700)
        selectors={"google":"a:has(h3)","bing":"li.b_algo h2 a","duckduckgo":"a.result__a"}
        links=page.locator(selectors[engine]);found=[]
        for i in range(min(links.count(),limit*4)):
            a=links.nth(i)
            try:
                title=(a.inner_text(timeout=1500) or "").strip()
                href=normalize_result(a.get_attribute("href"),page.url)
                if title and public_http_url(href):
                    host=urlparse(href).hostname or ""
                    if not any(host==x or host.endswith("."+x) for x in ("google.com","bing.com","duckduckgo.com")):
                        found.append({"title":title[:180],"url":href})
                if len(found)>=limit:break
            except Exception:continue
        return found
    except Exception as exc:
        SEARCH_ERRORS.append({"engine": engine, "error": str(exc)[:240]})
        return []

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--query",required=True)
    ap.add_argument("--results",type=int,default=7)
    ap.add_argument("--pages",type=int,default=5)
    ap.add_argument("--mode",choices=("basic","advanced","deep"),default="basic")
    args=ap.parse_args()
    # Search depth controls engine breadth and evidence volume, not crawler exposure.
    mode_limits={"basic":(5,3,("bing","google")),
                 "advanced":(8,5,("bing","google","duckduckgo")),
                 "deep":(12,7,("bing","google","duckduckgo"))}
    mode_results,mode_pages,mode_engines=mode_limits[args.mode]
    result_limit=max(1,min(args.results,mode_results,12))
    page_limit=max(1,min(args.pages,mode_pages,7))
    search_started=time.perf_counter()
    sources=[];errors=[]
    with sync_playwright() as p:
        browser=p.chromium.launch(channel="msedge",headless=True)
        context=browser.new_context(locale="vi-VN",ignore_https_errors=False,
          service_workers="block", accept_downloads=False,
          user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0")
        search_page=context.new_page();search_page.set_default_timeout(8000)
        results=[]
        engine_attempts=[]
        seen_search_urls=set()
        per_engine_limit={"basic":result_limit,"advanced":5,"deep":6}[args.mode]
        for engine in mode_engines:
            before=len(SEARCH_ERRORS)
            engine_results=collect_search(search_page,engine,args.query,per_engine_limit)
            added=0
            for item in engine_results:
                canonical=item["url"].split("#",1)[0]
                if canonical not in seen_search_urls:
                    seen_search_urls.add(canonical)
                    results.append(item)
                    added+=1
            engine_attempts.append({"engine":engine,"result_count":len(engine_results),
                                    "unique_added":added,"error_count":len(SEARCH_ERRORS)-before})
            if args.mode=="basic" and results:break
        results=results[:result_limit]
        seen=set()
        for item in results:
            if item["url"] in seen:continue
            seen.add(item["url"])
            tab=context.new_page();tab.set_default_timeout(9000);page_started=time.perf_counter()
            try:
                def guard(route):
                    u=route.request.url
                    parsed_request=urlparse(u)
                    if parsed_request.scheme not in ("http","https") or route.request.resource_type in ("image","media","font") or not public_http_url(u):
                        route.abort()
                    else:route.continue_()
                tab.route("**/*",guard)
                response=tab.goto(item["url"],wait_until="domcontentloaded",timeout=18000)
                final=tab.url
                if not public_http_url(final) or (response and response.status>=400):
                    tab.close();continue
                try:tab.wait_for_load_state("domcontentloaded",timeout=2500)
                except Exception:pass
                title=(tab.title() or item["title"]).strip()
                body=tab.locator("body").inner_text(timeout=7000)
                body=re.sub(r"\n{3,}","\n\n",body)
                body=re.sub(r"[ \t]{2,}"," ",body).strip()
                if len(body)>250:
                    sources.append({"title":title[:180],"url":final,"text":body[:8500],"depth":0})
                # Follow up to two relevant same-host internal pages per search result.
                # Every URL is DNS/IP validated again and the request guard remains active.
                base_host=(urlparse(final).hostname or "").lower().rstrip(".")
                internal=[]
                try:
                    anforrs=tab.locator("a[href]")
                    for j in range(min(anforrs.count(),180)):
                        a=anforrs.nth(j)
                        href=urljoin(final,a.get_attribute("href") or "")
                        label=(a.inner_text(timeout=500) or "").strip()
                        hp=urlparse(href)
                        if hp.hostname and hp.hostname.lower().rstrip(".")==base_host and hp.scheme in ("http","https") and public_http_url(href):
                            internal.append({"url":href.split("#",1)[0],"title":label[:180] or href})
                except Exception: pass
                tab.close()
                query_terms=[x.lower() for x in re.findall(r"[\w]{3,}",args.query)]
                ranked=[]
                for link in internal:
                    score=sum(1 for term in query_terms if term in (link["title"]+" "+link["url"]).lower())
                    if score: ranked.append((score,link))
                ranked.sort(key=lambda x:x[0],reverse=True)
                for _,link in ranked[:2]:
                    if len(sources)>=page_limit: break
                    if any(x["url"]==link["url"] for x in sources): continue
                    child=context.new_page();child.set_default_timeout(9000)
                    try:
                        child.route("**/*",guard)
                        child_response=child.goto(link["url"],wait_until="domcontentloaded",timeout=15000)
                        child_final=child.url
                        if not public_http_url(child_final) or (urlparse(child_final).hostname or "").lower().rstrip(".")!=base_host or (child_response and child_response.status>=400):
                            child.close(); continue
                        child_title=(child.title() or link["title"]).strip()
                        child_body=child.locator("body").inner_text(timeout=6000)
                        child_body=re.sub(r"\n{3,}","\n\n",child_body)
                        child_body=re.sub(r"[ \t]{2,}"," ",child_body).strip()
                        if len(child_body)>250:
                            sources.append({"title":child_title[:180],"url":child_final,"text":child_body[:6500],"depth":1})
                        child.close()
                    except Exception:
                        try: child.close()
                        except Exception: pass
                errors.append({"stage":"extract_page","url_host":urlparse(final).hostname,"duration_ms":round((time.perf_counter()-page_started)*1000),"text_chars":len(body)})
                if len(sources)>=page_limit:break
            except Exception as exc:
                errors.append({"stage":"open_page","url_host":urlparse(item["url"]).hostname,"duration_ms":round((time.perf_counter()-page_started)*1000),"error":str(exc)[:160]})
                try:tab.close()
                except Exception:pass
        context.close();browser.close()
    # Error entries are dictionaries; format them explicitly instead of passing
    # dictionaries to str.join(). Keep diagnostics useful but bounded.
    error_details = []
    for entry in errors[:2]:
        if isinstance(entry, dict):
            stage = str(entry.get("stage", "web_search"))
            host = str(entry.get("url_host", ""))
            detail = str(entry.get("error", ""))
            if detail:
                error_details.append(f"{stage} ({host}): {detail}" if host else f"{stage}: {detail}")
            else:
                error_details.append(f"{stage} ({host})" if host else stage)
        else:
            error_details.append(str(entry))
    error_message = None
    if not sources:
        suffix = "; ".join(error_details) if error_details else "Check the Internet/Edge connection."
        error_message = "No suitable page was found. " + suffix
    emit_json({"query":args.query,"mode":args.mode,"sources":sources,"engine_used":",".join(x["engine"] for x in engine_attempts if x.get("unique_added",0)) or None,
      "engine_attempts":engine_attempts,"search_errors":SEARCH_ERRORS[:6],"page_errors":errors[:6],
      "duration_ms":round((time.perf_counter()-search_started)*1000),"source_count":len(sources),
      "error":error_message})

if __name__=="__main__":
    try:main()
    except Exception as e:
        try:
            emit_json({"error":str(e)[:500],"sources":[]})
        except Exception:
            # Last-resort ASCII-only diagnostic; never let error reporting mask the root error.
            sys.stdout.buffer.write(b'{"error":"web_search_worker_failed","sources":[]}\n')
            sys.stdout.buffer.flush()
