"""Pydantic v2 schemas for the empresas-colombia MCP server.

These models define the wire shapes returned by every MCP tool and
resource. They are intentionally framework-light (no FastMCP imports) so
they can be unit-tested in isolation.

Schemas:
- ``EmpresaCandidato`` — single Socrata/RUES candidate row.
- ``EmpresaCandidatoList`` — list of candidates (RootModel wrapper).
- ``ClasificacionARL`` — Decreto 768/2022 ARL class + sector.
- ``ActividadEconomica`` — CIIU code + human description.
- ``EmpresaEnriquecida`` — full enriched record (MCP single-match shape).
"""

from __future__ import annotations

from typing import Iterator, List, Literal

from pydantic import BaseModel, ConfigDict, Field, RootModel


Fuente = Literal["socrata", "rues", "manual"]


class EmpresaCandidato(BaseModel):
    """Single candidate row returned by ``buscar_por_dominio``."""

    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    razon_social: str = Field(..., min_length=1, description="Legal name")
    nit: str = Field(..., min_length=1, description="Tax ID")
    fuente: Fuente = Field(..., description="Origin dataset")
    municipio: str | None = Field(
        default=None, description="Optional registered municipality"
    )


class EmpresaCandidatoList(RootModel[List[EmpresaCandidato]]):
    """List wrapper for candidates; serializes as a flat JSON array."""

    root: List[EmpresaCandidato] = Field(default_factory=list)

    def __iter__(self) -> Iterator[EmpresaCandidato]:  # type: ignore[override]
        return iter(self.root)

    def __len__(self) -> int:
        return len(self.root)

    def __bool__(self) -> bool:
        return bool(self.root)


class ClasificacionARL(BaseModel):
    """Decreto 768/2022 ARL classification for a CIIU code."""

    model_config = ConfigDict(extra="forbid")

    clase_riesgo_arl_num: int = Field(
        ..., ge=1, le=5, description="Risk level (1=min, 5=max)"
    )
    clase_riesgo_arl_desc: str = Field(
        ..., min_length=1, description="Human description (Minimo..Maximo)"
    )
    sector_economico: str = Field(
        ..., min_length=1, description="Sector (Primario|Secundario|Terciario)"
    )


class ActividadEconomica(BaseModel):
    """CIIU code + human-readable activity description."""

    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    ciiu_codigo: str = Field(..., min_length=4, max_length=8, description="CIIU code (4 or 7 digit)")
    descripcion: str = Field(..., min_length=1, description="Activity description")


class EmpresaEnriquecida(BaseModel):
    """Full enriched record returned by ``consultar_empresa``."""

    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    nit: str = Field(..., min_length=1)
    razon_social: str = Field(..., min_length=1)
    ciiu_codigo: str = Field(..., min_length=4, max_length=8)
    cantidad_empleados: int | None = Field(
        default=None,
        ge=0,
        description="Number of employees (None = unknown)",
    )
    arl: ClasificacionARL
    actividad_economica: ActividadEconomica | None = Field(
        default=None,
        description="Optional human description from RUES",
    )
    fuente: Fuente


__all__ = [
    "Fuente",
    "EmpresaCandidato",
    "EmpresaCandidatoList",
    "ClasificacionARL",
    "ActividadEconomica",
    "EmpresaEnriquecida",
]