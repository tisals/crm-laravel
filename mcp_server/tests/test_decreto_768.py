"""Tests for Decreto 768/2022 lookup function.

The lookup function reads ``resources/decreto_768.json`` (canonical matrix)
and applies a 4-digit normalization rule plus a first-digit inference
fallback when no exact match is found.

These tests run BEFORE the production module is written.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest


PACKAGE_ROOT = Path(__file__).resolve().parent.parent
RESOURCES = PACKAGE_ROOT / "resources"


def _write_matrix(entries: dict) -> None:
    """Test helper: write a temporary matrix file in the canonical schema.

    The schema mirrors the production file:
        {"_meta": {...}, "entries": {"6202": {...}, ...}}
    """
    RESOURCES.mkdir(parents=True, exist_ok=True)
    payload = {
        "_meta": {
            "source": "test fixture",
            "shape": "ciiu_4digit -> ARL dict",
        },
        "entries": entries,
    }
    (RESOURCES / "decreto_768.json").write_text(
        json.dumps(payload, indent=4), encoding="utf-8", newline="\n"
    )


def _restore_empty_matrix() -> None:
    """Restore the canonical empty matrix after a test writes a fixture."""
    empty = {
        "_meta": {
            "source": "Decreto 768/2022 (Mintrabajo Colombia)",
            "shape": "ciiu_4digit -> { clase_riesgo_ul_num:int, clase_riesgo_ul_desc:string, sector_economico:string, fuente:string }",
            "mirror": "config/decreto_768.php (Laravel-side). MUST stay byte-identical in semantic content; CI SHA256 drift check enforces this.",
            "pr": "PR1 ships an empty matrix. PR6 fills both files with the canonical Decreto 768/2022 rows.",
        },
        "entries": {},
    }
    (RESOURCES / "decreto_768.json").write_text(
        json.dumps(empty, indent=4), encoding="utf-8", newline="\n"
    )


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------


def test_lookup_module_importable() -> None:
    """decreto_768 module MUST be importable."""
    from empresas_colombia import decreto_768

    assert hasattr(decreto_768, "lookup")


def test_lookup_returns_inferred_when_matrix_empty_and_ciiu_has_digits() -> None:
    """Empty production matrix + digit-bearing CIIU → lookup() applies inference.

    Per spec ``decreto-768-risk-matrix`` §Scenario "Inference fallback":
    when no exact match exists, the inference rule applies. PR2 ships an
    empty matrix, so any 4-digit CIIU still returns the inferred dict.
    """
    _restore_empty_matrix()
    import importlib

    from empresas_colombia import decreto_768

    importlib.reload(decreto_768)

    # First digit 6 → Terciario/I per the inference rule.
    result = decreto_768.lookup("6202")
    assert result is not None
    assert result["clase_riesgo_arl_num"] == 1
    assert result["sector_economico"] == "Terciario"

    # First digit 9 → Terciario/I.
    result = decreto_768.lookup("9999")
    assert result is not None
    assert result["clase_riesgo_arl_num"] == 1
    assert result["sector_economico"] == "Terciario"


def test_lookup_returns_none_for_input_without_digits() -> None:
    """Input with no digits (e.g. 'ABCDE' or empty string) MUST return None.

    Inference requires at least one digit to derive a sector. Returning
    None on truly malformed input keeps the contract honest.
    """
    _restore_empty_matrix()
    import importlib

    from empresas_colombia import decreto_768

    importlib.reload(decreto_768)

    assert decreto_768.lookup("ABCDE") is None
    assert decreto_768.lookup("") is None
    assert decreto_768.lookup("...") is None


def test_lookup_normalizes_seven_digit_ciiu_to_four_digits() -> None:
    """A 7-digit CIIU (e.g. '6202001') MUST normalize to its 4-digit prefix."""
    _write_matrix(
        {
            "6202": {
                "clase_riesgo_arl_num": 3,
                "clase_riesgo_arl_desc": "Medio",
                "sector_economico": "Servicios",
                "fuente": "decreto_768_2022",
            }
        }
    )
    import importlib

    from empresas_colombia import decreto_768

    importlib.reload(decreto_768)

    result = decreto_768.lookup("6202001")
    assert result is not None
    assert result["clase_riesgo_arl_num"] == 3
    assert result["clase_riesgo_arl_desc"] == "Medio"
    assert result["sector_economico"] == "Servicios"

    _restore_empty_matrix()
    importlib.reload(decreto_768)


def test_lookup_exact_4digit_match_returns_dict() -> None:
    """Exact 4-digit match MUST return the canonical ARL dict."""
    _write_matrix(
        {
            "6202": {
                "clase_riesgo_arl_num": 3,
                "clase_riesgo_arl_desc": "Medio",
                "sector_economico": "Servicios",
                "fuente": "decreto_768_2022",
            }
        }
    )
    import importlib

    from empresas_colombia import decreto_768

    importlib.reload(decreto_768)

    result = decreto_768.lookup("6202")
    assert result is not None
    assert result["clase_riesgo_arl_num"] == 3
    assert result["clase_riesgo_arl_desc"] == "Medio"
    assert result["sector_economico"] == "Servicios"

    _restore_empty_matrix()
    importlib.reload(decreto_768)


def test_lookup_inference_from_first_digit_when_no_exact_match() -> None:
    """Inference rule MUST apply when exact match is absent.

    Spec: 0|1→Primario/III (num=3); 2|3|4→Secundario/IV (num=4);
    else→Terciario/I (num=1).
    """
    _write_matrix({})  # No exact entries at all.
    import importlib

    from empresas_colombia import decreto_768

    importlib.reload(decreto_768)

    # First digit 0 → Primario/III
    r0 = decreto_768.lookup("0999")
    assert r0 is not None
    assert r0["clase_riesgo_arl_num"] == 3
    assert r0["sector_economico"] == "Primario"

    # First digit 1 → Primario/III
    r1 = decreto_768.lookup("1999")
    assert r1 is not None
    assert r1["clase_riesgo_arl_num"] == 3
    assert r1["sector_economico"] == "Primario"

    # First digit 2 → Secundario/IV
    r2 = decreto_768.lookup("2999")
    assert r2 is not None
    assert r2["clase_riesgo_arl_num"] == 4
    assert r2["sector_economico"] == "Secundario"

    # First digit 3 → Secundario/IV
    r3 = decreto_768.lookup("3499")
    assert r3 is not None
    assert r3["clase_riesgo_arl_num"] == 4
    assert r3["sector_economico"] == "Secundario"

    # First digit 4 → Secundario/IV
    r4 = decreto_768.lookup("4799")
    assert r4 is not None
    assert r4["clase_riesgo_arl_num"] == 4
    assert r4["sector_economico"] == "Secundario"

    # First digit 5 → Terciario/I
    r5 = decreto_768.lookup("5999")
    assert r5 is not None
    assert r5["clase_riesgo_arl_num"] == 1
    assert r5["sector_economico"] == "Terciario"

    # First digit 9 → Terciario/I (else branch)
    r9 = decreto_768.lookup("9999")
    assert r9 is not None
    assert r9["clase_riesgo_arl_num"] == 1
    assert r9["sector_economico"] == "Terciario"

    _restore_empty_matrix()
    importlib.reload(decreto_768)


def test_lookup_returns_dict_with_required_keys() -> None:
    """Every lookup result MUST contain clase_riesgo_arl_num, desc, sector."""
    _write_matrix(
        {
            "6202": {
                "clase_riesgo_arl_num": 3,
                "clase_riesgo_arl_desc": "Medio",
                "sector_economico": "Servicios",
                "fuente": "decreto_768_2022",
            }
        }
    )
    import importlib

    from empresas_colombia import decreto_768

    importlib.reload(decreto_768)

    result = decreto_768.lookup("6202")
    assert result is not None
    required = {"clase_riesgo_arl_num", "clase_riesgo_arl_desc", "sector_economico"}
    assert required.issubset(result.keys()), (
        f"lookup result must contain {required}, got {sorted(result.keys())}"
    )

    _restore_empty_matrix()
    importlib.reload(decreto_768)


def test_lookup_exact_match_wins_over_inference() -> None:
    """When exact entry exists, lookup MUST return it (not the inference)."""
    _write_matrix(
        {
            "9999": {
                "clase_riesgo_arl_num": 5,
                "clase_riesgo_arl_desc": "Maximo",
                "sector_economico": "Riesgo maximo",
                "fuente": "decreto_768_2022",
            }
        }
    )
    import importlib

    from empresas_colombia import decreto_768

    importlib.reload(decreto_768)

    result = decreto_768.lookup("9999")
    assert result is not None
    # If exact match were honored, num=5 and desc=Maximo. If inference ran,
    # we'd get num=1 Terciario. Exact match wins.
    assert result["clase_riesgo_arl_num"] == 5
    assert result["clase_riesgo_arl_desc"] == "Maximo"
    assert result["sector_economico"] == "Riesgo maximo"

    _restore_empty_matrix()
    importlib.reload(decreto_768)


def test_lookup_handles_input_with_mixed_alphanumeric_gracefully() -> None:
    """CIIU with mixed letters and digits (e.g. 'A6202B') MUST still find the entry."""
    _write_matrix(
        {
            "6202": {
                "clase_riesgo_arl_num": 3,
                "clase_riesgo_arl_desc": "Medio",
                "sector_economico": "Servicios",
                "fuente": "decreto_768_2022",
            }
        }
    )
    import importlib

    from empresas_colombia import decreto_768

    importlib.reload(decreto_768)

    result = decreto_768.lookup("A6202B")
    assert result is not None
    assert result["clase_riesgo_arl_num"] == 3

    _restore_empty_matrix()
    importlib.reload(decreto_768)


@pytest.fixture(autouse=True)
def _ensure_empty_matrix_after_test() -> None:
    """Safety net: always restore the canonical empty matrix after each test."""
    yield
    _restore_empty_matrix()