# empresas-colombia MCP server

FastMCP server for Colombian company enrichment. Exposes two tools
(``buscar_por_dominio``, ``consultar_empresa``) and one resource
(``ciiu://decreto-768/matriz-riesgos``) backed by Socrata
(``jdru-qe2j``) with a RUES HTML fallback.

## Layout

```
mcp_server/
├── pyproject.toml          # build + pytest config
├── requirements.txt        # runtime deps (pinned majors)
├── Dockerfile              # Python 3.11 slim, stdio ENTRYPOINT
├── README.md               # this file
├── resources/
│   └── decreto_768.json    # canonical Decreto 768/2022 matrix
├── src/
│   └── empresas_colombia/
│       ├── __init__.py     # __version__ = "0.1.0"
│       ├── schemas.py      # Pydantic wire shapes
│       ├── decreto_768.py  # lookup() + inference rule
│       ├── socrata.py      # socrata_query_by_name()
│       ├── rues_scraper.py # rues_scrape()
│       └── server.py       # FastMCP entry point (cli/main)
└── tests/
    ├── conftest.py
    ├── fixtures/
    │   └── rues_sample.html
    ├── test_scaffold.py    # WU-2.1: layout + metadata
    ├── test_schemas.py      # WU-2.2
    ├── test_decreto_768.py # WU-2.3
    ├── test_socrata.py     # WU-2.4
    ├── test_rues_scraper.py# WU-2.5
    ├── test_server.py      # WU-2.6
    └── test_integration.py # WU-2.7: stdio boot handshake
```

## Quick start (local dev)

```bash
# 1. Install dev + runtime deps from the project root.
pip install -r mcp_server/requirements.txt
pip install -e mcp_server/[dev]

# 2. Run the test suite.
cd mcp_server && python -m pytest -v

# 3. Launch the server on stdio (default transport).
cd mcp_server/src && python -m empresas_colombia.server

# 4. Or as a package import.
```

## Docker

```bash
docker build -f mcp_server/Dockerfile -t empresas-colombia-mcp:latest mcp_server/

# stdio (default)
docker run --rm -i empresas-colombia-mcp:latest

# HTTP/SSE on :8000
docker run --rm -p 8000:8000 -e MCP_TRANSPORT=http empresas-colombia-mcp:latest
```

## Environment variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `SOCRATA_APP_TOKEN` | (empty) | Optional `X-App-Token` header |
| `SOCRATA_BASE_URL` | `https://www.datos.gov.co/resource/jdru-qe2j.json` | Override Socrata URL (used in tests) |
| `RUES_BASE_URL` | `https://www.rues.org.co/` | Override RUES endpoint |
| `DECRETO_768_RESOURCE_PATH` | `resources/decreto_768.json` | Override Decreto matrix path |
| `MCP_TRANSPORT` | `stdio` | `stdio` (default) or `http` (uvicorn on :8000) |

## Tools

### `buscar_por_dominio(dominio: str) -> list[dict]`

Search candidate companies by domain.

* Primary path: Socrata `jdru-qe2j` LIKE on the derived `razon_social`
  segment (first label of the host, TLD stripped).
* Fallback: RUES HTML form POST + BeautifulSoup parse of
  `tr.resultado-empresa` rows.
* Returns up to 5 candidates.

### `consultar_empresa(razon_social_o_nit: str) -> dict`

Resolve a single company record with Decreto 768/2022 enrichment.

* Socrata primary → RUES fallback.
* Decreto inference applied to the `ciiu_1` / `ciiu_codigo` field.
* Returns the merged dict or `None` when nothing matches.

## Resource

### `ciiu://decreto-768/matriz-riesgos`

Returns the canonical Decreto matrix JSON
(`resources/decreto_768.json`).

## Decreto matrix sync (manual)

The matrix is mirrored in `config/decreto_768.php` (Laravel-side). The
two files MUST carry identical semantic content. To update:

1. Edit `mcp_server/resources/decreto_768.json` (canonical Python copy).
2. Mirror the same entries to `config/decreto_768.php` (Laravel-side).
3. Run `php artisan test --filter=Decreto768DriftTest` to verify the
   SHA256 drift check passes.
4. PR6 ships the canonical Decreto 768/2022 rows; until then the
   matrix is empty and every CIIU falls back to the inference rule.

## Tests

```bash
cd mcp_server && python -m pytest -v
```

Strict TDD: every work unit ships its tests in the same commit as the
production code. The integration test (`test_integration.py`) spawns
the server as a subprocess and exchanges an MCP `initialize`
handshake to prove the stdio loop is wired up.

## Known limitations (PR2 scope)

* The Decreto matrix is empty (PR6 fills it). Inference is the only
  source of `clase_riesgo_arl_num` / `sector_economico` right now.
* Socrata's `jdru-qe2j` does not carry a `dominio` column; the
  dominio→razon_social mapping is a coarse heuristic. PR3+ may add a
  second dataset with explicit dominio support.
* RUES HTML scraping is fragile by nature — the parser tolerates
  missing rows but real-world markup drift may break the selector.