"""empresas-colombia MCP server package.

FastMCP server for Colombian company enrichment that exposes:
- Tools:
    * ``buscar_por_dominio``: Socrata primary, RUES HTML fallback.
    * ``consultar_empresa``: full record with Decreto 768/2022 inference.
- Resource:
    * ``ciiu://decreto-768/matriz-riesgos``: canonical Decreto matrix.

The package is intentionally framework-light: business logic lives in
``decreto_768``, ``socrata``, ``rues_scraper``, and ``schemas``. The
FastMCP entry point is ``server``.
"""

from __future__ import annotations

__version__ = "0.1.0"

__all__ = ["__version__"]