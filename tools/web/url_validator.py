"""Strict URL and DNS policy for the web browser worker.

Important: DNS validation here is a defense-in-depth check, not DNS pinning.
A network egress proxy/firewall must enforce the same policy at connection time
to close the DNS-rebinding/TOCTOU gap. See docs/web-egress-security.md.
"""
from __future__ import annotations

import ipaddress
import socket
from urllib.parse import urlsplit, urlunsplit


ALLOWED_SCHEMES = {"http", "https"}
ALLOWED_PORTS = {80, 443}
BLOCKED_SUFFIXES = (".localhost", ".local", ".internal", ".test", ".invalid")


def _normalise_host(host: str) -> str:
    host = host.rstrip(".").lower()
    if not host or "%" in host:
        raise ValueError("Invalid host name.")
    try:
        # Canonicalize internationalized domain names before DNS resolution.
        host = host.encode("idna").decode("ascii")
    except UnicodeError as exc:
        raise ValueError("Invalid IDN domain.") from exc
    if host == "localhost" or host.endswith(BLOCKED_SUFFIXES):
        raise ValueError("Internal domains are not allowed.")
    return host


def resolve_public_addresses(host: str, port: int) -> set[str]:
    """Resolve every returned A/AAAA address; reject if any address is non-global."""
    try:
        literal = ipaddress.ip_address(host)
        addresses = {str(literal)}
    except ValueError:
        try:
            records = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
        except socket.gaierror as exc:
            raise ValueError("Could not resolve the domain.") from exc
        addresses = {str(ipaddress.ip_address(item[4][0].split("%", 1)[0]))
                     for item in records}
    if not addresses:
        raise ValueError("The domain has no IP address.")
    for value in addresses:
        ip = ipaddress.ip_address(value)
        if not ip.is_global:
            raise ValueError("The URL resolves to a private, internal, or non-public IP address.")
    return addresses


def validate_public_url(value: str, allowed_host: str | None = None) -> tuple:
    if not isinstance(value, str) or not value or len(value) > 8192:
        raise ValueError("URL is invalid or exceeds the limit.")
    parsed = urlsplit(value.strip())
    scheme = parsed.scheme.lower()
    if scheme not in ALLOWED_SCHEMES or not parsed.hostname:
        raise ValueError("Only valid HTTP/HTTPS URLs are accepted.")
    if parsed.username is not None or parsed.password is not None:
        raise ValueError("URL must not contain credentials.")
    host = _normalise_host(parsed.hostname)
    try:
        port = parsed.port or (443 if scheme == "https" else 80)
    except ValueError as exc:
        raise ValueError("Invalid URL port.") from exc
    if port not in ALLOWED_PORTS:
        raise ValueError("Only ports 80 and 443 are allowed.")
    if allowed_host is not None and host != _normalise_host(allowed_host):
        raise ValueError("The request is outside the allowed domain.")
    addresses = resolve_public_addresses(host, port)
    # Remove fragments: they are not sent to the server and should not fork cache keys.
    clean = urlunsplit((scheme, parsed.netloc, parsed.path or "/", parsed.query, ""))
    return parsed, host, addresses, clean
