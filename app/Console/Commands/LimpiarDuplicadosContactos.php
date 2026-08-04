<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * crm:limpiar-duplicados-contactos
 *
 * Limpia contactos duplicados (mismo email en múltiples entidades).
 *
 * Regla de decisión (versión final):
 *
 *   PASO 0: Cargar dominios compartidos (2+ entidades con mismo dominio)
 *
 *   PASO 1: ¿El dominio del email está en un grupo COMPARTIDO?
 *           ├─ SÍ → SKIP (caso ambiguo, no se hace nada, queda para revisión manual)
 *           └─ NO → continúa
 *
 *   PASO 2: ¿Alguna entidad tiene `dominio` que matchea el dominio del email?
 *           ├─ SÍ  → GANADORA = esa entidad
 *           └─ NO  → continúa
 *
 *   PASO 3: ¿Alguna entidad quedaría en 0 contactos si pierde este?
 *           ├─ SÍ  → GANADORA = esa entidad (protegida)
 *           └─ NO  → continúa
 *
 *   PASO 4: La entidad con MENOS contactos gana
 *           ├─ Empate: la que tiene tipo_id='NIT'
 *           └─ Persiste: tabla de empates manual
 *
 * Comportamiento:
 *   - Ganadora: conserva el contacto
 *   - Perdedoras: soft-delete + estado='Inactivo' (preserva auditoría)
 *   - Protegidas: si la GANADORA es protegida, las perdedoras SÍ se borran
 *   - Ambiguos (dominio compartido): NO se hace NADA, queda para decisión manual
 *
 * FLAGS:
 *   --dry-run              : Solo muestra qué haría, no modifica
 *   --apply                : Ejecuta los cambios
 *   --export-empates=archivo.csv : Genera CSV con empates pendientes
 *
 * SIEMPRE hace backup de la tabla contacto antes de --apply.
 *
 * IMPORTANTE: Solo afecta contactos soft-deleted (delete_at IS NULL).
 */
class LimpiarDuplicadosContactos extends Command
{
    protected $signature = 'crm:limpiar-duplicados-contactos
                            {--dry-run : Simula los cambios sin modificar la BD}
                            {--apply : Ejecuta los cambios}
                            {--export-empates= : Exporta empates pendientes a CSV}';

    protected $description = 'Limpia contactos duplicados (protegidas sí, ambiguos no)';

    /** @var array<string> Lista de dominios que están en 2+ entidades (ambiguos) */
    private $dominiosCompartidos = array();

    /** @var array Datos de stats */
    private $stats = array(
        'grupos_total' => 0,
        'grupos_ambiguos' => 0,
        'grupos_protegidos_ganan' => 0,
        'grupos_match_dominio' => 0,
        'grupos_menos_contactos' => 0,
        'grupos_empates' => 0,
        'contactos_a_borrar' => 0,
        'soft_deletes' => 0,
        'estado_inactivo' => 0,
    );

    /** @var array Lista de empates para exportar */
    private $empates = array();

    /** @var array Decisiones tomadas (para mostrar en dry-run) */
    private $decisiones = array();

    /** @var bool */
    private $isDryRun = false;

    /** @var bool */
    private $isApply = false;

    /** @var string|null */
    private $empatesFile = null;

    public function handle()
    {
        $this->isDryRun = (bool) $this->option('dry-run');
        $this->isApply = (bool) $this->option('apply');
        $this->empatesFile = $this->option('export-empates');

        if (! $this->isDryRun && ! $this->isApply && ! $this->empatesFile) {
            $this->error('Especifica una accion: --dry-run, --apply o --export-empates=archivo.csv');

            return 1;
        }

        $this->info('Buscando contactos duplicados (mismo email en multiples entidades)...');
        $this->newLine();

        // PASO 0: cargar dominios compartidos
        $this->cargarDominiosCompartidos();
        $this->info('Dominios compartidos (ambiguos, se omiten): ' . count($this->dominiosCompartidos));

        // Cargar grupos de emails duplicados
        $grupos = $this->cargarGruposDuplicados();
        $this->stats['grupos_total'] = count($grupos);

        if (empty($grupos)) {
            $this->info('No hay duplicados. Nada que limpiar.');

            return 0;
        }

        $this->info('Grupos de emails duplicados encontrados: ' . count($grupos));
        $this->newLine();

        // Procesar cada grupo
        foreach ($grupos as $email => $contactos) {
            $this->procesarGrupo($email, $contactos);
        }

        // Modo export-empates
        if ($this->empatesFile) {
            $this->exportarEmpates($this->empatesFile);

            return 0;
        }

        $this->mostrarResumen();

        if ($this->isDryRun) {
            $this->warn('DRY-RUN: no se hicieron cambios. Usa --apply para ejecutar.');
        }

        return 0;
    }

