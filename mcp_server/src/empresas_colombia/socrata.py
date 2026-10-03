"""Socrata client for Colombian company registry (``jdru-qe2j``).

Socrata is the open-data platform hosting the ``jdru-qe2j`` dataset (the
authoritative Colombian company registry). The client builds a SoQL
``$where`` LIKE query against the canonical ``razon_social`` column and
returns the parsed JSON list.

The client degrades silently on most failure modes (5xx, 4xx, invalid
JSON, wrong shape) and only surfaces ``SocrataRateLimitError`` on HTTP 429
so the upper layer (the MCP tool) can decide to fall back to RUES HTML
scraping.
"""

from __future__ import annotations

from typing import Any, Iterable, Mapping, Optional

import httpx


DEFAULT_BASE_URL = "https://www.datos.gov.co/resource/jdru-qe2j.json"
DEFAULT_TIMEOUT_S = 10.0
DEFAULT_LIMIT = 5


class SocrataRateLimitError(RuntimeError):
    """Raised when Socrata returns HTTP 429 (Too Many Requests)."""


def _build_headers(app_token: Optional[str]) -> dict[str, str]:
    headers: dict[str, str] = {
        "Accept": "application/json",
        "User-Agent": "empresas-colombia-mcp/0.1 (+https://tecnoinnsoft.com)",
    }
    if app_token:
        headers["X-App-Token"] = app_token
    return headers


def _build_params(razon_social: str, limit: int) -> list[tuple[str, str]]:
    """Build the SoQL parameter list for a LIKE search on razon_social.

    Socrata's SoQL ``LIKE`` operator performs case-insensitive substring
    match against the canonical ``razon_social`` column. Single quotes in
    the input are escaped with a backslash so the SoQL parser does not see
    an unterminated string literal.
    """
    escaped = razon_social.replace("'", "''")
    return [
        ("$where", f"upper(razon_social) LIKE '%{escaped.upper()}%'"),
        ("$limit", str(max(1, int(limit)))),
    ]


async def socrata_query_by_name(
    razon_social: str,
    *,
    limit: int = DEFAULT_LIMIT,
    app_token: Optional[str] = None,
    base_url: str = DEFAULT_BASE_URL,
    timeout_s: float = DEFAULT_TIMEOUT_S,
    client: Optional[httpx.AsyncClient] = None,
) -> list[dict[str, Any]]:
    """Query Socrata for companies whose ``razon_social`` matches ``razon_social``.

    Args:
        razon_social: The name (or partial name) to search for. Case
            insensitive; single quotes are SoQL-escaped.
        limit: Maximum number of records to return. Defaults to 5.
        app_token: Optional Socrata ``X-App-Token``. When omitted, the
            header is not sent (Socrata still serves unauthenticated
            requests at a throttled rate).
        base_url: Override the canonical Socrata endpoint (tests).
        timeout_s: Per-request timeout in seconds.
        client: Optional pre-built httpx client (tests / connection
            pooling). A new client is created per call when omitted.

    Returns:
        A list of record dicts from Socrata. Empty list on any failure
        other than rate limiting (which raises ``SocrataRateLimitError``).
    """
    params = _build_params(razon_social, limit)
    headers = _build_headers(app_token)

    owns_client = client is None
    if owns_client:
        client = httpx.AsyncClient(timeout=timeout_s)

    try:
        response = await client.post(
            base_url,
            params=params,
            headers=headers,
        )
    except httpx.HTTPError:
        # Network/transport failures (timeout, connection reset, DNS).
        return []
    if owns_client:
        await client.aclose()

    if response.status_code == 429:
        raise SocrataRateLimitError(
            f"Socrata rate-limited (HTTP 429) for query={razon_social!r}"
        )

    if response.status_code >= 500 or response.status_code >= 400:
        # 4xx (other than 429) and 5xx → graceful empty result.
        return []

    try:
        payload = response.json()
    except (ValueError, json_invalid(response)):
        return []

    if not isinstance(payload, list):
        return []

    return [record for record in payload if isinstance(record, dict)]


def json_invalid(response: httpx.Response) -> type[ValueError]:
    """Compatibility shim for httpx versions where ``response.json()`` raises
    ``json.JSONDecodeError`` (subclass of ``ValueError``)."""
    return ValueError


__all__ = [
    "socrata_query_by_name",
    "SocrataRateLimitError",
    "DEFAULT_BASE_URL",
    "DEFAULT_TIMEOUT_S",
    "DEFAULT_LIMIT",
]