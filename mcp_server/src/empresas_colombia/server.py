"""FastMCP server entrypoint for the empresas-colombia capability.

The server exposes two tools and one resource:

    * ``buscar_por_dominio(dominio: str) -> list[dict]``
        Socrata primary → RUES HTML fallback. Returns up to 5 candidate
        ``{razon_social, nit, municipio, fuente}`` records.

    * ``consultar_empresa(razon_social_o_nit: str) -> dict``
        Single-record resolution combining Socrata + Decreto 768/2022
        inference (when exact CIIU not in matrix). Falls back to RUES
        when Socrata is empty or 5xx.

    * Resource ``ciiu://decreto-768/matriz-riesgos``
        Returns the canonical Decreto matrix JSON.

Transport:
    * Default: stdio (driven by ``python -m empresas_colombia.server`` or
      the ``empresas-colombia-mcp`` console script).
    * HTTP/SSE: set ``MCP_TRANSPORT=http`` to bind uvicorn on :8000.

Environment variables:
    * ``SOCRATA_APP_TOKEN`` — optional, sent as ``X-App-Token``.
    * ``SOCRATA_BASE_URL`` — default ``https://www.datos.gov.co/resource/jdru-qe2j.json``.
    * ``RUES_BASE_URL`` — default ``https://www.rues.org.co/``.
    * ``DECRETO_768_RESOURCE_PATH`` — default ``resources/decreto_768.json``.
"""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any, Optional

from fastmcp import FastMCP

from empresas_colombia import decreto_768, rues_scraper, socrata


SOCRATA_APP_TOKEN = os.getenv("SOCRATA_APP_TOKEN") or None
SOCRATA_BASE_URL = os.getenv(
    "SOCRATA_BASE_URL", "https://www.datos.gov.co/resource/jdru-qe2j.json"
)
RUES_BASE_URL = os.getenv("RUES_BASE_URL", "https://www.rues.org.co/")
DECRETO_768_RESOURCE_PATH = os.getenv(
    "DECRETO_768_RESOURCE_PATH", "resources/decreto_768.json"
)
MCP_TRANSPORT = os.getenv("MCP_TRANSPORT", "stdio").lower()

MAX_CANDIDATES = 5


mcp = FastMCP(
    "empresas-colombia",
    instructions=(
        "Colombian company enrichment via Socrata (jdru-qe2j) primary and "
        "RUES HTML fallback. Decreto 768/2022 risk inference applied to "
        "the ciiu_codigo returned by Socrata."
    ),
)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _split_dominio(dominio: str) -> str:
    """Extract a coarse company-name hint from a dominio string.

    Socrata ``jdru-qe2j`` does NOT carry a ``dominio`` column. We use the
    first meaningful label segment as the partial razon_social for the
    SoQL ``LIKE`` query. This is a best-effort heuristic; precision
    improvements are tracked for PR3+.
    """
    cleaned = (dominio or "").strip().lower()
    if not cleaned:
        return ""
    # Strip scheme.
    if "://" in cleaned:
        cleaned = cleaned.split("://", 1)[1]
    # Take the host only.
    host = cleaned.split("/", 1)[0]
    # Drop the TLD (.com.co, .com, .co, etc).
    parts = [p for p in host.split(".") if p and p not in {"www", "co", "com", "net", "io"}]
    if not parts:
        return ""
    # Prefer the leftmost label (e.g. ``tecnoinnsoft`` from ``tecnoinnsoft.com.co``).
    return parts[0]


def _matrix_resource_body() -> str:
    """Return the Decreto matrix JSON content for the MCP resource."""
    path = Path(DECRETO_768_RESOURCE_PATH)
    if not path.is_absolute():
        # Resolve relative to the package root.
        path = Path(__file__).resolve().parents[2] / path
    return path.read_text(encoding="utf-8")


def _to_candidate(record: dict[str, Any]) -> dict[str, Any]:
    """Normalize a raw Socrata/RUES record to the candidate shape."""
    return {
        "razon_social": str(record.get("razon_social") or "").strip(),
        "nit": str(record.get("nit") or "").strip(),
        "municipio": record.get("municipio"),
        "fuente": record.get("fuente") or "socrata",
    }