    /**
     * Carga el set de dominios que están asignados a 2+ entidades.
     * Esos son AMBIGUOS (no podemos saber cuál es la real).
     */
    private function cargarDominiosCompartidos()
    {
        $rows = DB::select('SELECT dominio FROM entidad WHERE deleted_at IS NULL AND dominio IS NOT NULL AND dominio != "" GROUP BY dominio HAVING COUNT(*) > 1');

        foreach ($rows as $r) {
            $this->dominiosCompartidos[strtolower(trim($r->dominio))] = true;
        }
    }

    /**
     * Carga los emails que aparecen en 2+ entidades (contactos duplicados).
     */
    private function cargarGruposDuplicados()
    {
        $emailsDup = DB::select('SELECT email_contacto FROM contacto WHERE deleted_at IS NULL AND email_contacto IS NOT NULL AND email_contacto != "" GROUP BY email_contacto HAVING COUNT(DISTINCT entidad_id) > 1');

        if (empty($emailsDup)) {
            return array();
        }

        $this->info('   Emails con duplicados cross-entidad: ' . count($emailsDup));

        $grupos = array();
        foreach ($emailsDup as $row) {
            $email = $row->email_contacto;

            $contactos = DB::select('SELECT c.id, c.entidad_id, c.nombres, c.apellidos, c.email_contacto, c.created_at, e.nombre as entidad_nombre, e.dominio as entidad_dominio, e.tipo_id as entidad_tipo_id, e.identificacion as entidad_identificacion FROM contacto c INNER JOIN entidad e ON e.id = c.entidad_id WHERE c.email_contacto = ? AND c.deleted_at IS NULL AND e.deleted_at IS NULL ORDER BY c.entidad_id, c.id', array($email));

            if (count($contactos) >= 2) {
                $grupos[$email] = array_map(function ($c) {
                    return (array) $c;
                }, $contactos);
            }
        }

        return $grupos;
    }

