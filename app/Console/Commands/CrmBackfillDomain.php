<?php

namespace App\Console\Commands;

use App\Traits\UrlCategorizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * crm:backfill-domain — Buscar dominios faltantes via DuckDuckGo.
 *
 * Encuentra entidades sin dominio ni red_social_url, las busca en
 * DuckDuckGo, y opcionalmente escribe el match en la BD.
 *
 * Uso:
 *   php artisan crm:backfill-domain                      # dry-run, muestra candidatos
 *   php artisan crm:backfill-domain --commit            # escribe en BD
 *   php artisan crm:backfill-domain --limit=10          # solo las primeras 10
 *   php artisan crm:backfill-domain --delay=2           # 2s entre queries (rate limit)
 *   php artisan crm:backfill-domain --entity=123        # solo id 123
 *   php artisan crm:backfill-domain --strict            # validar match por heurística
 *
 * Dependencias (instaladas en Dockerfile):
 *   - python3 + py3-pip
 *   - ddgs (DuckDuckGo search library)
 */
class CrmBackfillDomain extends Command
{
    use UrlCategorizer;

    protected $signature = 'crm:backfill-domain
        {--limit=0 : Limitar cantidad de entidades a procesar (0 = todas)}
        {--commit : Escribir resultados en BD (sin esto es dry-run)}
        {--delay=1 : Segundos de espera entre queries}
        {--entity= : Procesar solo este ID de entidad}
        {--strict : Validar match por heurística (más conservador)}
        {--show : Mostrar cada match encontrado y descartado}';

    protected $description = 'Buscar dominios faltantes via DuckDuckGo (ddgs)';

    // Dominios que NO son sitios web de la empresa (job boards, directorios, redes sociales)
    // Estos solo se guardan como `red_social_url` (LinkedIn, FB, IG) o se descartan (Indeed, Prezi).
    // El matching es por subdominio completo, así que "es.indeed.com" matchea
    // cualquier "*.indeed.com".
    private const NOISE_DOMAINS = [
        // Job boards / employment
        'indeed.com', 'jobtome.com', 'glassdoor.com', 'computrabajo.com',
        'elempleo.com', 'jobrapido.com', 'magneto365.com', 'bumeran.com',
        'occ.com.mx', 'mifuturoempleo.co', 'jobbank.com',
        'opcionempleo.com', 'opcionempleo.com.co', 'talent.com',
        'linkedin.com', 'co.linkedin.com', 'www.linkedin.com', 'linkedin.cn',
        'tiktok.com', 'www.tiktok.com',
        // Document sharing / presentations
        'prezi.com', 'slideshare.net', 'issuu.com', 'scribd.com',
        'drive.google.com', 'docs.google.com', 'calameo.com', 'calameo.com.ar',
        'webscolombia.co', 'sites.google.com', 'wordpress.com', 'blogspot.com',
        'wixsite.com', 'weebly.com', 'jimdo.com', 'webnode.com', 'jumpseller.com',
        // Directories / aggregators (MUY importante)
        'registronit.com', 'emis.com', 'direccion.com.co', 'dnb.com',
        'panjiva.com', 'lusha.com', 'telefonicaydireccion.com',
        'paginasamarillas.com.co', 'yellowpages.com.co',
        'telexplorer.com.co', 'lasempresas.com.co',
        'empresite.eleconomistaamerica.co', 'empresite.eleconomista.es',
        'cybo.com', 'es.cybo.com', 'di1.com.ar', 'europages.es',
        'connectamericas.com', 'informacolombia.com', 'directorio-empresas.einforma.co',
        'einforma.co', 'esic.es', 'kompass.com', 'salesfuel.com',
        'zolvo.com', 'zoominfo.com', 'apolo.work',
        'larepublica.co', 'empresas.larepublica.co', 'portafolio.co',
        'empresas.portafolio.co', 'infopiniones.com', 'findglocal.com',
        'edirectorio.net', 'takealuk.com', 'allabolivia.com',
        'co.todosnegocios.com', 'informacion-empresas.co',
        'datosperu.org', 'facebook.com', 'instagram.com',
        'datosperu.com', 'datosperu.info', 'empresarial.com',
        // News / articles / academic
        'wikipedia.org', 'youtube.com', 'youtu.be',
        'fao.org', 'scielo.org', 'scielo.org.co', 'bvsalud.org',
        'doi.org', 'researchgate.net', 'academia.edu',
        // E-commerce / Marketplaces
        'mercadolibre.com', 'mercadolibre.com.co', 'amazon.com', 'amazon.com.co',
        'falabella.com', 'falabella.com.co', 'exito.com', 'linio.com',
        'alibaba.com', 'ebay.com', 'olx.com.co',
        // Tourism / Hotel
        'es.tohotel.com', 'tohotel.com', 'tripadvisor.com', 'tripadvisor.co',
        'tripadvisor.com.mx', 'booking.com', 'hotels-manizales.com', 'adonde.com',
        'alonhadondevivir.com', 'airbnb.com',
        // Data aggregators / directories
        'significadode.org', 'tuhotel.com',
        'seair.co.in',
        // Real estate
        'zonaprop.com', 'fincaraiz.com.co', 'metrocuadrado.com',
        'link.me', 'linktr.ee',
        // Government / institutional (no son empresas)
        'consulado.gov.co', 'cancilleria.gov.co', 'embajada.gov.co',
        'colombianosune.gov.co', 'minsalud.gov.co', 'mineducacion.gov.co',
        'mindeporte.gov.co', 'procuraduria.gov.co', 'contraloria.gov.co',
        'senado.gov.co', 'camara.gov.co', 'corteconstitucional.gov.co',
        'mincomercio.gov.co', 'mincit.gov.co', 'mintic.gov.co', 'minhacienda.gov.co',
        'car.gov.co', 'comisionderegulacion.gov.co', 'gov.co',
        // Education
        'udistrital.edu.co', 'unal.edu.co', 'uniandes.edu.co',
        'eafit.edu.co', 'javeriana.edu.co', 'icesi.edu.co', 'upb.edu.co',
        'usergioarboleda.edu.co', 'uam.edu.co', 'uba.edu.co', 'ucc.edu.co',
    ];

