<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * MergeDuplicateEntitiesSeeder — Fusiona entidades duplicadas post-CSV.
 *
 * Los duplicados se generan porque OportunidadCsvImportUseCase crea entidades
 * sobre la marcha, y el newEntityMap se resetea por chunk. Esto hace que una
 * misma entidad aparezca como "nueva" en chunks diferentes.
 *
 * Este seeder define pares duplicados CONOCIDOS y los mergea al final del seed,
 * garantizando que el resultado sea reproducible en dev, staging y prod.
 *
 * Estrategia:
 *   - Pares auto-detectados por nombre normalizado (misma lógica que normalizeEntityName)
 *   - Un par explícito para ARCILLAS MCL S.A.S ↔ ARCILLAS MLC (difieren post-normalización)
 *   - Gana la entidad con más oportunidades; si empate, la de menor ID
 *   - Idempotente: si no se encuentran los duplicados, no hace nada
 */
class MergeDuplicateEntitiesSeeder extends Seeder
{
    /**
     * Pares duplicados definidos por nombre normalizado.
     * key => normalized name (tras normalizeEntityName)
     * Si dos entidades comparten el mismo normalized, se mergean.
     */
    private const NORMALIZED_PAIRS = [
        'aef',
        'anasya ambiental',
        'flota guaitara',
    ];

    /**
     * Pares explícitos que NO comparten el mismo normalized name
     * pero son la misma entidad real. Formato: [nombre1, nombre2]
     */
    private const EXPLICIT_PAIRS = [
        ['ARCILLAS MCL S.A.S', 'ARCILLAS MLC'],
    ];

