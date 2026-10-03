"""Tests for Pydantic schemas in src/empresas_colombia/schemas.py.

Schemas encode the MCP tool response shapes per spec.
These tests run BEFORE the production module is written.
"""

from __future__ import annotations

from typing import Any

import pytest
from pydantic import ValidationError


def test_empresa_candidato_requires_razon_social_nit_fuente() -> None:
    """EmpresaCandidato MUST require razon_social, nit, fuente."""
    from empresas_colombia.schemas import EmpresaCandidato

    candidate = EmpresaCandidato(
        razon_social="Tecnoinnsoft SAS",
        nit="900123456-7",
        fuente="socrata",
    )
    assert candidate.razon_social == "Tecnoinnsoft SAS"
    assert candidate.nit == "900123456-7"
    assert candidate.fuente == "socrata"


def test_empresa_candidato_rejects_missing_razon_social() -> None:
    """EmpresaCandidato MUST reject payloads missing razon_social."""
    from empresas_colombia.schemas import EmpresaCandidato

    with pytest.raises(ValidationError) as exc_info:
        EmpresaCandidato(nit="900123456-7", fuente="socrata")  # type: ignore[call-arg]
    # The error MUST point at razon_social.
    errors = exc_info.value.errors()
    assert any("razon_social" in str(err.get("loc", ())) for err in errors), (
        f"expected error on razon_social, got: {errors}"
    )


def test_empresa_candidato_rejects_missing_nit() -> None:
    """EmpresaCandidato MUST reject payloads missing nit."""
    from empresas_colombia.schemas import EmpresaCandidato

    with pytest.raises(ValidationError) as exc_info:
        EmpresaCandidato(
            razon_social="Tecnoinnsoft SAS",
            fuente="socrata",
        )  # type: ignore[call-arg]
    errors = exc_info.value.errors()
    assert any("nit" in str(err.get("loc", ())) for err in errors), (
        f"expected error on nit, got: {errors}"
    )


def test_empresa_candidato_accepts_optional_municipio() -> None:
    """EmpresaCandidato MUST accept optional municipio (default None)."""
    from empresas_colombia.schemas import EmpresaCandidato

    candidate = EmpresaCandidato(
        razon_social="Foo",
        nit="1",
        fuente="rues",
    )
    assert candidate.municipio is None

    candidate_with_mun = EmpresaCandidato(
        razon_social="Foo",
        nit="1",
        fuente="rues",
        municipio="Bogota",
    )
    assert candidate_with_mun.municipio == "Bogota"


def test_empresa_candidato_roundtrip_json() -> None:
    """EmpresaCandidato MUST roundtrip via JSON without losing data."""
    from empresas_colombia.schemas import EmpresaCandidato

    original = EmpresaCandidato(
        razon_social="Foo SA",
        nit="900",
        fuente="socrata",
        municipio="Medellin",
    )
    json_text = original.model_dump_json()
    reloaded = EmpresaCandidato.model_validate_json(json_text)
    assert reloaded == original


def test_clasificacion_arl_accepts_levels_1_to_5() -> None:
    """ClasificacionARL MUST accept clase_riesgo_arl_num in 1..5."""
    from empresas_colombia.schemas import ClasificacionARL

    for level in (1, 2, 3, 4, 5):
        c = ClasificacionARL(
            clase_riesgo_arl_num=level,
            clase_riesgo_arl_desc="X",
            sector_economico="Y",
        )
        assert c.clase_riesgo_arl_num == level


def test_clasificacion_arl_rejects_levels_out_of_range() -> None:
    """ClasificacionARL MUST reject numbers outside 1..5."""
    from empresas_colombia.schemas import ClasificacionARL

    for bad in (0, 6, -1, 100):
        with pytest.raises(ValidationError):
            ClasificacionARL(
                clase_riesgo_arl_num=bad,
                clase_riesgo_arl_desc="X",
                sector_economico="Y",
            )


def test_actividad_economica_fields() -> None:
    """ActividadEconomica MUST carry ciiu_codigo + descripcion."""
    from empresas_colombia.schemas import ActividadEconomica

    a = ActividadEconomica(ciiu_codigo="6202", descripcion="Software")
    assert a.ciiu_codigo == "6202"
    assert a.descripcion == "Software"


