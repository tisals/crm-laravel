<?php
/**
 * Data quality investigation: find duplicate entities and contacts.
 * Run with: docker exec crm-laravel-dev php /var/www/html/database/investigate_dupes.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== ENTITIES WITH SAME DOMAIN ===\n";
$domains = DB::table('entidad')
    ->whereNotNull('dominio')
    ->where('dominio', '!=', '')
    ->selectRaw('LOWER(TRIM(dominio)) as dominio, GROUP_CONCAT(id ORDER BY id) as ids, GROUP_CONCAT(nombre ORDER BY id SEPARATOR " | ") as nombres, COUNT(*) as cnt')
    ->groupByRaw('LOWER(TRIM(dominio))')
    ->having('cnt', '>', 1)
    ->get();

foreach ($domains as $d) {
    echo "  Domain: {$d->dominio}\n";
    echo "    IDs: {$d->ids}\n";
    echo "    Names: {$d->nombres}\n";
}
echo "Total domain duplicates: " . $domains->count() . "\n\n";

echo "=== ENTITIES WITH SAME NORMALIZED NAME ===\n";
// Aggressive normalization function matching all suffix variations
function superNormalize(string $name): string {
    $name = strtolower(trim($name));
    // Remove all legal suffixes with any combination of dots/spaces/case
    // Patterns: SAS, S.A.S, S. A. S, s a s, S A S, sas, LTDA, Ltda, S.A, SA, etc
    $suffixPatterns = [
        '/s\.?\s*a\.?\s*s\.?\s*/',  // S.A.S., S. A. S., SAS, s a s, s.a.s.
        '/l[tí]?\.?\s*d[aá]?\.?\s*/i', // LTDA, Ltda., L T D A
        '/s\.?\s*a\.?\s*/',           // S.A., SA, S. A., s a
        '/e\.?\s*u\.?\s*/',           // E.U., EU, E. U.
        '/l\.?\s*t\.?\s*d\.?\s*/',    // L.T.D.
        '/s\.?\s*e\.?\s*n\.?\s*c\.?\s*/', // S. en C., S EN C
        '/inc\.?\s*/i',
        '/corp\.?\s*/i',
        '/foundation/i',
        '/fundacion/i',
        '/corporacion/i',
        '/cooperativa/i',
        '/sociedad\s+(por\s+)?acciones\s+simplificadas/i',
        '/sociedad\s+anonima/i',
        '/asociacion/i',
    ];
    foreach ($suffixPatterns as $pattern) {
        $name = preg_replace($pattern, '', $name);
    }
    // Remove punctuation except letters and numbers
    $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
    // Collapse multiple spaces
    $name = preg_replace('/\s+/', ' ', $name);
    // Remove common stop words that don't distinguish entities
    $stopWords = ['y', 'de', 'del', 'la', 'los', 'las', 'el', 'en', 'para', 'con', 'sin', 'por', 'e', 'o', 'a', 'su'];
    $words = explode(' ', trim($name));
    $words = array_filter($words, fn($w) => !in_array(trim($w), $stopWords));
    $name = implode(' ', $words);
    return trim(preg_replace('/\s+/', ' ', $name));
}

$allEntities = DB::table('entidad')->get(['id', 'nombre']);
$normalized = [];
foreach ($allEntities as $e) {
    $key = superNormalize($e->nombre);
    $normalized[$key][] = $e->id;
}

$dupCount = 0;
foreach ($normalized as $key => $ids) {
    if (count($ids) > 1) {
        $dupCount++;
        $names = $allEntities->whereIn('id', $ids)->pluck('nombre')->implode(' | ');
        echo "  Key: [$key]\n";
        echo "    IDs: " . implode(', ', $ids) . "\n";
        echo "    Names: $names\n";
    }
}
echo "Total normalized name duplicates: $dupCount\n\n";

echo "=== CONTACTS WITH SAME EMAIL (same entidad) ===\n";
$contacts = DB::table('contacto')
    ->whereNotNull('email_contacto')
    ->where('email_contacto', '!=', '')
    ->whereNotNull('entidad_id')
    ->selectRaw('entidad_id, LOWER(TRIM(email_contacto)) as email, GROUP_CONCAT(id ORDER BY id) as ids, GROUP_CONCAT(COALESCE(nombres,"?") ORDER BY id SEPARATOR " | ") as names, COUNT(*) as cnt')
    ->groupByRaw('entidad_id, LOWER(TRIM(email_contacto))')
    ->having('cnt', '>', 1)
    ->get();

foreach ($contacts as $c) {
    echo "  Entidad {$c->entidad_id} - Email: {$c->email}\n";
    echo "    IDs: {$c->ids}\n";
    echo "    Names: {$c->names}\n";
}
echo "Total email duplicates: " . $contacts->count() . "\n\n";

echo "=== CONTACTS WITHOUT EMAIL AND WITHOUT NAME ===\n";
$noEmailNoName = DB::table('contacto')
    ->where(function($q) {
        $q->whereNull('email_contacto')->orWhere('email_contacto', '');
    })
    ->where(function($q2) {
        $q2->whereNull('nombres')->orWhere('nombres', '')->orWhere('nombres', 'Sin nombre');
    })
    ->count();
echo "Contacts without email AND without name: $noEmailNoName\n\n";

echo "=== ENTITIES WITHOUT CONTACTS ===\n";
$entityNoContact = DB::table('entidad')
    ->leftJoin('contacto', 'entidad.id', '=', 'contacto.entidad_id')
    ->whereNull('contacto.id')
    ->count();
echo "Entities without any contacts: $entityNoContact\n";
echo "(First 10):\n";
$sample = DB::table('entidad')
    ->leftJoin('contacto', 'entidad.id', '=', 'contacto.entidad_id')
    ->whereNull('contacto.id')
    ->limit(10)
    ->pluck('entidad.nombre');
foreach ($sample as $s) echo "  - $s\n";