def _filter_candidates(records: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Drop empty records and cap at MAX_CANDIDATES."""
    out: list[dict[str, Any]] = []
    for record in records or []:
        rs = str(record.get("razon_social") or "").strip()
        nit = str(record.get("nit") or "").strip()
        if not rs or not nit:
            continue
        out.append(_to_candidate(record))
        if len(out) >= MAX_CANDIDATES:
            break
    return out


# ---------------------------------------------------------------------------
# MCP resources
# ---------------------------------------------------------------------------


@mcp.resource("ciiu://decreto-768/matriz-riesgos")
def decreto_768_matrix() -> str:
    """Canonical Decreto 768/2022 CIIU→ARL matrix (JSON)."""
    return _matrix_resource_body()


# ---------------------------------------------------------------------------
# MCP tools
# ---------------------------------------------------------------------------


@mcp.tool
async def buscar_por_dominio(dominio: str) -> list[dict[str, Any]]:
    """Search for candidate companies by their public domain name.

    Primary path is Socrata ``jdru-qe2j`` (case-insensitive LIKE on the
    derived razon_social segment). On Socrata 5xx, falls back to RUES
    HTML scraping.

    Args:
        dominio: Public domain string (e.g. ``tecnoinnsoft.com``).

    Returns:
        A list of up to 5 candidate dicts ``{razon_social, nit, municipio, fuente}``.
        Empty list when nothing matches.
    """
    hint = _split_dominio(dominio)
    if not hint:
        return []

    # Socrata primary.
    try:
        records = await socrata.socrata_query_by_name(
            razon_social=hint,
            limit=MAX_CANDIDATES,
            app_token=SOCRATA_APP_TOKEN,
            base_url=SOCRATA_BASE_URL,
        )
        if records:
            return _filter_candidates(records)
    except socrata.SocrataRateLimitError:
        # Rate-limited — try RUES as a recovery path.
        pass

    # RUES fallback.
    rues_records = await rues_scraper.rues_scrape(
        keyword=hint, limit=MAX_CANDIDATES, base_url=RUES_BASE_URL
    )
    return _filter_candidates(rues_records)


@mcp.tool
async def consultar_empresa(razon_social_o_nit: str) -> Optional[dict[str, Any]]:
    """Resolve a single company record (razon_social or NIT).

    Returns the Socrata first-match enriched with the Decreto 768/2022
    ARL classification (exact match OR inference fallback for empty
    matrix in PR2). On Socrata 5xx, falls back to RUES; on no matches
    anywhere, returns ``None``.
    """
    query = (razon_social_o_nit or "").strip()
    if not query:
        return None

    record: Optional[dict[str, Any]] = None
    fuente = "socrata"

    try:
        records = await socrata.socrata_query_by_name(
            razon_social=query,
            limit=1,
            app_token=SOCRATA_APP_TOKEN,
            base_url=SOCRATA_BASE_URL,
        )
        if records:
            record = records[0]
    except socrata.SocrataRateLimitError:
        record = None

    if record is None:
        # RUES fallback (search by NIT if it looks numeric, else by query).
        rues_records = await rues_scraper.rues_scrape(
            keyword=query, limit=1, base_url=RUES_BASE_URL
        )
        if rues_records:
            record = rues_records[0]
            fuente = "rues"

    if record is None:
        return None

    ciiu_raw = (
        record.get("ciiu_1")
        or record.get("ciiu_codigo")
        or record.get("ciiu")
        or ""
    )
    decreto = decreto_768.lookup(str(ciiu_raw)) or {}

    cantidad_empleados = record.get("personal") or record.get("cantidad_empleados")

    return {
        "nit": str(record.get("nit") or "").strip(),
        "razon_social": str(record.get("razon_social") or "").strip(),
        "ciiu_codigo": str(ciiu_raw or "").strip(),
        "cantidad_empleados": cantidad_empleados,
        "sector_economico": decreto.get("sector_economico"),
        "clase_riesgo_arl_num": decreto.get("clase_riesgo_arl_num"),
        "clase_riesgo_arl_desc": decreto.get("clase_riesgo_arl_desc"),
        "fuente": fuente,
    }


# ---------------------------------------------------------------------------
# CLI entry
# ---------------------------------------------------------------------------


def cli() -> None:
    """Console-script entry point: ``empresas-colombia-mcp``."""
    if MCP_TRANSPORT in ("http", "sse", "streamable-http"):
        # FastMCP exposes an http_app() helper that ASGI/uvicorn can serve.
        import uvicorn  # type: ignore[import-not-found]

        app = mcp.http_app()
        uvicorn.run(app, host="0.0.0.0", port=8000, log_level="info")
    else:
        mcp.run(transport="stdio")


def main() -> None:
    """Alias used when invoked via ``python -m empresas_colombia.server``."""
    cli()


if __name__ == "__main__":
    main()


__all__ = ["mcp", "cli", "main", "buscar_por_dominio", "consultar_empresa"]