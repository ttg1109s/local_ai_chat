import os, sys, types, unittest
from unittest.mock import patch

WEB_DIR = os.path.join(os.path.dirname(__file__), "..", "tools", "web")
TOOLS_DIR = os.path.join(os.path.dirname(__file__), "..", "tools")
sys.path.insert(0, WEB_DIR)
sys.path.insert(0, TOOLS_DIR)
import crawl_worker


class CrawlWorkerTests(unittest.TestCase):
    def test_page_and_depth_limits(self):
        fake_reader = types.ModuleType("website_connector")
        def validate(url, allowed_host=None):
            from urllib.parse import urlsplit, urlunsplit
            p = urlsplit(url)
            host = p.hostname
            if allowed_host and host != allowed_host:
                raise ValueError("cross host")
            return p, host, urlunsplit((p.scheme, p.netloc, p.path or "/", p.query, ""))
        def scrape(url):
            return {"success": True, "url": url, "title": "T", "text": "content",
                    "links": [{"url": "https://example.com/next", "text": "next"},
                              {"url": "https://evil.example/out", "text": "out"}]}
        fake_reader.validate_target = validate
        fake_reader.scrape_website = scrape
        with patch.dict(sys.modules, {"website_connector": fake_reader}), patch.object(crawl_worker.time, "sleep"):
            result = crawl_worker.crawl_site("https://example.com/", max_pages=2, max_depth=1)
        self.assertTrue(result["success"])
        self.assertEqual(result["page_count"], 2)
        self.assertLessEqual(result["page_count"], 2)
        self.assertFalse(result["instruction_authority"])

    def test_limits_are_clamped(self):
        fake_reader = types.ModuleType("website_connector")
        def validate(url, allowed_host=None):
            from urllib.parse import urlsplit
            return urlsplit(url), "example.com", "https://example.com/"
        fake_reader.validate_target = validate
        fake_reader.scrape_website = lambda url: {"success": True, "url": url, "title": "T", "text": "x", "links": []}
        with patch.dict(sys.modules, {"website_connector": fake_reader}):
            result = crawl_worker.crawl_site("https://example.com/", max_pages=999, max_depth=999)
        self.assertEqual(result["limits"]["max_pages"], 20)
        self.assertEqual(result["limits"]["max_depth"], 3)
        self.assertTrue(result["success"])

    def test_empty_crawl_reports_failure(self):
        fake_reader = types.ModuleType("website_connector")
        def validate(url, allowed_host=None):
            from urllib.parse import urlsplit
            return urlsplit(url), "example.com", "https://example.com/"
        fake_reader.validate_target = validate
        fake_reader.scrape_website = lambda url: {"success": False, "error": "unavailable"}
        with patch.dict(sys.modules, {"website_connector": fake_reader}):
            result = crawl_worker.crawl_site("https://example.com/", max_pages=1, max_depth=0)
        self.assertFalse(result["success"])
        self.assertEqual(result["page_count"], 0)
        self.assertEqual(result["error"], "No pages were successfully crawled.")
        self.assertEqual(len(result["errors"]), 1)


if __name__ == "__main__":
    unittest.main()
