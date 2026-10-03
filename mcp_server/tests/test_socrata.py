"""Tests for the Socrata client.

The Socrata client MUST:
    * Build a correct SoQL URL targeting ``jdru-qe2j``.
    * Send an optional ``X-App-Token`` header when provided.
    * Surface HTTP 429 as a typed ``SocrataRateLimitError``.
    * Return ``[]`` on 5xx server errors (graceful degradation).
    * URL-encode special characters (e.g. apostrophes).
"""

from __future__ import annotations

import httpx
import pytest


# ---------------------------------------------------------------------------
# Test fixtures
# ---------------------------------------------------------------------------


@pytest.fixture
def socrata_module():
    """Lazy import so RED phase fails clearly."""
    from empresas_colombia import socrata

    return socrata


@pytest.fixture
def mock_transport():
    """Yield an active ``respx`` mock router.

    ``respx.mock()`` patches ``httpx`` globally for the duration of the
    context. The fixture enters the context so each test gets a clean,
    activated router without boilerplate.
    """
    import respx

    with respx.mock(assert_all_called=False) as router:
        yield router


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_socrata_query_module_importable(socrata_module) -> None:
    """socrata module MUST expose the public surface."""
    assert hasattr(socrata_module, "socrata_query_by_name")
    assert hasattr(socrata_module, "SocrataRateLimitError")


@pytest.mark.asyncio
async def test_socrata_query_returns_empty_on_5xx(socrata_module, mock_transport) -> None:
    """5xx server errors MUST be swallowed → empty list (graceful)."""
    mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(503, text="Service Unavailable"))

    result = await socrata_module.socrata_query_by_name("tecnoinnsoft")
    assert result == []


@pytest.mark.asyncio
async def test_socrata_query_returns_empty_on_4xx_non_429(
    socrata_module, mock_transport
) -> None:
    """4xx (non-429) MUST be swallowed → empty list."""
    mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(400, text="Bad Request"))

    result = await socrata_module.socrata_query_by_name("foo")
    assert result == []


@pytest.mark.asyncio
async def test_socrata_query_raises_rate_limit_on_429(
    socrata_module, mock_transport
) -> None:
    """HTTP 429 MUST raise SocrataRateLimitError."""
    mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(429, text="Too Many Requests"))

    with pytest.raises(socrata_module.SocrataRateLimitError):
        await socrata_module.socrata_query_by_name("foo")


@pytest.mark.asyncio
async def test_socrata_query_includes_app_token_header_when_provided(
    socrata_module, mock_transport
) -> None:
    """When app_token is provided, the X-App-Token header MUST be set."""
    route = mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=[]))

    await socrata_module.socrata_query_by_name(
        "tecnoinnsoft", app_token="my-token-123"
    )

    assert route.called
    request = route.calls[0].request
    assert request.headers.get("x-app-token") == "my-token-123"


@pytest.mark.asyncio
async def test_socrata_query_omits_app_token_header_when_not_provided(
    socrata_module, mock_transport
) -> None:
    """When app_token is None, X-App-Token MUST be absent."""
    route = mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=[]))

    await socrata_module.socrata_query_by_name("tecnoinnsoft", app_token=None)

    assert route.called
    request = route.calls[0].request
    assert "x-app-token" not in {k.lower() for k in request.headers.keys()}


@pytest.mark.asyncio
async def test_socrata_query_builds_url_with_where_clause(
    socrata_module, mock_transport
) -> None:
    """The request URL MUST include a ``$where`` clause with the search term."""
    from urllib.parse import unquote

    route = mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=[]))

    await socrata_module.socrata_query_by_name("tecnoinnsoft")

    assert route.called
    request = route.calls[0].request
    request_url = str(request.url)
    # httpx URL-encodes '$' as '%24'. Compare against decoded URL so the
    # spec-mandated SoQL form (``$where=...``) is verified regardless of
    # whether the client or transport normalises the dollar sign.
    decoded = unquote(request_url)
    # The URL MUST target the canonical Socrata endpoint for Colombian
    # company registry.
    assert "datos.gov.co/resource/jdru-qe2j.json" in decoded
    # The URL MUST carry a SoQL $where clause containing the search term.
    assert "$where" in decoded.lower()
    # Search term MAY be upper-cased for case-insensitive SoQL match;
    # compare case-insensitively to accept either form.
    assert "tecnoinnsoft" in decoded.lower()


