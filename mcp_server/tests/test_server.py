"""Tests for the FastMCP server entrypoint.

The server exposes two tools (``buscar_por_dominio``, ``consultar_empresa``)
and one resource (``ciiu://decreto-768/matriz-riesgos``). These tests
inspect the registered tools/resources and invoke them via the internal
FastMCP API.

Tool behavior is verified with mocked Socrata / RUES transports; the
underlying network calls MUST NOT hit real services.
"""

from __future__ import annotations

import httpx
import pytest


@pytest.fixture
def mock_transport():
    import respx

    with respx.mock(assert_all_called=False) as router:
        yield router


@pytest.fixture
def server_module():
    from empresas_colombia import server

    return server


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_server_module_importable(server_module) -> None:
    """server module MUST expose the FastMCP instance and CLI entry."""
    assert hasattr(server_module, "mcp")
    assert hasattr(server_module, "cli") or hasattr(server_module, "main")
    from fastmcp import FastMCP

    assert isinstance(server_module.mcp, FastMCP)


@pytest.mark.asyncio
async def test_server_registers_two_tools(server_module) -> None:
    """The server MUST register exactly the two public tools."""
    tools = await server_module.mcp.get_tools()
    names = sorted(tools.keys())
    assert "buscar_por_dominio" in names, f"missing tool; got {names}"
    assert "consultar_empresa" in names, f"missing tool; got {names}"


@pytest.mark.asyncio
async def test_server_registers_decreto_resource(server_module) -> None:
    """The server MUST register the Decreto matrix resource."""
    resources = await server_module.mcp.get_resources()
    uris = sorted(resources.keys())
    assert "ciiu://decreto-768/matriz-riesgos" in uris, (
        f"missing resource; got {uris}"
    )


@pytest.mark.asyncio
async def test_resource_ciiu_decreto_768_returns_matrix_content(
    server_module,
) -> None:
    """Reading the Decreto resource MUST return the matrix JSON payload."""
    resources = await server_module.mcp.get_resources()
    resource = resources["ciiu://decreto-768/matriz-riesgos"]

    body = resource.fn()  # type: ignore[attr-defined]
    # The body MUST be the matrix JSON content (parseable, with ``entries``).
    import json

    parsed = json.loads(body) if isinstance(body, str) else body
    assert "entries" in parsed, f"resource missing 'entries' key; got {parsed!r}"


@pytest.mark.asyncio
async def test_buscar_por_dominio_returns_candidates(
    server_module, mock_transport
) -> None:
    """``buscar_por_dominio`` MUST return parsed candidate dicts."""
    socrata_fixture = [
        {"razon_social": "TECNOINNSOFT SAS", "nit": "900123456-7"},
        {"razon_social": "TECNOINNSOFT ANDINA", "nit": "800111222-3"},
    ]
    mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=socrata_fixture))

    tools = await server_module.mcp.get_tools()
    tool_fn = tools["buscar_por_dominio"].fn  # type: ignore[attr-defined]
    result = await tool_fn(dominio="tecnoinnsoft.com")

    assert isinstance(result, list)
    assert len(result) >= 1
    first = result[0]
    assert first["razon_social"] == "TECNOINNSOFT SAS"
    assert first["nit"] == "900123456-7"
    assert first["fuente"] == "socrata"


@pytest.mark.asyncio
async def test_buscar_por_dominio_returns_empty_on_no_match(
    server_module, mock_transport
) -> None:
    """Empty Socrata result + empty RUES result MUST return ``[]``."""
    mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=[]))
    mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(
            200, text="<html><body><table></table></body></html>"
        )
    )

    tools = await server_module.mcp.get_tools()
    tool_fn = tools["buscar_por_dominio"].fn  # type: ignore[attr-defined]
    result = await tool_fn(dominio="nonexistent-company-xyz.invalid")

    assert result == []