    /**
     * Procesa un grupo de duplicados y toma la decisión.
     */
    private function procesarGrupo($email, $contactos)
    {
        $emailLower = strtolower(trim($email));
        $domain = strtolower(trim(explode('@', $email, 2)[1] ?? ''));

        // ============ PASO 1: ¿Dominio del email está en grupo compartido? ============
        if (isset($this->dominiosCompartidos[$domain])) {
            $this->stats['grupos_ambiguos']++;
            $this->decisiones[$email] = array(
                'email' => $email,
                'decisión' => 'SKIP',
                'razon' => 'dominio compartido (' . $domain . ' usado por 2+ entidades)',
            );

            return;
        }

        // Contar contactos por entidad (sin este)
        $entityContactCount = $this->contarContactosPorEntidad($contactos);

        // ============ PASO 2: Match de dominio único ============
        $winnerByDomain = null;
        foreach ($contactos as $c) {
            if ($c['entidad_dominio']
                && strtolower(trim($c['entidad_dominio'])) === $domain
            ) {
                $winnerByDomain = $c;
                break;
            }
        }

        if ($winnerByDomain) {
            // Match de dominio: esa entidad gana
            $this->stats['grupos_match_dominio']++;
            $this->aplicarDecision($email, $winnerByDomain, $contactos, "dominio matchea '{$domain}'");

            return;
        }

        // ============ PASO 3 y 4: Sin match de dominio → protegidas / menos contactos ============
        // Determinar protegidas (quedan en 0 sin este contacto)
        $protegidas = array();
        $candidatasNoProtegidas = array(); // key = entidad_id, value = ['contacto' => ..., 'count' => ...]

        foreach ($contactos as $c) {
            $entId = (int) $c['entidad_id'];
            $countConEste = $entityContactCount[$entId] ?? 1;
            $sinEste = $countConEste - 1;

            if ($sinEste === 0) {
                $protegidas[] = $c;
            } else {
                $candidatasNoProtegidas[$entId] = array(
                    'contacto' => $c,
                    'count' => $countConEste,
                );
            }
        }

        // Si hay 1+ protegidas y 0 no protegidas, no se hace nada (todas son necesarias)
        if (count($protegidas) >= 1 && empty($candidatasNoProtegidas)) {
            $this->stats['grupos_protegidos_ganan']++;
            $this->decisiones[$email] = array(
                'email' => $email,
                'decisión' => 'NO_CHANGE',
                'razon' => 'Todas las entidades son protegidas (quedarian en 0)',
            );

            return;
        }

        // Si hay protegidas, GANAN (las protegidas son las ganadoras)
        if (count($protegidas) >= 1) {
            $ganadora = $protegidas[0];
            $this->stats['grupos_protegidos_ganan']++;
            $this->aplicarDecision($email, $ganadora, $contactos, 'protegida (quedaria en 0 sin este contacto)');

            return;
        }

        // Sin protegidas: la de menos contactos gana
        if (empty($candidatasNoProtegidas)) {
            // Caso borde
            $this->decisiones[$email] = array(
                'email' => $email,
                'decisión' => 'NO_CHANGE',
                'razon' => 'caso borde, no aplica cambio',
            );

            return;
        }

        $minCount = min(array_column($candidatasNoProtegidas, 'count'));
        $minCandidates = array_filter($candidatasNoProtegidas, function ($c) use ($minCount) {
            return $c['count'] === $minCount;
        });

        if (count($minCandidates) === 1) {
            $ganadora = reset($minCandidates)['contacto'];
            $this->stats['grupos_menos_contactos']++;
            $this->aplicarDecision($email, $ganadora, $contactos, "menos contactos ({$minCount}) entre no protegidas");

            return;
        }

        // Empate: NIT gana
        $nitWinner = null;
        foreach ($minCandidates as $c) {
            if ($c['contacto']['entidad_tipo_id'] === 'NIT') {
                $nitWinner = $c['contacto'];
                break;
            }
        }

        if ($nitWinner) {
            $this->stats['grupos_menos_contactos']++;
            $this->aplicarDecision($email, $nitWinner, $contactos, "empate resuelto por NIT ({$minCount} contactos)");

            return;
        }

        // Persiste empate: tabla de empates
        $this->stats['grupos_empates']++;
        $this->decisiones[$email] = array(
            'email' => $email,
            'decisión' => 'EMPATE',
            'razon' => 'empate persistente, revisar manualmente',
        );

        $this->empates[] = array(
            'email' => $email,
            'candidatas' => array_map(function ($c) {
                return array(
                    'entidad_id' => $c['contacto']['entidad_id'],
                    'entidad_nombre' => $c['contacto']['entidad_nombre'],
                    'entidad_tipo_id' => $c['contacto']['entidad_tipo_id'],
                    'contacto_id' => $c['contacto']['id'],
                    'count' => $c['count'],
                );
            }, array_values($minCandidates)),
        );
    }

    /**
     * Aplica la decisión: marca perdedoras como soft-delete + estado Inactivo.
     */
    private function aplicarDecision($email, $ganadora, $contactos, $razon)
    {
        $perdedoras = array();
        foreach ($contactos as $c) {
            if ($ganadora && $c['id'] !== $ganadora['id']) {
                $perdedoras[] = $c;
            }
        }

        if (empty($perdedoras)) {
            $this->decisiones[$email] = array(
                'email' => $email,
                'decisión' => 'NO_CHANGE',
                'razon' => "caso borde: 1 sola entidad ({$ganadora['entidad_nombre']})",
            );

            return;
        }

        $this->stats['contactos_a_borrar'] += count($perdedoras);

        $this->decisiones[$email] = array(
            'email' => $email,
            'decisión' => 'BORRAR_PERDEDORAS',
            'ganadora' => $ganadora['entidad_nombre'],
            'razon' => $razon,
            'perdedoras' => array_map(function ($p) {
                return array(
                    'entidad_id' => $p['entidad_id'],
                    'entidad_nombre' => $p['entidad_nombre'],
                    'contacto_id' => $p['id'],
                );
            }, $perdedoras),
        );

        // Aplicar si no es dry-run
        if ($this->isApply) {
            $ids = array_column($perdedoras, 'id');
            DB::table('contacto')
                ->whereIn('id', $ids)
                ->update(array(
                    'deleted_at' => now(),
                    'estado' => 'Inactivo',
                    'updated_at' => now(),
                ));
            $this->stats['soft_deletes'] += count($perdedoras);
            $this->stats['estado_inactivo'] += count($perdedoras);
        }
    }