@pytest.mark.asyncio
async def test_socrata_query_uses_post_method(
    socrata_module, mock_transport
) -> None:
    """Socrata queries MUST use POST (avoid URL-length issues with SoQL)."""
    route = mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=[]))

    await socrata_module.socrata_query_by_name("foo")

    assert route.called
    assert route.calls[0].request.method == "POST"


@pytest.mark.asyncio
async def test_socrata_query_returns_parsed_records(
    socrata_module, mock_transport
) -> None:
    """A 200 response MUST yield the parsed JSON list."""
    fixture = [
        {
            "razon_social": "TECNOINNSOFT SAS",
            "nit": "900123456-7",
            "municipio": "Bogota",
        },
        {
            "razon_social": "TECNOINNSOFT COLOMBIA SAS",
            "nit": "900987654-3",
            "municipio": "Medellin",
        },
    ]
    mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=fixture))

    result = await socrata_module.socrata_query_by_name("tecnoinnsoft")
    assert result == fixture
    assert len(result) == 2
    assert result[0]["nit"] == "900123456-7"


@pytest.mark.asyncio
async def test_socrata_query_handles_special_chars_in_razon_social(
    socrata_module, mock_transport
) -> None:
    """Apostrophes MUST NOT crash the client or the SoQL parser.

    The apostrophe is SoQL-escaped (``'`` → ``''``) so the SoQL parser
    sees a valid string literal, AND URL-encoded so the request itself
    is well-formed. The test verifies that the search term still appears
    in the URL (with the apostrophe visible either as ``''`` after SoQL
    escape, or ``%27`` after URL encoding).
    """
    from urllib.parse import unquote

    route = mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=[]))

    await socrata_module.socrata_query_by_name("O'Reilly Colombia")

    assert route.called
    request_url = str(route.calls[0].request.url)
    decoded = unquote(request_url)

    # The apostrophe shows up in the URL as either:
    #   * SoQL-escape ``''``  (after URL-decoding the escaped form)
    #   * URL-encoded ``%27``
    assert ("''" in decoded) or ("%27" in request_url.lower())
    # The other parts of the term MUST still be present and case-folded.
    assert "REILLY" in decoded.upper()
    assert "COLOMBIA" in decoded.upper()


@pytest.mark.asyncio
async def test_socrata_query_respects_limit(
    socrata_module, mock_transport
) -> None:
    """The query MUST include ``$limit`` with the requested value."""
    from urllib.parse import unquote

    route = mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=[]))

    await socrata_module.socrata_query_by_name("foo", limit=5)

    assert route.called
    request_url = unquote(str(route.calls[0].request.url))
    # The URL MUST carry the requested limit (decoded).
    assert "$limit=5" in request_url.lower() or "limit=5" in request_url.lower()


@pytest.mark.asyncio
async def test_socrata_query_includes_accept_json_header(
    socrata_module, mock_transport
) -> None:
    """The request MUST advertise JSON acceptance."""
    route = mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=[]))

    await socrata_module.socrata_query_by_name("foo")

    assert route.called
    accept = route.calls[0].request.headers.get("accept", "")
    assert "json" in accept.lower()


@pytest.mark.asyncio
async def test_socrata_query_returns_empty_when_body_invalid_json(
    socrata_module, mock_transport
) -> None:
    """Invalid JSON body MUST NOT crash the client; return []."""
    mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, text="not-json"))

    result = await socrata_module.socrata_query_by_name("foo")
    assert result == []


@pytest.mark.asyncio
async def test_socrata_query_returns_empty_when_body_not_a_list(
    socrata_module, mock_transport
) -> None:
    """Socrata MUST return a list; if it returns an object, treat as empty."""
    mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json={"error": "wrong shape"}))

    result = await socrata_module.socrata_query_by_name("foo")
    assert result == []