    // Patrones que indican persona natural, no empresa
    private const NATURAL_PERSON_PATTERNS = [
        '/\b(cc|cedula|cédula|nit|pasaporte|tarjeta)\b/i',
    ];

    // Palabras que SÍ indican empresa
    private const COMPANY_KEYWORDS = [
        'sas', 's.a.s', 'ltda', 'limitada', 'inc', 'corp', 'llc',
        'company', 'group', 'holdings', 'solutions', 'services',
        'industrias', 'constructora', 'consultora', 'ingenieria',
        'tecnologia', 'logistica', 'logística', 'seguros',
    ];

    public function handle(): int
    {
        $this->info('═════════════════════════════════════════════════════');
        $this->info(' crm:backfill-domain — buscar dominios faltantes');
        $this->info('═════════════════════════════════════════════════════');
        $this->newLine();

        // Validar Python + ddgs disponibles
        $ddgCheck = $this->checkDdgsAvailable();
        if ($ddgCheck !== true) {
            $this->error("ddgs no disponible: $ddgCheck");
            $this->newLine();
            $this->info('Para instalar dentro del container:');
            $this->info('  apk add --no-cache python3 py3-pip');
            $this->info('  pip install --break-system-packages ddgs');
            return self::FAILURE;
        }

        // Construir query para entidades candidatas
        $query = $this->buildCandidateQuery();
        if ($entityId = $this->option('entity')) {
            $query->where('id', (int) $entityId);
        }
        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $candidates = $query->get();
        $totalCandidates = $candidates->count();

        $this->info(sprintf("Candidatas: %d entidades sin sitio web", $totalCandidates));
        $this->info(sprintf("Modo: %s", $this->option('commit') ? 'WRITE TO DB' : 'DRY-RUN (sin escribir)'));
        $this->info(sprintf("Delay: %ds entre queries", (int) $this->option('delay')));
        $this->info(sprintf("Validación estricta: %s", $this->option('strict') ? 'SI' : 'NO'));
        $this->newLine();

        if ($totalCandidates === 0) {
            $this->info('No hay entidades candidatas.');
            return self::SUCCESS;
        }

        $results = [
            'domain' => 0,
            'social' => 0,
            'no_match' => 0,
            'skipped_natural' => 0,
            'errors' => 0,
        ];

        $bar = $this->output->createProgressBar($totalCandidates);
        $bar->start();

        foreach ($candidates as $e) {
            $bar->advance();

            // Skip personas naturales
            if ($this->isLikelyNaturalPerson($e)) {
                $results['skipped_natural']++;
                continue;
            }

            $result = $this->searchEntityDomain($e, $this->option('show'));

            if ($result === null) {
                $results['no_match']++;
            } elseif ($result['is_social']) {
                $results['social']++;
            } else {
                $results['domain']++;
            }

            if (sleep((int) $this->option('delay')) !== 0) {
                // Sleep interrupted
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Resumen
        $this->info('═════════════════════════════════════════════════════');
        $this->info(' Resumen del backfill');
        $this->info('═════════════════════════════════════════════════════');
        $this->info(sprintf("  Total procesadas:        %d", $totalCandidates));
        $this->info(sprintf("  Dominio REAL match:      %d", $results['domain']));
        $this->info(sprintf("  Red social match:        %d", $results['social']));
        $this->info(sprintf("  Sin match (saltos):      %d", $results['no_match']));
        $this->info(sprintf("  Skip (persona natural):  %d", $results['skipped_natural']));
        $this->info(sprintf("  Errores:                 %d", $results['errors']));
        $this->newLine();

        if (! $this->option('commit')) {
            $this->warn('DRY-RUN: nada se escribió en BD. Re-correr con --commit para aplicar.');
        } else {
            $this->info('Datos escritos en BD. Verificá antes de mergear.');
        }

        return self::SUCCESS;
    }

    /**
     * Verifica que Python3 + ddgs están disponibles en el PATH.
     */
    private function checkDdgsAvailable(): bool|string
    {
        $output = [];
        $code = 0;
        exec('python3 -c "from ddgs import DDGS; print(\"ok\")" 2>&1', $output, $code);
        if ($code !== 0) {
            return implode("\n", $output);
        }
        return true;
    }

    /**
     * Query base: entidades sin dominio Y sin red_social_url,
     * Y no son personas naturales obvias.
     */
    private function buildCandidateQuery()
    {
        return DB::table('entidad')
            ->where(function ($q) {
                $q->whereNull('dominio')->orWhere('dominio', '');
            })
            ->where(function ($q) {
                $q->whereNull('red_social_url')->orWhere('red_social_url', '');
            })
            ->where(function ($q) {
                // Excluir personas naturales (CC)
                $q->where('tipo_id', '!=', 'CC')
                  ->orWhereNull('tipo_id');
            })
            ->select('id', 'nombre', 'identificacion', 'tipo_id')
            ->orderBy('id');
    }

    /**
     * Heurística para detectar personas naturales vs empresas.
     * Las CC (cédulas) las identificamos, pero también nombres sin keyword
     * de empresa (SAS, LTDA) que son muy cortos.
     */
    private function isLikelyNaturalPerson(object $entity): bool
    {
        // CC explícito
        if ($entity->tipo_id === 'CC') return true;

        // Sin NIT es probable persona natural
        if (empty($entity->identificacion) && $this->isShortNameLikelyPerson($entity->nombre)) {
            return true;
        }

        return false;
    }

    private function isShortNameLikelyPerson(string $nombre): bool
    {
        // 2-3 palabras en lowercase (sin SAS/LTDA) probablemente es nombre
        $hasCompanyWord = false;
        foreach (self::COMPANY_KEYWORDS as $kw) {
            if (stripos($nombre, $kw) !== false) {
                $hasCompanyWord = true;
                break;
            }
        }
        if ($hasCompanyWord) return false;

        $words = explode(' ', trim($nombre));
        return count($words) <= 3;
    }

    /**
     * Buscar dominio via ddgs.
     * Devuelve array con 'domain' y 'is_social', o null si no se encontró match.
     */
    private function searchEntityDomain(object $entity, bool $verbose = false): ?array
    {
        $query = sprintf('"%s" Colombia sitio oficial', $entity->nombre);
        $json = $this->runDdgSearch($query, 5);
        if ($json === null) return null;

        $results = json_decode($json, true);
        if (! is_array($results) || empty($results)) return null;

        if ($verbose) {
            $this->line("\n  [{$entity->id}] {$entity->nombre}");
        }

        // Validar mejor candidato
        $best = $this->pickBestResult($entity->nombre, $results, $verbose);
        if ($best === null) {
            if ($verbose) $this->warn("  → sin match confiable");
            return null;
        }

        if ($this->option('commit')) {
            $this->writeMatchToDb($entity, $best);
        }

        return $best;
    }

    private function runDdgSearch(string $query, int $max = 5): ?string
    {
        // Serializar query a JSON para evitar problemas de escape
        $payload = json_encode(['query' => $query, 'max' => $max]);
        $cmd = sprintf(
            "python3 -c %s 2>&1",
            escapeshellarg(
                'import json, sys; from ddgs import DDGS; ' .
                'q = json.loads(sys.stdin.read()); ' .
                'r = list(DDGS().text(q["query"], max_results=q["max"])); ' .
                'print(json.dumps([{"href": x.get("href", ""), "title": x.get("title", ""), "body": x.get("body", "")} for x in r]))'
            )
        );

        $output = [];
        $code = 0;
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(['python3', '-c', 'import json, sys; from ddgs import DDGS; q = json.loads(sys.stdin.read()); r = list(DDGS().text(q["query"], max_results=q["max"])); print(json.dumps([{"href": x.get("href", ""), "title": x.get("title", ""), "body": x.get("body", "")} for x in r]))'], $descriptor, $pipes);

        if (! is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $result = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);

        if ($returnCode !== 0 || $result === false || $result === '') {
            return null;
        }
        return $result;
    }

    /**
     * Selecciona el mejor resultado de DDG basándose en heurística.
     * Devuelve null si no hay match confiable.
     */
    private function pickBestResult(string $entityName, array $results, bool $verbose = false): ?array
    {
        $name = $this->normalizeEntityName($entityName);
        $attempts = [];

        foreach ($results as $r) {
            $url = $r['href'] ?? '';
            $title = $r['title'] ?? '';
            $host = $this->extractHost($url);
            if (! $host) {
                $attempts[] = ['url' => $url, 'reason' => 'no host'];
                continue;
            }

            // Descartar dominios "ruido" (job boards, directorios)
            $isNoise = false;
            foreach (self::NOISE_DOMAINS as $noise) {
                if ($host === $noise || str_ends_with($host, '.' . $noise)) {
                    $isNoise = true;
                    break;
                }
            }
            if ($isNoise) {
                $attempts[] = ['url' => $url, 'reason' => 'noise: ' . $host];
                continue;
            }

            // Descartar URLs de directorios (path/URL de empresa)
            if ($this->isDirectoryUrl($url)) {
                $attempts[] = ['url' => $url, 'reason' => 'directory'];
                continue;
            }

            // Si la URL es red social (FB, IG, LKD), guardar como social_url
            if ($this->isSocialNetworkUrl($url)) {
                $score = $this->matchScore($name, $title, $host);
                $attempts[] = ['url' => $url, 'type' => 'social', 'score' => $score];
                if ($score >= 0.5) {
                    if ($verbose) $this->info("  ✓ Social match: {$url} (score {$score})");
                    return [
                        'url' => $url,
                        'is_social' => true,
                        'score' => $score,
                    ];
                }
                continue;
            }

            // Score de match (con host para mejor heurística)
            $score = $this->matchScore($name, $title, $host);
            $attempts[] = ['url' => $url, 'type' => 'domain', 'host' => $host, 'score' => $score];

            if ($this->option('strict') && $score < 0.6) {
                continue;
            }
            // Requerir match del HOST (no solo del título) para dominios
            if ($score < 1.0) {
                if ($verbose) $this->line("    - {$url} → score bajo ({$score}), no es match del host");
                continue;
            }
            if ($verbose) $this->info("  ✓ Domain match: {$url} (host: {$host}, score {$score})");
            return [
                'url' => $url,
                'is_social' => false,
                'score' => $score,
            ];
        }

        // Si verbose, mostrar por qué no se encontró match
        if ($verbose) {
            foreach ($attempts as $a) {
                $reason = $a['reason'] ?? ($a['type'] ?? '?') . ' score=' . ($a['score'] ?? '?');
                $this->line("    - {$a['url']} → {$reason}");
            }
        }

        return null;
    }

    /**
     * Score de match. Para que un dominio sea confiable debe cumplir al menos
     * una de:
     *  - La URL/host contiene al menos 1 palabra clave del nombre
     *  - El título tiene TODAS las palabras del nombre
     *
     * El score final es 1.0 si el dominio contiene una palabra clave,
     * más bajo si solo el título matchea (caso directorio).
     */
    private function matchScore(string $name, string $title, ?string $host = null): float
    {
        $nameWords = array_filter(explode(' ', strtolower($name)), fn($w) => strlen($w) >= 3);
        $titleLower = strtolower($title);

        if (empty($nameWords)) return 0;

        // Score de match del título
        $titleMatch = 0;
        foreach ($nameWords as $w) {
            if (str_contains($titleLower, $w)) $titleMatch++;
        }
        $titleScore = $titleMatch / count($nameWords);

        // Score de match del host (más confiable — el dominio dice de quién es)
        $hostScore = 0;
        if ($host) {
            $hostLower = strtolower($host);
            foreach ($nameWords as $w) {
                if (str_contains($hostLower, $w)) {
                    $hostScore = 1.0;
                    break;
                }
            }
        }

        // Si el host matchea, ese es el score final
        if ($hostScore > 0) {
            return $hostScore;
        }

        // Si no matchea host, usar el title
        return $titleScore;
    }

    /**
     * Detecta si la URL es de un directorio/aggregator de empresas.
     * Los directorios generan paths como /company/NOMBRE o /empresa/NOMBRE,
     * no páginas oficiales.
     */
    private function isDirectoryUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        // Paths típicos de directorios
        $directoryPatterns = [
            '#/(company|companies|empresa|empresas|negocio|negocios|business|profile|listing)/#i',
            '#/empresa/[^/]+$#i',
            '#/companies/[^/]+$#i',
            '#/directorio-?empresas?/#i',
            '#/perfil-empresa/#i',
        ];

        foreach ($directoryPatterns as $p) {
            if (preg_match($p, $path)) {
                return true;
            }
        }

        // Hosts de directorios no incluidos en NOISE_DOMAINS
        $directoryIndicators = [
            'larepublica.co',
            'portafolio.co',
            'infopiniones.com',
            'findglocal.com',
            'edirectorio.net',
            'takealuk.com',
            'allabolivia.com',
            'co.todosnegocios.com',
            'telexplorer.com',
            'mipagina.com',
            'compuempresa.com',
        ];

        $host = strtolower($this->extractHost($url) ?? '');
        foreach ($directoryIndicators as $d) {
            if (str_ends_with($host, $d)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeEntityName(string $name): string
    {
        $name = strtolower(trim($name));
        // Quitar sufijos típicos
        $name = preg_replace('/\s+(s\.?a\.?s\.?|s\.?a\.?|ltda\.?|limitada|inc\.?|corp\.?|s\.?a\.?s\.?)\s*$/i', '', $name);
        return trim($name);
    }

    private function writeMatchToDb(object $entity, array $match): void
    {
        $data = $match['is_social']
            ? ['red_social_url' => $match['url']]
            : ['dominio' => $match['url']];

        DB::table('entidad')
            ->where('id', $entity->id)
            ->update($data);
    }
}