def test_empresa_enriquecida_composes_arl_and_actividad() -> None:
    """EmpresaEnriquecida MUST compose ARL + ActividadEconomica + NIT/RazonSocial."""
    from empresas_colombia.schemas import (
        ActividadEconomica,
        ClasificacionARL,
        EmpresaEnriquecida,
    )

    record = EmpresaEnriquecida(
        nit="900123456-7",
        razon_social="Tecnoinnsoft SAS",
        ciiu_codigo="6202",
        cantidad_empleados=42,
        arl=ClasificacionARL(
            clase_riesgo_arl_num=3,
            clase_riesgo_arl_desc="Medio",
            sector_economico="Servicios",
        ),
        actividad_economica=ActividadEconomica(
            ciiu_codigo="6202",
            descripcion="Desarrollo de software",
        ),
        fuente="socrata",
    )
    assert record.nit == "900123456-7"
    assert record.arl.clase_riesgo_arl_num == 3
    assert record.actividad_economica.ciiu_codigo == "6202"


def test_empresa_enriquecida_fuente_must_be_known() -> None:
    """EmpresaEnriquecida.fuente MUST be one of socrata|rues|manual."""
    from empresas_colombia.schemas import (
        ClasificacionARL,
        EmpresaEnriquecida,
    )

    # Valid sources
    for src in ("socrata", "rues", "manual"):
        rec = EmpresaEnriquecida(
            nit="1",
            razon_social="X",
            ciiu_codigo="6202",
            cantidad_empleados=0,
            arl=ClasificacionARL(
                clase_riesgo_arl_num=1,
                clase_riesgo_arl_desc="Minimo",
                sector_economico="Primario",
            ),
            fuente=src,
        )
        assert rec.fuente == src

    # Invalid source rejected
    with pytest.raises(ValidationError):
        EmpresaEnriquecida(
            nit="1",
            razon_social="X",
            ciiu_codigo="6202",
            cantidad_empleados=0,
            arl=ClasificacionARL(
                clase_riesgo_arl_num=1,
                clase_riesgo_arl_desc="Minimo",
                sector_economico="Primario",
            ),
            fuente="bogus-source",
        )


def test_empresa_enriquecida_optional_cantidad_empleados() -> None:
    """EmpresaEnriquecida.cantidad_empleados MUST default to None (unknown)."""
    from empresas_colombia.schemas import (
        ClasificacionARL,
        EmpresaEnriquecida,
    )

    rec = EmpresaEnriquecida(
        nit="1",
        razon_social="X",
        ciiu_codigo="6202",
        arl=ClasificacionARL(
            clase_riesgo_arl_num=1,
            clase_riesgo_arl_desc="Minimo",
            sector_economico="Primario",
        ),
        fuente="socrata",
    )
    assert rec.cantidad_empleados is None


def test_empresa_candidato_list_serializes_as_json_array() -> None:
    """EmpresaCandidatoList MUST serialize as a JSON list."""
    from empresas_colombia.schemas import EmpresaCandidato, EmpresaCandidatoList

    items = EmpresaCandidatoList(
        root=[
            EmpresaCandidato(razon_social="A", nit="1", fuente="socrata"),
            EmpresaCandidato(razon_social="B", nit="2", fuente="rues"),
        ]
    )
    payload = items.model_dump_json()
    parsed: list[Any] = []
    import json

    parsed = json.loads(payload)
    assert isinstance(parsed, list)
    assert len(parsed) == 2
    assert parsed[0]["razon_social"] == "A"


def test_empresa_candidato_list_accepts_empty() -> None:
    """EmpresaCandidatoList MUST accept an empty list."""
    from empresas_colombia.schemas import EmpresaCandidatoList

    items = EmpresaCandidatoList(root=[])
    assert items.root == []
    assert items.model_dump() == []


def test_empresa_candidato_list_iteration() -> None:
    """EmpresaCandidatoList MUST be iterable (delegates to root)."""
    from empresas_colombia.schemas import EmpresaCandidato, EmpresaCandidatoList

    items = EmpresaCandidatoList(
        root=[
            EmpresaCandidato(razon_social="A", nit="1", fuente="socrata"),
        ]
    )
    iterated = [c.razon_social for c in items]
    assert iterated == ["A"]