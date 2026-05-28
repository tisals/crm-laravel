<?php
/**
 * DATA QUALITY CLEANUP SCRIPT
 * 
 * Merges duplicate entities and contacts, cleans orphans.
 * 
 * Phases:
 *   1. Merge entities sharing a custom domain (not free email providers)
 *   2. Merge entities sharing a normalized name (aggressive suffix handling)
 *   3. Merge contacts sharing the same email within the same entity
 *   4. Merge contacts sharing the same name within the same entity  
 *   5. Delete contacts without email AND without name (if no linked oportunidades)
 *   6. Delete entities without contacts (except Propia brands)
 * 
 * Run: docker exec crm-laravel-dev php /var/www/html/database/cleanup_dupes.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== DATA QUALITY CLEANUP ===\n\n";

// ============================================================
// STEP 0: Aggressive name normalization
// ============================================================

/**
 * Remove ALL variations of legal suffixes from an entity name.
 * Handles: SAS, S.A.S., S. A. S., s a s, S.A.S, LTDA, Ltda., 
 * L T D A, S.A., SA, S. A., E.U., EU, S. en C., etc.
 */
function superNormalize(string $name): string {
    $name = strtolower(trim($name));
    
    // Step 1: Normalize spaces around punctuation for consistent removal
    $name = preg_replace('/\s*\.\s*/', '.', $name);  // "S. A. S." → "S.A.S."
    $name = preg_replace('/\s*-\s*/', '-', $name);   // "INDU - B" → "INDU-B"
    
    // Step 2: Remove all known legal suffixes (in order of specificity)
    $patterns = [
        '/^s\.?\s*a\.?\s*s\.?\s*\b/i',                    // SAS at start
        '/\b(s\.?\s*a\.?\s*s\.?)\s*$/i',                  // S.A.S. at end
        '/\b(s\s*a\s*s)\s*$/i',                            // S A S at end
        '/\b(l\.?\s*t\.?\s*d\.?\s*a\.?)\s*$/i',           // L.T.D.A.
        '/\b(l\s*t\s*d\s*a?)\s*$/i',                      // L T D A
        '/\b(s\.?\s*a\.?)\s*$/i',                          // S.A. at end
        '/\b(s\s*a)\s*$/i',                                // S A at end
        '/\b(e\.?\s*u\.?)\s*$/i',                          // E.U.
        '/\b(s\.?\s*e\.?\s*n\.?\s*c\.?)\s*$/i',           // S.EN.C.
        '/\b(inc\.?)\s*$/i',                                // Inc.
        '/\b(corp\.?)\s*$/i',                               // Corp.
        '/\b(ltda\.?)\s*$/i',                               // Ltda.
        '/\b(sas)\s*$/i',                                   // sas (plain)
        '/\b(eu)\s*$/i',                                    // eu
        '/\b(s\.a)\s*$/i',                                  // s.a
    ];
    
    foreach ($patterns as $pattern) {
        $name = preg_replace($pattern, '', $name);
    }
    
    // Step 3: Remove standalone words that don't identify the company
    $removeWords = [
        'sas', 'ltda', 'ltd', 'sa', 's.a', 's.a.s', 'e.u', 'eu',
        'inc', 'corp', 'foundation', 'fundacion', 'corporacion',
        'sociedad', 'anonima', 'cooperativa', 'asociacion',
    ];
    $words = explode(' ', $name);
    $words = array_filter($words, fn($w) => !in_array(trim($w), $removeWords));
    $name = implode(' ', $words);
    
    // Step 4: Remove special chars and collapse spaces
    $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    
    // Step 5: Remove common stop words
    $stopWords = ['y', 'de', 'del', 'la', 'los', 'las', 'el', 'en', 'para', 'con', 'sin', 'por', 'e', 'o', 'a', 'su', 'un', 'una'];
    $words = explode(' ', trim($name));
    $words = array_filter($words, fn($w) => !in_array(trim($w), $stopWords));
    $name = implode(' ', $words);
    
    return trim(preg_replace('/\s+/', ' ', $name));
}

// Generic email domains (NOT custom company domains)
$genericDomains = [
    'gmail.com', 'hotmail.com', 'yahoo.com', 'outlook.com', 'live.com',
    'hotmail.es', 'yahoo.es', 'outlook.es', 'live.com.mx', 'gmail.es',
    'icloud.com', 'protonmail.com', 'mail.com', 'yandex.com', 'aol.com',
    'msn.com', 'ymail.com', 'inbox.com', 'zoho.com', 'gmx.com',
];

echo "--------\n";
echo "PHASE 1: MERGE ENTITIES BY DOMAIN\n";

/**
 * Merge contacts from one entity into another, handling the unique(entidad_id, email_contacto) constraint.
 * Returns [contactsMoved, contactsMerged, fkUpdates].
 */