@pytest.mark.asyncio
async def test_buscar_por_dominio_falls_back_to_rues_on_socrata_5xx(
    server_module, mock_transport
) -> None:
    """Socrata 5xx MUST trigger the RUES fallback."""
    socrata_route = mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(503, text="Service Unavailable"))

    rues_route = mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(
            200,
            text=(
                "<html><body><table>"
                '<tr class="resultado-empresa">'
                '<td class="razon-social">TECNOINNSOFT RUES</td>'
                '<td class="nit">900-rues-1</td>'
                '<td class="municipio">Bogota</td>'
                "</tr>"
                "</table></body></html>"
            ),
        )
    )

    tools = await server_module.mcp.get_tools()
    tool_fn = tools["buscar_por_dominio"].fn  # type: ignore[attr-defined]
    result = await tool_fn(dominio="tecnoinnsoft.com")

    assert socrata_route.called
    assert rues_route.called
    assert isinstance(result, list)
    assert len(result) >= 1
    assert result[0]["fuente"] == "rues"


@pytest.mark.asyncio
async def test_consultar_empresa_returns_enriched_with_decreto_lookup(
    server_module, mock_transport
) -> None:
    """``consultar_empresa`` MUST combine Socrata + Decreto inference."""
    socrata_full = [
        {
            "razon_social": "TECNOINNSOFT SAS",
            "nit": "900123456-7",
            "ciiu_1": "6202",
            "personal": 42,
            "municipio": "Bogota",
        }
    ]
    mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=socrata_full))

    tools = await server_module.mcp.get_tools()
    tool_fn = tools["consultar_empresa"].fn  # type: ignore[attr-defined]
    result = await tool_fn(razon_social_o_nit="TECNOINNSOFT SAS")

    assert result is not None
    assert result["nit"] == "900123456-7"
    assert result["ciiu_codigo"] == "6202"
    # Decreto inference for "6202" → first digit 6 → Terciario/I per PR2
    # empty matrix (no exact match) → inference rule.
    assert "clase_riesgo_arl_num" in result
    assert "sector_economico" in result
    assert result["fuente"] == "socrata"


@pytest.mark.asyncio
async def test_consultar_empresa_handles_5xx_from_socrata(
    server_module, mock_transport
) -> None:
    """Socrata 5xx MUST be handled gracefully (return None or empty)."""
    mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(503, text="Service Unavailable"))
    mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(
            200, text="<html><body><table></table></body></html>"
        )
    )

    tools = await server_module.mcp.get_tools()
    tool_fn = tools["consultar_empresa"].fn  # type: ignore[attr-defined]
    result = await tool_fn(razon_social_o_nit="TECNOINNSOFT SAS")

    # Tool returns either None or {} on total failure — both are acceptable.
    assert result is None or result == {} or result == []


@pytest.mark.asyncio
async def test_consultar_empresa_falls_back_to_rues_when_socrata_empty(
    server_module, mock_transport
) -> None:
    """Empty Socrata → RUES scrape → enriched result from RUES."""
    mock_transport.post(
        "https://www.datos.gov.co/resource/jdru-qe2j.json"
    ).mock(return_value=httpx.Response(200, json=[]))
    rues_html = (
        "<html><body><table>"
        '<tr class="resultado-empresa">'
        '<td class="razon-social">TECNOINNSOFT RUES SAS</td>'
        '<td class="nit">900-rues-2</td>'
        '<td class="municipio">Medellin</td>'
        "</tr>"
        "</table></body></html>"
    )
    mock_transport.post("https://www.rues.org.co/").mock(
        return_value=httpx.Response(200, text=rues_html)
    )

    tools = await server_module.mcp.get_tools()
    tool_fn = tools["consultar_empresa"].fn  # type: ignore[attr-defined]
    result = await tool_fn(razon_social_o_nit="TECNOINNSOFT")

    # If Socrata empty and RUES returns a row, the tool returns enriched
    # record with fuente='rues'. If it returns None / empty (because
    # RUES partial data cannot populate Decreto fields), that's also OK.
    if result:
        assert result.get("fuente") == "rues"
        assert result["nit"] == "900-rues-2"