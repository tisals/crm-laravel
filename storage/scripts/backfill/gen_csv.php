<?php
/**
 * Genera CSV para revision manual de backfill de dominios.
 *
 * Uso:
 *   php /tmp/gen_csv.php
 *   docker cp crm-laravel-dev:/tmp/revision_dominios_YYYYMMDD.csv ./
 *
 * Tiempo estimado: ~1.5s/entidad. 390 entidades = ~10 minutos.
 *
 * Output columns:
 *  - ID, Nombre, Identificacion, Tipo_ID, Estado, Categoria_Automatica
 *  - DDG_Suggestion_Host, DDG_Suggestion_URL, DDG_Score
 *  - Heuristica_Tipo, Notes, Decision_Sugerida
 *
 * Categorias:
 *  - CANDIDATE_OK:   match_score >= 1.0 (host contains name word)
 *  - NO_MATCH:       DDG no encontro match confiable
 *  - PERSONA_NATURAL: skip (CC o no tiene NIT, nombres cortos)
 */
$pdo = new PDO('mysql:host=mariadb;dbname=tecnoinnsoft_crm;charset=utf8mb4', 'root', 'sailus_root_dev', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$ddgsAvail = false;
exec('python3 -c "from ddgs import DDGS; print(\'ok\')" 2>/dev/null', $output, $code);
if ($code === 0) $ddgsAvail = true;
if (!$ddgsAvail) { echo "ERROR: instalar ddgs (apk add python3 + pip install ddgs)\n"; exit(1); }

$entities = $pdo->query("SELECT id, nombre, identificacion, tipo_id, estado, created_at FROM entidad WHERE (dominio IS NULL OR dominio = '') AND (red_social_url IS NULL OR red_social_url = '') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$total = count($entities);
echo "Total: $total entidades (~" . round($total * 1.5 / 60, 1) . " minutos)\n\n";

function isNaturalPerson(array $e): bool {
    if ($e['tipo_id'] === 'CC') return true;
    if (empty($e['identificacion']) && isShortNameLikelyPerson($e['nombre'])) return true;
    return false;
}
function isShortNameLikelyPerson(string $n): bool {
    $kw = ['sas','s.a.s','ltda','limitada','inc','corp','llc','company','group','holdings','solutions','services'];
    foreach ($kw as $k) if (stripos($n, $k) !== false) return false;
    return count(explode(' ', trim($n))) <= 3;
}
function isNoiseDomain(string $host): bool {
    $noise = ['indeed.com','jobtome.com','glassdoor.com','computrabajo.com','elempleo.com','jobrapido.com','magneto365.com','bumeran.com','occ.com.mx','mifuturoempleo.co','jobbank.com','opcionempleo.com','opcionempleo.com.co','talent.com','linkedin.com','co.linkedin.com','www.linkedin.com','linkedin.cn','tiktok.com','www.tiktok.com','prezi.com','slideshare.net','issuu.com','scribd.com','drive.google.com','docs.google.com','calameo.com','calameo.com.ar','webscolombia.co','sites.google.com','wordpress.com','blogspot.com','wixsite.com','weebly.com','jimdo.com','webnode.com','jumpseller.com','registronit.com','emis.com','direccion.com.co','dnb.com','panjiva.com','lusha.com','telefonicaydireccion.com','paginasamarillas.com.co','yellowpages.com.co','telexplorer.com.co','lasempresas.com.co','empresite.eleconomistaamerica.co','empresite.eleconomista.es','cybo.com','es.cybo.com','di1.com.ar','europages.es','connectamericas.com','informacolombia.com','directorio-empresas.einforma.co','einforma.co','esic.es','kompass.com','salesfuel.com','zolvo.com','zoominfo.com','apolo.work','larepublica.co','empresas.larepublica.co','portafolio.co','empresas.portafolio.co','infopiniones.com','findglocal.com','edirectorio.net','takealuk.com','allabolivia.com','co.todosnegocios.com','informacion-empresas.co','datosperu.org','facebook.com','instagram.com','datosperu.com','datosperu.info','empresarial.com','wikipedia.org','youtube.com','youtu.be','fao.org','scielo.org','scielo.org.co','bvsalud.org','doi.org','researchgate.net','academia.edu','mercadolibre.com','mercadolibre.com.co','amazon.com','amazon.com.co','falabella.com','falabella.com.co','exito.com','linio.com','alibaba.com','ebay.com','olx.com.co','es.tohotel.com','tohotel.com','tripadvisor.com','tripadvisor.co','tripadvisor.com.mx','booking.com','hotels-manizales.com','adonde.com','alonhadondevivir.com','airbnb.com','significadode.org','tuhotel.com','seair.co.in','zonaprop.com','fincaraiz.com.co','metrocuadrado.com','link.me','linktr.ee','buscasecop.com','mivitrina.parservicios.com','datacreditoempresas.com.co','consulado.gov.co','cancilleria.gov.co','embajada.gov.co','colombianosune.gov.co','minsalud.gov.co','mineducacion.gov.co','mindeporte.gov.co','procuraduria.gov.co','contraloria.gov.co','senado.gov.co','camara.gov.co','corteconstitucional.gov.co','mincomercio.gov.co','mincit.gov.co','mintic.gov.co','minhacienda.gov.co','car.gov.co','comisionderegulacion.gov.co','gov.co','udistrital.edu.co','unal.edu.co','uniandes.edu.co','eafit.edu.co','javeriana.edu.co','icesi.edu.co','upb.edu.co','usergioarboleda.edu.co','uam.edu.co','uba.edu.co','ucc.edu.co'];
    $h = strtolower($host);
    foreach ($noise as $n) if ($h === $n || str_ends_with($h, '.' . $n)) return true;
    return false;
}
function isDirectoryUrl(string $url): bool {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $patterns = ['#/(company|companies|empresa|empresas|negocio|negocios|business|profile|listing)/#i','#/empresa/[^/]+$#i','#/companies/[^/]+$#i','#/directorio-?empresas?/#i','#/perfil-empresa/#i'];
    foreach ($patterns as $p) if (preg_match($p, $path)) return true;
    return false;
}
function matchScore(string $name, string $title, ?string $host = null): float {
    $nw = array_filter(explode(' ', strtolower($name)), fn($w) => strlen($w) >= 3);
    if (!$nw) return 0;
    $tl = strtolower($title);
    $tm = 0; foreach ($nw as $w) if (str_contains($tl, $w)) $tm++;
    $hs = 0;
    if ($host) { $hl = strtolower($host); foreach ($nw as $w) if (str_contains($hl, $w)) { $hs = 1.0; break; } }
    return $hs > 0 ? $hs : ($tm / count($nw));
}
function searchEntity(string $nombre, int $max = 5): ?array {
    $script = 'import json, sys
from ddgs import DDGS
q = json.loads(sys.stdin.read())
r = list(DDGS().text(q["query"], max_results=q["max"]))
print(json.dumps([{"href": x.get("href", ""), "title": x.get("title", ""), "body": x.get("body", "")} for x in r]))';
    $process = proc_open(['python3', '-c', $script], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) return null;
    $payload = json_encode(['query' => '"' . $nombre . '" Colombia sitio oficial', 'max' => $max]);
    fwrite($pipes[0], $payload); fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
    proc_close($process);
    if (!$result) return null;
    return json_decode($result, true);
}

$date = date('Ymd');
$outFile = "/tmp/revision_dominios_{$date}.csv";
$fh = fopen($outFile, 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['ID','Nombre','Identificacion','Tipo_ID','Estado','Categoria_Automatica','DDG_Suggestion_Host','DDG_Suggestion_URL','DDG_Score','Heuristica_Tipo','Notes','Decision_Sugerida'], ';');

$processed = 0; $matched = 0; $skipped = 0; $no_match = 0;

foreach ($entities as $e) {
    $processed++;
    if (isNaturalPerson($e)) {
        $skipped++;
        fputcsv($fh, [$e['id'],$e['nombre'],$e['identificacion']?:'',$e['tipo_id']?:'',$e['estado']?:'','PERSONA_NATURAL','','','','Excluir de busqueda','',''], ';');
        continue;
    }
    $results = searchEntity($e['nombre']);
    if (!$results || empty($results)) {
        $no_match++;
        fputcsv($fh, [$e['id'],$e['nombre'],$e['identificacion']?:'',$e['tipo_id']?:'',$e['estado']?:'','NO_MATCH','','','','','Sin resultado en DDG - completar manualmente'], ';');
        continue;
    }
    $best = null; $heurTipo = '';
    foreach ($results as $r) {
        $url = $r['href'] ?? ''; $title = $r['title'] ?? '';
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $host = preg_replace('/^www\./', '', strtolower($host));
        if (!$host || isNoiseDomain($host) || isDirectoryUrl($url)) continue;
        $score = matchScore($e['nombre'], $title, $host);
        if ($score >= 1.0) {
            $best = ['host'=>$host,'url'=>$url,'title'=>$title,'score'=>$score];
            $heurTipo = 'host_match_' . (str_ends_with($host,'.com.co')?'com_co':(str_ends_with($host,'.co')?'co':'generic'));
            break;
        }
    }
    if ($best) {
        $matched++;
        fputcsv($fh, [$e['id'],$e['nombre'],$e['identificacion']?:'',$e['tipo_id']?:'',$e['estado']?:'','CANDIDATE_OK',$best['host'],$best['url'],$best['score'],$heurTipo,substr($best['title'],0,80),'VERIFICAR match=true; si correcto: aplicar a dominio'], ';');
    } else {
        $no_match++;
        foreach ($results as $r) {
            $url = $r['href']; $host = parse_url($url, PHP_URL_HOST) ?? '';
            $host = preg_replace('/^www\./','', strtolower($host));
            if ($host && !isNoiseDomain($host)) {
                fputcsv($fh, [$e['id'],$e['nombre'],$e['identificacion']?:'',$e['tipo_id']?:'',$e['estado']?:'','NO_MATCH',$host,$url,'0.5 o menos','candidato_descartado',substr($r['title'],0,80),'Buscar adicionales o completar manualmente'], ';');
                break;
            }
        }
    }
    if ($processed % 10 === 0) echo "  $processed/$total (matched=$matched, skipped=$skipped, no_match=$no_match)\n";
    usleep(1500000);
}
fclose($fh);
echo "\nRESUMEN: $processed procesadas | $matched match | $skipped personas | $no_match sin match\n";
echo "CSV: $outFile\n";