function mergeContactsIntoEntity(int $fromEntityId, int $toEntityId): array {
    $contacts = DB::table('contacto')->where('entidad_id', $fromEntityId)->get();
    $moved = 0;
    $merged = 0;
    $fkUpdates = 0;
    
    foreach ($contacts as $c) {
        $email = strtolower(trim($c->email_contacto ?? ''));
        if (!empty($email)) {
            // Check if a contact with this email already exists in target entity
            $existing = DB::table('contacto')
                ->where('entidad_id', $toEntityId)
                ->whereRaw('LOWER(TRIM(email_contacto)) = ?', [$email])
                ->first();
            
            if ($existing) {
                // Merge: redirect oportunidades to existing contact, delete duplicate
                $fks = DB::table('oportunidad')->where('contacto_id', $c->id)->update(['contacto_id' => $existing->id]);
                $fkUpdates += $fks;
                DB::table('contacto')->where('id', $c->id)->delete();
                $merged++;
                continue;
            }
        }
        
        // No conflict: move contact to survivor entity
        DB::table('contacto')->where('id', $c->id)->update(['entidad_id' => $toEntityId]);
        $moved++;
    }
    
    return [$moved, $merged, $fkUpdates];
}

$entities = DB::table('entidad')->get(['id', 'nombre', 'dominio', 'estado']);
$entidadMap = [];
foreach ($entities as $e) {
    $entidadMap[$e->id] = $e;
}

// Group by custom domain
$domainGroups = [];
foreach ($entities as $e) {
    if (empty($e->dominio)) continue;
    $domain = strtolower(trim($e->dominio));
    if (in_array($domain, $genericDomains)) continue;
    if (str_contains($domain, '@')) continue;
    
    $domainGroups[$domain][] = $e->id;
}

$domainMerges = 0;
$totalMoved = 0;
$totalMergedContacts = 0;
$totalFkUpdates = 0;

foreach ($domainGroups as $domain => $ids) {
    if (count($ids) < 2) continue;
    sort($ids);
    
    // Check normalized name similarity
    $normalizedNames = [];
    foreach ($ids as $id) {
        $normalizedNames[superNormalize($entidadMap[$id]->nombre)] = true;
    }
    if (count($normalizedNames) > 1) continue;
    
    // Pick survivor (most data)
    $survivor = null;
    $bestScore = -1;
    foreach ($ids as $id) {
        $contactCount = DB::table('contacto')->where('entidad_id', $id)->count();
        $oppCount = DB::table('oportunidad')->where('entidad_id', $id)->count();
        $score = $contactCount * 10 + $oppCount;
        if ($score > $bestScore) {
            $bestScore = $score;
            $survivor = $id;
        }
    }
    if ($survivor === null) $survivor = $ids[0];
    
    foreach ($ids as $dupId) {
        if ($dupId === $survivor) continue;
        
        // Merge contacts handling unique constraint
        [$moved, $merged, $fku] = mergeContactsIntoEntity($dupId, $survivor);
        $totalMoved += $moved;
        $totalMergedContacts += $merged;
        $totalFkUpdates += $fku;
        
        // Update oportunidades' entidad_id
        $oppFks = DB::table('oportunidad')->where('entidad_id', $dupId)->update(['entidad_id' => $survivor]);
        $totalFkUpdates += $oppFks;
        
        // Delete duplicate entity
        DB::table('entidad')->where('id', $dupId)->delete();
        $domainMerges++;
    }
    
    echo "  Domain [$domain]: merged into " . $entidadMap[$survivor]->nombre . " (contacts moved: $totalMoved, merged: $totalMergedContacts)\n";
}

echo "  Total: $domainMerges entity merges ($totalMoved contacts moved, $totalMergedContacts merged, $totalFkUpdates FK updates)\n\n";

echo "--------\n";
echo "PHASE 2: MERGE ENTITIES BY NORMALIZED NAME\n";

// Re-fetch entities (some may have been deleted in phase 1)
$entities = DB::table('entidad')->get(['id', 'nombre', 'dominio', 'estado']);
$entidadMap = [];
foreach ($entities as $e) {
    $entidadMap[$e->id] = $e;
}

// Group by normalized name
$nameGroups = [];
foreach ($entities as $e) {
    $key = superNormalize($e->nombre);
    if (empty($key)) continue;
    $nameGroups[$key][] = $e->id;
}

$nameMerges = 0;
$totalMoved = 0;
$totalMergedContacts = 0;
$totalFkUpdates = 0;

