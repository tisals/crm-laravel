"""Scaffold tests for mcp_server.

These tests verify the package layout is sound (importable, has metadata, deps
resolved). They run BEFORE any production code in `src/empresas_colombia/`
exists, so they should FAIL on a fresh tree (RED gate) and pass once the
scaffold (pyproject.toml + src/ package skeleton) is in place.
"""

from __future__ import annotations

import importlib
import re
from pathlib import Path


PACKAGE_ROOT = Path(__file__).resolve().parent.parent
SRC_ROOT = PACKAGE_ROOT / "src"
PYPROJECT = PACKAGE_ROOT / "pyproject.toml"


def _read_pyproject() -> str:
    assert PYPROJECT.exists(), f"pyproject.toml missing at {PYPROJECT}"
    return PYPROJECT.read_text(encoding="utf-8")


def test_package_root_has_expected_layout() -> None:
    """The package MUST be a src-layout Python project."""
    assert PACKAGE_ROOT.is_dir(), "mcp_server/ root directory must exist"
    assert SRC_ROOT.is_dir(), "src/ directory must exist (src-layout)"
    assert (SRC_ROOT / "empresas_colombia").is_dir(), (
        "src/empresas_colombia/ package directory must exist"
    )
    init_file = SRC_ROOT / "empresas_colombia" / "__init__.py"
    assert init_file.is_file(), "src/empresas_colombia/__init__.py must exist"


def test_package_metadata_declares_name_and_version() -> None:
    """pyproject.toml MUST declare the package name and a version."""
    contents = _read_pyproject()
    assert re.search(r'^name\s*=\s*"empresas-colombia"', contents, re.MULTILINE), (
        "pyproject.toml must set name='empresas-colombia'"
    )
    match = re.search(r'^version\s*=\s*"([^"]+)"', contents, re.MULTILINE)
    assert match is not None, "pyproject.toml must declare a version string"
    version = match.group(1)
    # PEP 440 minimal: at least X.Y.Z
    assert re.match(r"^\d+\.\d+\.\d+", version), (
        f"version '{version}' must follow PEP 440 (X.Y.Z minimum)"
    )


def test_package_metadata_requires_fastmcp() -> None:
    """pyproject.toml MUST declare fastmcp dependency (>=0.4 per spec)."""
    contents = _read_pyproject()
    assert re.search(
        r'fastmcp\s*[><=~]+\s*0?\.4', contents
    ), "pyproject.toml must require fastmcp>=0.4 per spec"


def test_package_metadata_requires_httpx_beautifulsoup4_pydantic() -> None:
    """pyproject.toml MUST declare httpx, beautifulsoup4, pydantic."""
    contents = _read_pyproject()
    for dep in ("httpx", "beautifulsoup4", "pydantic"):
        assert re.search(rf"\b{dep}\b", contents), (
            f"pyproject.toml must declare {dep} dependency"
        )


def test_package_metadata_requires_python_311_or_newer() -> None:
    """pyproject.toml MUST declare Python >=3.11."""
    contents = _read_pyproject()
    match = re.search(
        r'requires-python\s*=\s*"([^"]+)"', contents
    )
    assert match is not None, "pyproject.toml must declare requires-python"
    spec = match.group(1)
    assert "3.11" in spec or "3.12" in spec or "3.13" in spec, (
        f"requires-python '{spec}' must include Python 3.11+"
    )


def test_package_init_exposes_version_attribute() -> None:
    """src/empresas_colombia/__init__.py MUST expose __version__."""
    pkg = importlib.import_module("empresas_colombia")
    assert hasattr(pkg, "__version__"), (
        "empresas_colombia.__version__ must be defined"
    )
    assert isinstance(pkg.__version__, str)
    assert re.match(r"^\d+\.\d+\.\d+", pkg.__version__), (
        f"__version__ '{pkg.__version__}' must follow PEP 440"
    )


def test_resources_directory_present() -> None:
    """resources/ MUST exist (carries the Decreto matrix JSON)."""
    resources = PACKAGE_ROOT / "resources"
    assert resources.is_dir(), "resources/ directory must exist"
    decreto = resources / "decreto_768.json"
    assert decreto.is_file(), "resources/decreto_768.json must exist"


def test_dependencies_resolvable_via_import() -> None:
    """All runtime deps MUST be importable after scaffold is in place.

    This catches missing optional-dependency installs.
    """
    for mod in ("httpx", "bs4", "pydantic"):
        importlib.import_module(mod)