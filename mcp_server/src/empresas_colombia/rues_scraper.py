"""RUES HTML scraper fallback.

RUES (Registro Único Empresarial y Social) is the official business
registry site. When Socrata does not return a usable candidate list, the
server falls back to POSTing the RUES search form and parsing the
returned HTML for ``<tr class="resultado-empresa">`` rows.

The scraper is intentionally permissive: any transport error or
non-2xx status returns an empty list. Real-world RUES markup may shift
between requests; the parser MUST degrade gracefully when no rows are
found rather than raise.
"""

from __future__ import annotations

from typing import Any, Optional

import httpx
from bs4 import BeautifulSoup


DEFAULT_BASE_URL = "https://www.rues.org.co/"
DEFAULT_TIMEOUT_S = 12.0
DEFAULT_LIMIT = 5
USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/120.0 Safari/537.36"
)


def _build_form_payload(keyword: str) -> dict[str, str]:
    """Build the RUES search form payload.

    The RUES site accepts ``txtCriterio`` (the search term) and a
    ``btnBuscar`` button marker. The site may add extra fields; we send
    only the two essential ones to keep the payload minimal.
    """
    return {
        "txtCriterio": keyword,
        "btnBuscar": "Buscar",
    }


def _build_headers(base_url: str) -> dict[str, str]:
    return {
        "User-Agent": USER_AGENT,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "es-CO,es;q=0.9,en;q=0.5",
        "Referer": base_url,
        "Origin": base_url.rstrip("/"),
        "Content-Type": "application/x-www-form-urlencoded",
    }


def _parse_rows(html: str, limit: int) -> list[dict[str, Any]]:
    """Parse the RUES HTML response and extract up to ``limit`` rows."""
    soup = BeautifulSoup(html or "", "html.parser")
    rows = soup.select("tr.resultado-empresa")
    out: list[dict[str, Any]] = []
    for row in rows[: max(0, int(limit))]:
        razon = row.select_one(".razon-social, .razon_social")
        nit = row.select_one(".nit")
        municipio = row.select_one(".municipio")
        out.append(
            {
                "razon_social": (razon.get_text(strip=True) if razon else "") or "",
                "nit": (nit.get_text(strip=True) if nit else "") or "",
                "municipio": (municipio.get_text(strip=True) if municipio else None) or None,
                "fuente": "rues",
            }
        )
    return out


async def rues_scrape(
    keyword: str,
    *,
    limit: int = DEFAULT_LIMIT,
    base_url: str = DEFAULT_BASE_URL,
    timeout_s: float = DEFAULT_TIMEOUT_S,
    client: Optional[httpx.AsyncClient] = None,
) -> list[dict[str, Any]]:
    """Search RUES HTML for the given ``keyword``.

    Args:
        keyword: Free-text search term (razón social, NIT, or partial).
        limit: Maximum rows to return (default 5).
        base_url: Override RUES endpoint (tests).
        timeout_s: Per-request timeout in seconds.
        client: Optional pre-built httpx client.

    Returns:
        A list of dicts ``{razon_social, nit, municipio, fuente}``.
        Empty list on any failure (transport, status, parse).
    """
    if not keyword:
        return []

    headers = _build_headers(base_url)
    payload = _build_form_payload(keyword)

    owns_client = client is None
    if owns_client:
        client = httpx.AsyncClient(timeout=timeout_s, follow_redirects=True)

    try:
        response = await client.post(base_url, data=payload, headers=headers)
    except httpx.HTTPError:
        return []
    if owns_client:
        await client.aclose()

    if response.status_code >= 400:
        return []

    return _parse_rows(response.text, limit=limit)


__all__ = ["rues_scrape", "DEFAULT_BASE_URL", "DEFAULT_TIMEOUT_S", "DEFAULT_LIMIT"]