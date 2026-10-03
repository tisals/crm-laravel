"""Decreto 768/2022 lookup function.

Reads the canonical matrix from ``resources/decreto_768.json`` (resolved
via :mod:`importlib.resources`) and exposes ``lookup(ciiu: str) -> dict | None``.

Normalization:
        * Strip non-digit chars.
        * If 7-digit, take digits 2-5 (zero-indexed [1:5]) as the 4-digit code.
        * If shorter than 4 digits, pad with leading zeros.

Lookup:
        1. Exact 4-digit match in the matrix → return that dict.
        2. Inference from the first digit:
                * ``0`` or ``1`` → Primario / ARL class III (num=3)
                * ``2``, ``3``, ``4`` → Secundario / ARL class IV (num=4)
                * else (5..9, non-digit) → Terciario / ARL class I (num=1)

The production matrix in PR2 is intentionally empty (PR6 fills it).
"""

from __future__ import annotations

import json
from importlib import resources
from pathlib import Path
from typing import Optional


_MATRIX: Optional[dict] = None
_MATRIX_PATH: Optional[Path] = None


def _matrix_path() -> Path:
    """Return the filesystem path to ``resources/decreto_768.json``.

    Falls back to the on-disk path even when the package is not installed,
    so tests running from the source tree still find the matrix.
    """
    global _MATRIX_PATH
    if _MATRIX_PATH is not None:
        return _MATRIX_PATH

    try:
        # importlib.resources is the right way for installed packages.
        with resources.as_file(
            resources.files("empresas_colombia.resources").joinpath(
                "decreto_768.json"
            )
        ) as path:
            _MATRIX_PATH = Path(path)
            return _MATRIX_PATH
    except (ModuleNotFoundError, FileNotFoundError):
        # Fallback: walk up from this file to find resources/decreto_768.json.
        candidate = Path(__file__).resolve().parents[2] / "resources" / "decreto_768.json"
        _MATRIX_PATH = candidate
        return _MATRIX_PATH


def _load_matrix() -> dict:
    """Load the canonical Decreto 768/2022 matrix (cached at module load)."""
    global _MATRIX
    if _MATRIX is not None:
        return _MATRIX
    path = _matrix_path()
    with path.open("r", encoding="utf-8") as fh:
        _MATRIX = json.load(fh)
    return _MATRIX


def reload_matrix() -> dict:
    """Force a reload of the matrix from disk (used by tests)."""
    global _MATRIX
    _MATRIX = None
    _MATRIX_PATH = None
    return _load_matrix()


def _normalize_to_4digit(ciiu: str) -> str:
    """Normalize a CIIU string to exactly 4 digits.

    Rules:
        * Strip non-digit chars.
        * If 7-digit, return the FIRST 4 digits (the canonical CIIU category;
          the trailing 3 digits are a subdivision not represented in the
          Decreto matrix).
        * If shorter than 4, pad with leading zeros (defensive — invalid input).
        * If longer than 7, return the first 4 digits.
    """
    digits = "".join(ch for ch in ciiu if ch.isdigit())
    if len(digits) >= 7:
        return digits[0:4]  # 7-digit CIIU: first 4 digits = canonical category.
    if len(digits) < 4:
        return digits.zfill(4)
    return digits[:4]


def _inference_from_first_digit(first: str) -> Optional[dict]:
    """Apply the Decreto 768/2022 inference rule based on the first digit."""
    if first in ("0", "1"):
        return {
            "clase_riesgo_arl_num": 3,
            "clase_riesgo_arl_desc": "Medio",
            "sector_economico": "Primario",
            "fuente": "inferencia_decreto_768_2022",
        }
    if first in ("2", "3", "4"):
        return {
            "clase_riesgo_arl_num": 4,
            "clase_riesgo_arl_desc": "Alto",
            "sector_economico": "Secundario",
            "fuente": "inferencia_decreto_768_2022",
        }
    # Any other digit (5-9) or non-digit char → else branch.
    return {
        "clase_riesgo_arl_num": 1,
        "clase_riesgo_arl_desc": "Minimo",
        "sector_economico": "Terciario",
        "fuente": "inferencia_decreto_768_2022",
    }


def lookup(ciiu: str) -> Optional[dict]:
    """Look up the ARL classification for a CIIU code.

    Args:
        ciiu: A CIIU code in any common form (4-digit, 7-digit, with or
            without dots / dashes). Non-digit characters are stripped
            before normalization.

    Returns:
        A dict with keys ``clase_riesgo_arl_num`` (int 1-5),
        ``clase_riesgo_arl_desc`` (str), ``sector_economico`` (str),
        and ``fuente`` (str) — either from the matrix or from inference.
        Returns ``None`` when the matrix is empty AND the CIIU string has
        no digits at all (no inference fallback applies).
    """
    digits = "".join(ch for ch in ciiu if ch.isdigit())
    if not digits:
        # No digits means we cannot even infer. Caller decides the policy.
        return None

    four_digit = _normalize_to_4digit(ciiu)
    matrix = _load_matrix()
    entries = matrix.get("entries", {}) or {}

    # Exact match wins.
    if four_digit in entries:
        return dict(entries[four_digit])

    # Inference fallback based on first digit.
    return _inference_from_first_digit(digits[0])


__all__ = ["lookup", "reload_matrix", "_matrix_path"]