foreach ($nameGroups as $key => $ids) {
    if (count($ids) < 2) continue;
    sort($ids);
    
    // Don't merge entities with DIFFERENT custom domains
    $domains = [];
    foreach ($ids as $id) {
        if (!empty($entidadMap[$id]->dominio) && !in_array(strtolower(trim($entidadMap[$id]->dominio)), $genericDomains)) {
            $domains[strtolower(trim($entidadMap[$id]->dominio))] = true;
        }
    }
    $distinctDomains = array_keys($domains);
    if (count($distinctDomains) > 1) {
        $rawNames = [];
        foreach ($ids as $id) {
            $rawNames[] = strtolower(trim($entidadMap[$id]->nombre));
        }
        if (count(array_unique($rawNames)) > 1) {
            continue;
        }
    }
    
    // Pick survivor
    $survivor = null;
    $bestScore = -1;
    foreach ($ids as $id) {
        $contactCount = DB::table('contacto')->where('entidad_id', $id)->count();
        $oppCount = DB::table('oportunidad')->where('entidad_id', $id)->count();
        $score = $contactCount * 10 + $oppCount;
        if ($score > $bestScore) {
            $bestScore = $score;
            $survivor = $id;
        }
    }
    if ($survivor === null) $survivor = $ids[0];
    
    foreach ($ids as $dupId) {
        if ($dupId === $survivor) continue;
        
        [$moved, $merged, $fku] = mergeContactsIntoEntity($dupId, $survivor);
        $totalMoved += $moved;
        $totalMergedContacts += $merged;
        $totalFkUpdates += $fku;
        
        $oppFks = DB::table('oportunidad')->where('entidad_id', $dupId)->update(['entidad_id' => $survivor]);
        $totalFkUpdates += $oppFks;
        
        DB::table('entidad')->where('id', $dupId)->delete();
        $nameMerges++;
    }
    
    if ($nameMerges > 0) {
        echo "  Name [$key]: merged into " . $entidadMap[$survivor]->nombre . "\n";
    }
}

echo "  Total: $nameMerges entity merges ($totalMoved contacts moved, $totalMergedContacts merged, $totalFkUpdates FK updates)\n\n";

echo "--------\n";
echo "PHASE 3: MERGE CONTACTS BY EMAIL\n";

$contactEmailGroups = DB::table('contacto')
    ->whereNotNull('email_contacto')
    ->where('email_contacto', '!=', '')
    ->whereNotNull('entidad_id')
    ->selectRaw('entidad_id, LOWER(TRIM(email_contacto)) as email, GROUP_CONCAT(id) as ids, COUNT(*) as cnt')
    ->groupByRaw('entidad_id, LOWER(TRIM(email_contacto))')
    ->having('cnt', '>', 1)
    ->get();

$emailMerges = 0;
$emailFksUpdated = 0;

foreach ($contactEmailGroups as $g) {
    $contactIds = explode(',', $g->ids);
    sort($contactIds);
    $survivor = $contactIds[0]; // keep first contact
    
    for ($i = 1; $i < count($contactIds); $i++) {
        $dupId = $contactIds[$i];
        $fk = DB::table('oportunidad')->where('contacto_id', $dupId)->update(['contacto_id' => $survivor]);
        $emailFksUpdated += $fk;
        DB::table('contacto')->where('id', $dupId)->delete();
        $emailMerges++;
    }
}

echo "  Total email merges: $emailMerges (FK updates: $emailFksUpdated)\n\n";

echo "--------\n";
echo "PHASE 4: MERGE CONTACTS BY NAME (same entity)\n";

// Group contacts by (entidad_id, normalized name) where they have the same name but different emails
$allContacts = DB::table('contacto')
    ->whereNotNull('entidad_id')
    ->whereNotNull('nombres')
    ->where('nombres', '!=', '')
    ->where('nombres', '!=', 'Sin nombre')
    ->orderBy('id')
    ->get(['id', 'entidad_id', 'nombres', 'email_contacto']);

$nameGroups = [];
foreach ($allContacts as $c) {
    $normalized = strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $c->nombres)));
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    if (strlen($normalized) < 3) continue; // too short to match
    $key = $c->entidad_id . ':' . $normalized;
    $nameGroups[$key][] = $c->id;
}

$nameContactMerges = 0;
$nameContactFks = 0;

foreach ($nameGroups as $key => $ids) {
    if (count($ids) < 2) continue;
    
    // Pick survivor: the one with an email, or the first one
    $survivor = $ids[0];
    foreach ($ids as $id) {
        $c = $allContacts->firstWhere('id', $id);
        if ($c && !empty($c->email_contacto)) {
            $survivor = $id;
            break;
        }
    }
    
    for ($i = 0; $i < count($ids); $i++) {
        $dupId = $ids[$i];
        if ($dupId === $survivor) continue;
        
        $fk = DB::table('oportunidad')->where('contacto_id', $dupId)->update(['contacto_id' => $survivor]);
        $nameContactFks += $fk;
        DB::table('contacto')->where('id', $dupId)->delete();
        $nameContactMerges++;
    }
}