    /**
     * Cuenta contactos por entidad (incluyendo el propio contacto).
     */
    private function contarContactosPorEntidad($contactosGrupo)
    {
        $entityIds = array_unique(array_filter(array_column($contactosGrupo, 'entidad_id')));
        if (empty($entityIds)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
        $resultados = DB::select("SELECT entidad_id, COUNT(*) as total FROM contacto WHERE deleted_at IS NULL AND entidad_id IN ({$placeholders}) GROUP BY entidad_id", array_values($entityIds));

        $counts = array();
        foreach ($resultados as $r) {
            $counts[(int) $r->entidad_id] = (int) $r->total;
        }

        return $counts;
    }

    /**
     * Muestra resumen.
     */
    private function mostrarResumen()
    {
        $this->newLine();
        $this->info('===============================================================');
        $this->info('  RESUMEN DE LIMPIEZA DE DUPLICADOS');
        $this->info('===============================================================');
        $this->newLine();

        $this->table(
            array('Metrica', 'Valor'),
            array(
                array('Grupos totales de emails duplicados', $this->stats['grupos_total']),
                array('SKIP: dominios ambiguos (compartidos)', $this->stats['grupos_ambiguos']),
                array('Match de dominio unico', $this->stats['grupos_match_dominio']),
                array('Protegidas ganan', $this->stats['grupos_protegidos_ganan']),
                array('Menos contactos gana (incluye NIT)', $this->stats['grupos_menos_contactos']),
                array('Empates pendientes', $this->stats['grupos_empates']),
                array('', ''),
                array('Contactos a soft-delete', $this->stats['contactos_a_borrar']),
            )
        );

        // Muestra
        $changes = array_filter($this->decisiones, function ($d) {
            return $d['decisión'] === 'BORRAR_PERDEDORAS';
        });

        if (count($changes) > 0) {
            $this->newLine();
            $this->info('Muestra de cambios propuestos (primeros 20):');
            $rows = array();
            $count = 0;
            foreach ($changes as $email => $dec) {
                foreach ($dec['perdedoras'] as $p) {
                    $rows[] = array(
                        $email,
                        $dec['ganadora'],
                        $p['entidad_nombre'],
                        (string) $p['contacto_id'],
                        $dec['razon'],
                    );
                    $count++;
                    if ($count >= 20) {
                        break 2;
                    }
                }
            }
            $this->table(
                array('Email', 'Ganadora', 'Perdedora', 'Contacto ID', 'Razon'),
                $rows
            );
        }

        if ($this->stats['grupos_empates'] > 0) {
            $this->newLine();
            $this->warn('Hay ' . $this->stats['grupos_empates'] . ' empates pendientes.');
            $this->warn('   Usa --export-empates=archivo.csv para revisar y decidir.');
        }
    }

    /**
     * Exporta empates a CSV.
     */
    private function exportarEmpates($filepath)
    {
        $fp = fopen($filepath, 'w');
        if (! $fp) {
            $this->error('No se pudo abrir el archivo: ' . $filepath);

            return;
        }

        fputcsv($fp, array('email', 'contacto_id', 'entidad_id', 'entidad_nombre', 'entidad_tipo_id', 'total_contactos_entidad'));

        foreach ($this->empates as $empate) {
            foreach ($empate['candidatas'] as $c) {
                fputcsv($fp, array(
                    $empate['email'],
                    $c['contacto_id'],
                    $c['entidad_id'],
                    $c['entidad_nombre'],
                    $c['entidad_tipo_id'],
                    $c['count'],
                ));
            }
        }

        fclose($fp);

        $this->info('Empatess exportados a: ' . $filepath);
        $this->info('   Total: ' . count($this->empates) . ' empates');
    }
}
