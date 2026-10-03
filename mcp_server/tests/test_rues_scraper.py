"""Tests for the RUES HTML scraper fallback.

RUES is the Colombian business registry; its search page returns HTML that
the scraper parses with BeautifulSoup. The scraper MUST:

    * POST to ``https://www.rues.org.co/`` with the search keyword.
    * Send a realistic User-Agent and Referer header.
    * Parse rows and return at most 5 candidate dicts.
    * Return ``[]`` on transport errors or 5xx (graceful).
"""

from __future__ import annotations

from pathlib import Path

import httpx
import pytest


FIXTURES = Path(__file__).resolve().parent / "fixtures"


@pytest.fixture
def rues_module():
    """Lazy import so RED phase fails clearly."""
    from empresas_colombia import rues_scraper

    return rues_scraper


@pytest.fixture
def mock_transport():
    import respx

    with respx.mock(assert_all_called=False) as router:
        yield router


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_rues_module_importable(rues_module) -> None:
    """rues_scraper module MUST expose the public surface."""
    assert hasattr(rues_module, "rues_scrape")


@pytest.mark.asyncio
async def test_rues_scrape_returns_empty_list_on_5xx(
    rues_module, mock_transport
) -> None:
    """5xx MUST be swallowed → empty list."""
    mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(503, text="Service Unavailable")
    )

    result = await rues_module.rues_scrape("tecnoinnsoft")
    assert result == []


@pytest.mark.asyncio
async def test_rues_scrape_returns_empty_list_on_4xx(
    rues_module, mock_transport
) -> None:
    """4xx MUST be swallowed → empty list."""
    mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(400, text="Bad Request")
    )

    result = await rues_module.rues_scrape("foo")
    assert result == []


@pytest.mark.asyncio
async def test_rues_scrape_returns_empty_on_no_results(
    rues_module, mock_transport
) -> None:
    """Empty result page MUST return ``[]``."""
    html = "<html><body><table><tr><td>Sin resultados</td></tr></table></body></html>"
    mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(200, text=html)
    )

    result = await rues_module.rues_scrape("nonexistent-company-xyz")
    assert result == []


@pytest.mark.asyncio
async def test_rues_scrape_parses_html_rows_correctly(
    rues_module, mock_transport
) -> None:
    """HTML with ``<tr.resultado-empresa>`` rows MUST be parsed correctly."""
    fixture_html = (FIXTURES / "rues_sample.html").read_text(encoding="utf-8")
    assert fixture_html, "fixture HTML must not be empty"

    mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(200, text=fixture_html)
    )

    result = await rues_module.rues_scrape("tecnoinnsoft")
    assert isinstance(result, list)
    assert len(result) >= 1, "fixture must contain at least one row"
    first = result[0]
    # Each parsed row MUST carry at least razon_social, nit, fuente.
    assert "razon_social" in first
    assert "nit" in first
    assert "fuente" in first
    assert first["fuente"] == "rues"


@pytest.mark.asyncio
async def test_rues_scrape_respects_limit(
    rues_module, mock_transport
) -> None:
    """The scraper MUST cap results at ``limit`` (default 5)."""
    # Build a fixture with 10 rows; the scraper MUST return at most 5.
    rows = "".join(
        f'<tr class="resultado-empresa">'
        f'<td class="razon-social">EMPRESA {i} SA</td>'
        f'<td class="nit">90000000{i}</td>'
        f'<td class="municipio">Bogota</td>'
        f"</tr>"
        for i in range(10)
    )
    html = f"<html><body><table>{rows}</table></body></html>"

    mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(200, text=html)
    )

    result = await rues_module.rues_scrape("foo")
    assert len(result) <= 5


@pytest.mark.asyncio
async def test_rues_scrape_uses_post_method(
    rues_module, mock_transport
) -> None:
    """The scraper MUST use POST (form-encoded search)."""
    route = mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(200, text="<html><body></body></html>")
    )

    await rues_module.rues_scrape("foo")

    assert route.called
    assert route.calls[0].request.method == "POST"


@pytest.mark.asyncio
async def test_rues_scrape_sends_referer_and_user_agent(
    rues_module, mock_transport
) -> None:
    """The request MUST include a realistic User-Agent and Referer."""
    route = mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(200, text="<html><body></body></html>")
    )

    await rues_module.rues_scrape("foo")

    assert route.called
    headers = route.calls[0].request.headers
    ua = headers.get("user-agent", "")
    assert ua and len(ua) > 5, f"User-Agent must be present, got {ua!r}"
    # Referer MUST point to RUES (or be absent — both are acceptable, but if
    # present, MUST be RUES).
    referer = headers.get("referer", "")
    assert referer == "" or "rues.org.co" in referer, (
        f"Referer must be empty or RUES, got {referer!r}"
    )


@pytest.mark.asyncio
async def test_rues_scrape_payload_includes_search_keyword(
    rues_module, mock_transport
) -> None:
    """The POST body MUST carry the search keyword (form-encoded)."""
    route = mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(200, text="<html><body></body></html>")
    )

    await rues_module.rues_scrape("tecnoinnsoft")

    assert route.called
    request = route.calls[0].request
    body = request.content.decode("utf-8", errors="replace")
    assert "tecnoinnsoft" in body, f"keyword not in body: {body!r}"


@pytest.mark.asyncio
async def test_rues_scrape_handles_malformed_html_gracefully(
    rues_module, mock_transport
) -> None:
    """Malformed HTML MUST NOT crash the scraper."""
    mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(200, text="<not really <html")
    )

    result = await rues_module.rues_scrape("foo")
    assert isinstance(result, list)


@pytest.mark.asyncio
async def test_rues_scrape_returns_empty_on_network_error(
    rues_module, mock_transport
) -> None:
    """Network errors (timeout, DNS) MUST return []."""
    import httpx

    mock_transport.post("https://www.rues.org.co/").mock(
        side_effect=httpx.ConnectError("connection refused")
    )

    result = await rues_module.rues_scrape("foo")
    assert result == []


@pytest.mark.asyncio
async def test_rues_scrape_accepts_limit_parameter(
    rues_module, mock_transport
) -> None:
    """The ``limit`` parameter MUST cap the result list size."""
    rows = "".join(
        f'<tr class="resultado-empresa">'
        f'<td class="razon-social">EMPRESA {i} SA</td>'
        f'<td class="nit">9{i:07d}</td>'
        f'<td class="municipio">Bogota</td>'
        f"</tr>"
        for i in range(20)
    )
    html = f"<html><body><table>{rows}</table></body></html>"

    mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(200, text=html)
    )

    result = await rues_module.rues_scrape("foo", limit=3)
    assert len(result) <= 3