echo "  Total name-contact merges: $nameContactMerges (FK updates: $nameContactFks)\n\n";

echo "--------\n";
echo "PHASE 5: DELETE CONTACTS WITHOUT EMAIL AND WITHOUT NAME\n";

$badContacts = DB::table('contacto')
    ->where(function($q) {
        $q->whereNull('email_contacto')->orWhere('email_contacto', '');
    })
    ->where(function($q2) {
        $q2->whereNull('nombres')->orWhere('nombres', '')->orWhere('nombres', 'Sin nombre');
    })
    ->get(['id']);

$badDeleted = 0;
foreach ($badContacts as $c) {
    // Only delete if no linked oportunidades
    $opps = DB::table('oportunidad')->where('contacto_id', $c->id)->count();
    if ($opps === 0) {
        DB::table('contacto')->where('id', $c->id)->delete();
        $badDeleted++;
    }
}

echo "  Deleted $badDeleted contacts without email/name\n\n";

echo "--------\n";
echo "PHASE 6: DELETE ENTITIES WITHOUT CONTACTS (except Propia brands)\n";

$entitiesWithoutContacts = DB::table('entidad')
    ->leftJoin('contacto', 'entidad.id', '=', 'contacto.entidad_id')
    ->whereNull('contacto.id')
    ->get(['entidad.id', 'entidad.nombre', 'entidad.estado']);

$entityDeleted = 0;
$entitySkipped = 0;

foreach ($entitiesWithoutContacts as $e) {
    // Protect Propia brands and original seed entities
    if (in_array(strtolower($e->estado ?? ''), ['propia', 'Propia'])) {
        $entitySkipped++;
        continue;
    }
    // Also check if tiene oportunidades ligadas
    $opps = DB::table('oportunidad')->where('entidad_id', $e->id)->count();
    if ($opps > 0) {
        $entitySkipped++;
        continue;
    }
    
    DB::table('entidad')->where('id', $e->id)->delete();
    $entityDeleted++;
}

echo "  Deleted entities without contacts: $entityDeleted (skipped $entitySkipped protected)\n\n";

echo "--------\n";
echo "PHASE 7: GLOBAL CONTACT DEDUP BY EMAIL (across all entities)\n";

$globalDupes = DB::select("
    SELECT LOWER(TRIM(email_contacto)) as email,
           GROUP_CONCAT(id ORDER BY id) as ids,
           COUNT(*) as cnt
    FROM contacto
    WHERE email_contacto IS NOT NULL AND TRIM(email_contacto) != '' AND entidad_id IS NOT NULL
    GROUP BY LOWER(TRIM(email_contacto))
    HAVING cnt > 1
");

$globalMerged = 0;
$globalFks = 0;

foreach ($globalDupes as $g) {
    $ids = explode(',', $g->ids);

    // Pick the best survivor: one with linked oportunidades > more complete data > lowest ID
    $survivor = null;
    $bestScore = -1;
    foreach ($ids as $id) {
        $oppCount = DB::table('oportunidad')->where('contacto_id', $id)->count();
        $contactData = DB::table('contacto')->where('id', $id)->first();
        $dataScore = 0;
        if ($contactData) {
            if (!empty($contactData->nombres) && $contactData->nombres !== 'Sin nombre') $dataScore += 3;
            if (!empty($contactData->cargo)) $dataScore += 2;
            if (!empty($contactData->tel_contacto)) $dataScore += 1;
            if (!empty($contactData->movil)) $dataScore += 1;
        }
        $score = $oppCount * 10 + $dataScore;
        if ($score > $bestScore) {
            $bestScore = $score;
            $survivor = (int) $id;
        }
    }
    if ($survivor === null) $survivor = (int) $ids[0];

    foreach ($ids as $dupId) {
        $dupId = (int) $dupId;
        if ($dupId === $survivor) continue;

        // Transfer any oportunidades pointing to this duplicate
        $fks = DB::table('oportunidad')->where('contacto_id', $dupId)->update(['contacto_id' => $survivor]);
        $globalFks += $fks;
        DB::table('contacto')->where('id', $dupId)->delete();
        $globalMerged++;
    }
}

echo "  Total: $globalMerged contacts merged (FK updates: $globalFks)\n\n";

echo "========================================\n";
echo "CLEANUP COMPLETE\n";

// Final counts
echo "\nFinal counts:\n";
echo "  Entidades: " . DB::table('entidad')->count() . "\n";
echo "  Contactos: " . DB::table('contacto')->count() . "\n";
echo "  Oportunidades: " . DB::table('oportunidad')->count() . "\n";
echo "  Detalles: " . DB::table('detalle_oportunidad')->count() . "\n";
