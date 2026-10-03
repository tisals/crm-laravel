"""Pytest configuration for mcp_server tests."""

import sys
from pathlib import Path

# Ensure `src/` is importable as `empresas_colombia` package source root.
SRC = Path(__file__).resolve().parent.parent / "src"
if str(SRC) not in sys.path:
    sys.path.insert(0, str(SRC))