    public function run(): void
    {
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('🔗 Buscando entidades duplicadas...');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $merged = 0;

        // Fase 1: pares por normalized name
        foreach (self::NORMALIZED_PAIRS as $normalized) {
            $ids = $this->findEntitiesByNormalizedName($normalized);
            if (count($ids) >= 2) {
                $this->mergeGroup($ids, "normalizado: \"{$normalized}\"");
                $merged++;
            }
        }

        // Fase 2: pares explícitos
        foreach (self::EXPLICIT_PAIRS as [$name1, $name2]) {
            $id1 = $this->findEntityByExactName($name1);
            $id2 = $this->findEntityByExactName($name2);

            if ($id1 && $id2 && $id1 !== $id2) {
                $this->command->info("  Detectado par explícito: \"{$name1}\" ↔ \"{$name2}\"");
                $this->mergeGroup([$id1, $id2], "explícito: \"{$name1}\" ↔ \"{$name2}\"");
                $merged++;
            } elseif ($id1 && $id2 && $id1 === $id2) {
                $this->command->warn("  ⚠ Par explícito \"{$name1}\" ↔ \"{$name2}\" apunta a la misma entidad (ya mergeada), saltando.");
            } else {
                $this->command->warn("  ⚠ Par explícito \"{$name1}\" ↔ \"{$name2}\" no encontrado (puede que ya esté mergeado).");
            }
        }

        if ($merged === 0) {
            $this->command->info('  No se encontraron duplicados para mergear.');
        }

        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    /**
     * Mergear un grupo de entidades (2+) en una sola.
     * Gana la que tenga más oportunidades; si empate, la de menor ID.
     */
    private function mergeGroup(array $ids, string $label): void
    {
        // Commit 4 dropped `entidad.dominio` and `entidad.red_social_url`
        // from the entidad table; the canonical replacements are rows in
        // `presencia_online`. We don't need those columns here — the
        // inheritance helper below reads from `presencia_online` directly.
        $entities = DB::table('entidad')->whereIn('id', $ids)->get();
        if ($entities->count() < 2) {
            return;
        }

        // Determinar ganadora: más oportunidades → menor ID
        $winner = null;
        $maxOpps = -1;

        foreach ($entities as $ent) {
            $oppCount = DB::table('oportunidad')->where('entidad_id', $ent->id)->count();
            if ($oppCount > $maxOpps || ($oppCount === $maxOpps && ($winner === null || $ent->id < $winner->id))) {
                $winner = $ent;
                $maxOpps = $oppCount;
            }
        }

        if (! $winner) {
            $this->command->warn("    ⚠ No se pudo determinar ganadora para {$label}");

            return;
        }

        $losers = $entities->where('id', '!=', $winner->id);

        $this->command->info("  → {$label}");
        $this->command->info("    Ganadora: [{$winner->id}] {$winner->nombre} ({$maxOpps} ops)");

        foreach ($losers as $loser) {
            $loserOpps = DB::table('oportunidad')->where('entidad_id', $loser->id)->count();

            DB::transaction(function () use ($winner, $loser) {
                // Reasignar oportunidades
                $opps = DB::table('oportunidad')
                    ->where('entidad_id', $loser->id)
                    ->update(['entidad_id' => $winner->id, 'updated_at' => now()]);

                // Reasignar seguimientos
                DB::table('seguimiento')
                    ->where('entidad_id', $loser->id)
                    ->update(['entidad_id' => $winner->id, 'updated_at' => now()]);

                // Borrar contactos de la huérfana.
                // Commit fe99f70 dropped `contacto.entidad_id`; the
                // canonical link is `contacto.persona_id → entidad_persona`.
                // Delete every contacto whose persona is bound to the
                // loser entidad via the pivot.
                DB::statement('
                    DELETE c FROM contacto c
                    INNER JOIN entidad_persona ep ON ep.persona_id = c.persona_id
                    WHERE ep.entidad_id = ?
                ', [$loser->id]);
                // Also drop the orphan pivot rows pointing at the loser.
                DB::table('entidad_persona')
                    ->where('entidad_id', $loser->id)
                    ->delete();

                // Heredar dominio/red_social si la ganadora no tiene.
                // Commit 4 dropped `entidad.dominio` and
                // `entidad.red_social_url` — the canonical replacements
                // live on `presencia_online` (tipo='web' for dominio,
                // tipo='red_social' for the social URL). Re-point any
                // presencia_online rows from the loser to the winner
                // when the winner has none of that tipo.
                $this->inheritPresenciaOnline($winner->id, $loser->id);

                // Borrar entidad huérfana
                DB::table('entidad')
                    ->where('id', $loser->id)
                    ->delete();
            });

            $this->command->info("    🗑  Huérfana: [{$loser->id}] {$loser->nombre} ({$loserOpps} ops reasignadas)");
        }
    }

    /**
     * Encontrar IDs de entidades cuyo nombre normalizado coincida.
     */
    private function findEntitiesByNormalizedName(string $target): array
    {
        $entities = DB::table('entidad')->get(['id', 'nombre']);
        $ids = [];

        foreach ($entities as $ent) {
            if ($this->normalizeEntityName($ent->nombre) === $target) {
                $ids[] = (int) $ent->id;
            }
        }

        return $ids;
    }

    /**
     * Encontrar una entidad por nombre exacto (case-insensitive).
     */
    private function findEntityByExactName(string $name): ?int
    {
        $ent = DB::table('entidad')
            ->whereRaw('LOWER(TRIM(nombre)) = ?', [strtolower(trim($name))])
            ->first();

        return $ent ? (int) $ent->id : null;
    }

    /**
     * Normalización agresiva de nombre de entidad: remueve sufijos legales,
     * puntuación, stop words — devuelve solo el núcleo identificador.
     */
    private function normalizeEntityName(string $name): string
    {
        $name = strtolower(trim($name));

        // Normalizar espacios alrededor de puntuación
        $name = preg_replace('/\s*\.\s*/', '.', $name);
        $name = preg_replace('/\s*-\s*/', '-', $name);

        // Remover sufijos legales
        $suffixPatterns = [
            '/^s\.?\s*a\.?\s*s\.?\s*\b/',
            '/\b(s\.?\s*a\.?\s*s\.?)\s*$/',
            '/\b(s\s*a\s*s)\s*$/',
            '/\b(l\.?\s*t\.?\s*d\.?\s*a\.?)\s*$/',
            '/\b(l\s*t\s*d\s*a?)\s*$/',
            '/\b(s\.?\s*a\.?)\s*$/',
            '/\b(s\s*a)\s*$/',
            '/\b(e\.?\s*u\.?)\s*$/',
            '/\b(s\.?\s*e\.?\s*n\.?\s*c\.?)\s*$/',
            '/\b(inc\.?)\s*$/',
            '/\b(corp\.?)\s*$/',
            '/\b(ltda\.?)\s*$/',
            '/\b(sas)\s*$/',
            '/\b(eu)\s*$/',
            '/\b(s\.a)\s*$/',
        ];
        foreach ($suffixPatterns as $pattern) {
            $name = preg_replace($pattern, '', $name);
        }

        // Remover palabras sueltas
        $removeWords = [
            'sas', 'ltda', 'ltd', 'sa', 's.a', 's.a.s', 'e.u', 'eu',
            'inc', 'corp', 'foundation', 'fundacion', 'corporacion',
            'sociedad', 'anonima', 'cooperativa', 'asociacion',
        ];
        $words = explode(' ', $name);
        $words = array_filter($words, fn ($w) => ! in_array(trim($w), $removeWords));
        $name = implode(' ', $words);

        // Remover caracteres especiales y colapsar espacios
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        // Remover stop words
        $stopWords = ['y', 'de', 'del', 'la', 'los', 'las', 'el', 'en', 'para', 'con', 'sin', 'por', 'e', 'o', 'a', 'su', 'un', 'una'];
        $words = explode(' ', trim($name));
        $words = array_filter($words, fn ($w) => ! in_array(trim($w), $stopWords));
        $name = implode(' ', $words);

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Re-point presencia_online rows from the loser entidad to the
     * winner entidad, but only for `tipo` rows the winner does NOT
     * already have. This preserves the legacy "inherit dominio /
     * red_social_url" semantics after Commit 4 moved those columns
     * off `entidad` into `presencia_online`.
     */
    private function inheritPresenciaOnline(int $winnerId, int $loserId): void
    {
        foreach (['web', 'red_social'] as $tipo) {
            $winnerHas = DB::table('presencia_online')
                ->where('entidad_id', $winnerId)
                ->where('tipo', $tipo)
                ->exists();
            if ($winnerHas) {
                continue;
            }

            DB::table('presencia_online')
                ->where('entidad_id', $loserId)
                ->where('tipo', $tipo)
                ->update([
                    'entidad_id' => $winnerId,
                    'updated_at' => now(),
                ]);
        }
    }
}
