import unittest
import os, sys
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "tools", "web"))
from unittest.mock import patch
from url_validator import _normalise_host, resolve_public_addresses, validate_public_url

class UrlValidatorTests(unittest.TestCase):
    def test_reject_non_http_schemes(self):
        for url in ("file:///etc/passwd", "ftp://example.com/a", "javascript:alert(1)"):
            with self.subTest(url=url), self.assertRaises(ValueError):
                validate_public_url(url)

    def test_reject_credentials(self):
        with self.assertRaises(ValueError):
            validate_public_url("https://user:pass@example.com")

    def test_reject_nonstandard_ports(self):
        with self.assertRaises(ValueError):
            validate_public_url("https://example.com:8443")

    def test_host_allowlist(self):
        with patch("url_validator.resolve_public_addresses", return_value={"93.184.216.34"}):
            with self.assertRaises(ValueError):
                validate_public_url("https://example.com", "other.example")

    def test_reject_private_resolution(self):
        with patch("url_validator.socket.getaddrinfo", return_value=[
            (None, None, None, None, ("93.184.216.34", 443)),
            (None, None, None, None, ("10.0.0.4", 443))]):
            with self.assertRaises(ValueError):
                resolve_public_addresses("example.com", 443)

    def test_accept_public_resolution_and_strip_fragment(self):
        with patch("url_validator.resolve_public_addresses", return_value={"93.184.216.34"}):
            _parsed, host, ips, clean = validate_public_url("https://example.com/a?q=1#frag")
        self.assertEqual(host, "example.com")
        self.assertEqual(ips, {"93.184.216.34"})
        self.assertEqual(clean, "https://example.com/a?q=1")

    def test_reject_local_names(self):
        for host in ("localhost", "printer.local", "service.internal"):
            with self.subTest(host=host), self.assertRaises(ValueError):
                _normalise_host(host)

if __name__ == "__main__":
    unittest.main()
