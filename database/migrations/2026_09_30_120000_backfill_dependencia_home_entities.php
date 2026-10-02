<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `dependencia` for the 5 internal users in their two home
 * entities (Tecnoinnsoft SAS BIC, id=128, and Desecurity.net, id=2476).
 *
 * Context: the `2026_08_29_000004` migration added the `categoria` ENUM
 * (`dependencia` / `asignacion` / `delegacion`) and intended the existing
 * rows for internal users to be backfilled to `dependencia`. In this DB
 * those rows were all `asignacion` — including the home entities. That
 * broke `extractEntities()` (the `dependencia + delegacion` filter
 * returned 0 entities for everyone) and the conceptual model the schema
 * was designed for.
 *
 * This migration flips the 10 rows (5 users × 2 home entities) from
 * `asignacion` to `dependencia` so:
 *     - Mercurio's `mercurio_users_snapshot.entities` is populated with
 *       the user's actual memberships (Tecnoinnsoft + Desecurity) instead
 *       of the 700+ client accounts where they have comercial assignments.
 *     - `GetMyIdentityUseCase::computeFromDb()` continues to compute apps
 *       transitively via `entidad_persona` → `app_entidad` and admin@'
 *       s apps-per-entity set is anchored to its real home entities.
 *
 * Idempotent: re-running on already-`dependencia` rows is a no-op
 * (the WHERE filter `categoria = 'asignacion'` skips them).
 * Reversible: `down()` flips back to `asignacion`.
 *
 * Scope: ONLY the 5 internal users × 2 home entities. Other users
 * (service accounts, future hires) and other entities are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update(
            "UPDATE entidad_persona ep
             JOIN usuarios u ON u.persona_id = ep.persona_id
             SET ep.categoria = 'dependencia'
             WHERE u.email IN (
                 'innovacionydesarrollo.tis@gmail.com',
                 'gestorcomercial.tis@gmail.com',
                 'direccion.tis@gmail.com',
                 'servicioalcliente.tis@gmail.com',
                 'admin@tecnoinnsoft.dev'
             )
             AND ep.entidad_id IN (128, 2476)
             AND ep.categoria = 'asignacion'"
        );
    }

    public function down(): void
    {
        DB::update(
            "UPDATE entidad_persona ep
             JOIN usuarios u ON u.persona_id = ep.persona_id
             SET ep.categoria = 'asignacion'
             WHERE u.email IN (
                 'innovacionydesarrollo.tis@gmail.com',
                 'gestorcomercial.tis@gmail.com',
                 'direccion.tis@gmail.com',
                 'servicioalcliente.tis@gmail.com',
                 'admin@tecnoinnsoft.dev'
             )
             AND ep.entidad_id IN (128, 2476)
             AND ep.categoria = 'dependencia'"
        );
    }
};