<?php

namespace App\Empresas\Application\Services;

/**
 * PR3 of `complementar-entidad` — service result status enum.
 *
 * The application service returns a `ServiceResult` whose `status`
 * field carries one of these values so callers (controllers, listeners,
 * the queue worker) can branch on what actually happened instead of
 * relying on a nullable return contract.
 */
enum ServiceResultStatus: string
{
    /** Service completed enrichment and persisted the annex row. */
    case Enriched = 'enriched';

    /** >1 candidates returned by MCP; no annex row was persisted. */
    case NeedsSelection = 'needs_selection';

    /** MCP lookup or Decreto resolution failed after exhaustion. */
    case Failed = 'failed';

    /** Habeas Data filter (exclude mode) dropped the payload. */
    case SkippedHabeas = 'skipped_habeas';

    /** EMIT_EMPRESAS_ENRIQUECIMIENTO=false short-circuited the flow. */
    case SkippedKillSwitch = 'skipped_kill_switch';
}