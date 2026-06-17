<?php

namespace App\Application\UseCases\Oportunidad;

use App\Models\Contacto;
use App\Models\Producto;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Import opportunities from CSV rows into the database.
 *
 * Processes each row individually within a transaction chunk.
 * Entities and contacts are created on-the-fly when not found.
 */
class OportunidadCsvImportUseCase
{
    /** @var array<string, int> Pre-built entity lookup: [key => entidad_id] */
    private array $entityMap = [];

    /** @var array<string, int> Entities created in current batch: [key => id] */
    private array $newEntityMap = [];

    /** @var array<string, true> Contact dedup across chunks: ["{entidad_id}:{email}" => true] */
    private array $contactDedup = [];

    /** @var array<string, Producto> [lowercase_name => Producto] */
    private array $productMap = [];

    /** @var array<string, string> Maestro nombre → estado interno */
    private array $estadoMap = [];

    /** @var array{nits: array, names: array} Clientes from billing sheet */
    private array $clientesFacturacion = ['nits' => [], 'names' => []];

    private ?Producto $fallbackProduct = null;

    private int $defaultUserId = 1;

    private const ESTADO_MAP = [
        // Texto directo del CSV
        'generada' => 'Enviada',
        'aprobada' => 'Aceptada',
        'no aprobada' => 'Rechazada',
        'no aprobado' => 'Rechazada',
        // Nombres desde maestro (maestro.nombre → estado interno)
        'borrador' => 'Borrador',
        'enviado' => 'Enviada',
        'en negociación' => 'Aceptada',
        'ganado' => 'Ganada',
        'perdido' => 'Perdida',
    ];
    // --- Builder ---

    public function setEntityMap(array $map): static
    {
        $this->entityMap = $map;

        return $this;
    }

    public function setContactDedup(array $dedup): static
    {
        $this->contactDedup = $dedup;

        return $this;
    }

    public function setProductMap(array $products): static
    {
        $this->productMap = [];
        foreach ($products as $product) {
            $this->productMap[strtolower(trim($product->nombre))] = $product;
        }

        return $this;
    }

    public function setFallbackProduct(?Producto $product): static
    {
        $this->fallbackProduct = $product;

        return $this;
    }

    public function setDefaultUserId(int $id): static
    {
        $this->defaultUserId = $id;

        return $this;
    }

    public function setEstadoMap(array $map): static
    {
        $this->estadoMap = $map;

        return $this;
    }

    public function setClientesFacturacion(array $list): static
    {
        $this->clientesFacturacion = $list;

        return $this;
    }

    // --- Main pipeline ---

    /**
     * Import a chunk of CSV rows inside a single transaction.
     * Each row: resolve entity → resolve contact → upsert oportunidad → upsert detalle.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, skipped: int, errors: int, details: array}
     */
    public function import(array $rows): array
    {
        $counters = ['created' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

        $pipelineId = DB::table('pipelines')->where('codigo', 'COTIZACION')->value('id');
        if (! $pipelineId) {
            $pipelineId = DB::table('pipelines')->insertGetId([
                'nombre' => 'Cotización',
                'codigo' => 'COTIZACION',
                'habilitado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $stages = ['Borrador', 'Enviada', 'Aceptada', 'Rechazada', 'Ganada', 'Perdida'];
        $stageMap = [];
        foreach ($stages as $index => $stageName) {
            $stageId = DB::table('pipeline_etapas')
                ->where('pipeline_id', $pipelineId)
                ->where('nombre', $stageName)
                ->value('id');
            if (! $stageId) {
                $stageId = DB::table('pipeline_etapas')->insertGetId([
                    'pipeline_id' => $pipelineId,
                    'nombre' => $stageName,
                    'orden' => $index,
                    'habilitado' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $stageMap[$stageName] = $stageId;
        }

        DB::transaction(function () use ($rows, &$counters, $pipelineId, $stageMap) {
            $now = now();
            $this->newEntityMap = []; // reset per chunk

            foreach ($rows as $i => $row) {
                try {
                    $codigo = $row['codigo'] ?? null;
                    if (! $codigo) {
                        $counters['skipped']++;

                        continue;
                    }

                    // Use CSV fecha for timestamps so Kanban and entities sort by real date
                    $fechaStr = $this->parseFecha($row['fecha'] ?? null);
                    $timestamp = $fechaStr ? Carbon::parse($fechaStr) : $now;

                    // --- 1. Entity ---
                    $entidadId = $this->resolveEntityId($row, $timestamp);

                    // --- 2. Contact ---
                    $contactId = $this->resolveContactId($row, $entidadId, $timestamp);

                    // --- 3. Oportunidad (upsert by codigo) ---
                    $oppData = $this->buildOpportunityData($row, $entidadId, $contactId);

                    $resolvedStage = $oppData['estado'];
                    $oppData['pipeline_id'] = $pipelineId;
                    $oppData['pipeline_etapa_id'] = $stageMap[$resolvedStage] ?? $stageMap['Borrador'];
                    $oppData['estado'] = 'Activa';
                    $oppData['is_latest'] = true;

                    $oppData['created_at'] = $timestamp;
                    $oppData['updated_at'] = $timestamp;

                    $existing = DB::table('oportunidad')->where('codigo', $codigo)->first();
                    if ($existing) {
                        DB::table('oportunidad')->where('id', $existing->id)->update($oppData);
                        $oppId = $existing->id;
                    } else {
                        $oppId = DB::table('oportunidad')->insertGetId($oppData);
                    }

                    // --- 4. Detalle (replace on upsert) ---
                    $detalleData = $this->buildDetalleData($row);
                    if ($detalleData !== null) {
                        $detalleData['oportunidad_id'] = $oppId;
                        $detalleData['created_at'] = $timestamp;
                        $detalleData['updated_at'] = $timestamp;

                        DB::table('detalle_oportunidad')->where('oportunidad_id', $oppId)->delete();
                        DB::table('detalle_oportunidad')->insert($detalleData);
                    }

                    $counters['created']++;

                } catch (\Throwable $e) {
                    $counters['errors']++;
                    $counters['details'][] = [
                        'row' => $i,
                        'codigo' => $row['codigo'] ?? 'N/A',
                        'error' => $e->getMessage(),
                    ];
                }
            }
        });

        return $counters;
    }

    // --- Entity resolution ---

    private function resolveEntityId(array $row, $now): int
    {
        $empresa = $this->cleanStr($row['empresa'] ?? '');
        $dominio = $this->cleanStr($row['dominio'] ?? '');
        $lineaNegocio = $this->cleanStr($row['linea_negocio'] ?? '');

        if (! $empresa) {
            throw new \RuntimeException('CAMPO EMPRESA vacío');
        }

        $parsed = $this->parseEmpresaField($empresa);
        $empresaName = $parsed['name'];
        $nit = $parsed['nit'];

        $id = null;

        // Priority 1: Domain
        if ($dominio) {
            $domainKey = strtolower(explode('.', $dominio)[0]);
            $id = $this->lookupEntityId($domainKey);
        }

        // Priority 2: NIT
        if (! $id && $nit) {
            $cleanNit = preg_replace('/[\.\-\s]/', '', $nit);
            $id = $this->lookupEntityId($cleanNit) ?? $this->lookupEntityId($nit);
        }

        // Priority 3: Normalized name
        if (! $id) {
            $normalized = $this->normalizeEntityName($empresaName);
            $id = $this->lookupEntityId($normalized);
        }

        // Priority 4: Raw lower name
        if (! $id) {
            $nameLower = strtolower($empresaName);
            $id = $this->lookupEntityId($nameLower);
        }

        // Priority 5: Keyword fuzzy match (catches typos like "Activo" vs "Activos SAS")
        if (! $id) {
            $csvWords = $this->extractKeyWords($empresaName);
            if (! empty($csvWords)) {
                $id = $this->lookupEntityIdByKeywords($csvWords);
            }
        }

        // Check if it is client based on billing sheet
        $isClient = false;
        if ($nit) {
            $cleanNit = preg_replace('/[\.\-\s]/', '', $nit);
            if (in_array($cleanNit, $this->clientesFacturacion['nits'] ?? [])) {
                $isClient = true;
            }
        }
        if (! $isClient) {
            $normalizedName = $this->normalizeEntityName($empresaName);
            if (in_array($normalizedName, $this->clientesFacturacion['names'] ?? [])) {
                $isClient = true;
            }
        }

        $timestampStr = $now instanceof Carbon ? $now->format('Y-m-d H:i:s') : (string) $now;

        if ($id) {
            // Update existing entity's state and client_desde based on billing JSON crossing
            $updateData = [
                'estado' => $isClient ? 'Cliente' : 'Prospecto',
            ];
            if ($isClient) {
                $updateData['cliente_desde'] = DB::raw("IFNULL(LEAST(IFNULL(cliente_desde, '{$timestampStr}'), '{$timestampStr}'), '{$timestampStr}')");
            } else {
                $updateData['cliente_desde'] = null;
            }

            DB::table('entidad')
                ->where('id', $id)
                ->update($updateData);

            // Ensure created_at / updated_at are the oldest possible dates
            DB::table('entidad')
                ->where('id', $id)
                ->where(function ($q) use ($timestampStr) {
                    $q->whereNull('created_at')
                        ->orWhere('created_at', '>', $timestampStr);
                })
                ->update([
                    'created_at' => $timestampStr,
                    'updated_at' => $timestampStr,
                ]);

            return $id;
        }

        // Not found → CREATE
        $newId = DB::table('entidad')->insertGetId([
            'nombre' => mb_substr($empresaName, 0, 255),
            'nombre_comercial' => mb_substr($empresaName, 0, 255),
            'linea_negocio' => $lineaNegocio ?: null,
            'tipo_persona' => 'Juridica',
            'tipo_id' => 'NIT',
            'identificacion' => $nit ? preg_replace('/[\.\-\s]/', '', $nit) : null,
            'dominio' => $dominio ?: null,
            'estado' => $isClient ? 'Cliente' : 'Prospecto',
            'cliente_desde' => $isClient ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Index for dedup within same chunk
        $normalized = $this->normalizeEntityName($empresaName);
        $nameLower = strtolower($empresaName);
        $this->newEntityMap[$normalized] = $newId;
        $this->newEntityMap[$nameLower] = $newId;

        return $newId;
    }

    private function lookupEntityId(string $key): ?int
    {
        return $this->entityMap[$key]
            ?? $this->newEntityMap[$key]
            ?? null;
    }

    /**
     * Fuzzy keyword matching for entity names with typos/variations.
     * "Activo" → matches "Activos SAS" → returns its ID.
     */
    private function lookupEntityIdByKeywords(array $csvWords): ?int
    {
        // Build [entity_id => [keyword, ...]] from the combined map
        $byId = [];
        foreach ($this->entityMap as $key => $id) {
            $byId[$id][] = $key;
        }
        foreach ($this->newEntityMap as $key => $id) {
            $byId[$id][] = $key;
        }

        $bestMatch = null;
        $bestScore = 0;

        foreach ($byId as $id => $keys) {
            $score = 0;
            foreach ($csvWords as $csvWord) {
                foreach ($keys as $key) {
                    if (str_contains($key, $csvWord) || str_contains($csvWord, $key)) {
                        $score++;
                        break;
                    }
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $id;
            }
        }

        return $bestScore >= 1 ? $bestMatch : null;
    }

    /**
     * Extract meaningful keywords from a company name.
     * Copied from ContactoCsvSeeder pattern.
     */
    private function extractKeyWords(string $name): array
    {
        $name = strtolower($name);
        $name = preg_replace('/\b(sas|s\.a\.s|s\.a\.|ltda|ltd|sa|en c|sociedad|anonima)\b/i', '', $name);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $name);
        $stopWords = ['y', 'de', 'del', 'la', 'los', 'las', 'el', 'en', 'para', 'con', 'sin', 'por'];
        $words = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (strlen($part) >= 3 && ! in_array($part, $stopWords)) {
                $words[$part] = $part;
            }
        }

        return array_values($words);
    }

    // --- Contact resolution ---

    private function resolveContactId(array $row, int $entidadId, $now): ?int
    {
        $email = $this->cleanStr($row['email_contacto'] ?? '');
        if (! $email) {
            return null;
        }
        // Some rows have multiple emails separated by \n — take only the first
        $email = explode("\n", $email)[0];
        $email = trim($email);
        if (empty($email)) {
            return null;
        }

        $dedupKey = $entidadId.':'.$email;

        // Already handled in this chunk or previous chunk?
        if (isset($this->contactDedup[$dedupKey])) {
            return null;
        }

        // Check DB
        $existing = Contacto::where('entidad_id', $entidadId)
            ->where('email_contacto', $email)
            ->first();

        if ($existing) {
            $this->contactDedup[$dedupKey] = true;

            return $existing->id;
        }

        // Parse name (first line before \n)
        $contactoRaw = $this->cleanStr($row['contacto'] ?? '');
        $nombre = $contactoRaw ? trim(explode("\n", $contactoRaw)[0]) : '';

        $newId = DB::table('contacto')->insertGetId([
            'entidad_id' => $entidadId,
            'email_contacto' => $email,
            'nombres' => mb_substr($nombre, 0, 255) ?: 'Sin nombre',
            'apellidos' => ' ',    // MariaDB: NOT NULL, no default
            'cargo' => $this->cleanStr($row['cargo'] ?? null),
            'tel_contacto' => $this->cleanStr($row['tel_contacto'] ?? null),
            'movil' => $this->cleanStr($row['movil'] ?? null),
            'estado' => 'Activo',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->contactDedup[$dedupKey] = true;

        return $newId;
    }

    /**
     * Resolve estado from raw CSV value:
     * 1. Numeric → lookup maestro nombre by ID → map to internal
     * 2. Text   → lookup in hardcoded ESTADO_MAP
     * 3. Fallback → Borrador
     */
    private function resolveEstado(?string $raw): string
    {
        if (! $raw) {
            return 'Borrador';
        }

        $trimmed = trim($raw);

        // Numeric: maestro ID → nombre → internal
        if (is_numeric($trimmed)) {
            $maestroNombre = $this->estadoMap[(int) $trimmed] ?? null;
            if ($maestroNombre) {
                return self::ESTADO_MAP[strtolower($maestroNombre)] ?? 'Borrador';
            }
        }

        // Text: direct lookup
        return self::ESTADO_MAP[strtolower($trimmed)] ?? 'Borrador';
    }

    // --- Builders ---

    private function buildOpportunityData(array $row, int $entidadId, ?int $contactId): array
    {
        $estadoRaw = $this->cleanStr($row['estado'] ?? '');
        $estado = $this->resolveEstado($estadoRaw);

        return [
            'codigo' => $row['codigo'],
            'entidad_id' => $entidadId,
            'contacto_id' => $contactId,
            'fecha' => $this->parseFecha($row['fecha'] ?? null) ?? date('Y-m-d'),
            'fuente_canal' => $this->cleanStr($row['fuente_canal'] ?? $row['fuentecanal'] ?? null),
            'estado' => $estado,
            'observaciones' => $this->cleanStr($row['observaciones'] ?? null),
            'aclaraciones' => $this->cleanStr($row['aclaraciones'] ?? null),
            'validez_oferta' => $this->parseValidezOferta($row['validez_oferta'] ?? null),
            'tiempo_entrega' => $this->cleanStr($row['tiempo_de_entrega'] ?? null),
            'forma_pago' => $this->cleanStr($row['forma_de_pago'] ?? null),
            'garantia' => $row['garantia'] ?? $row['garanta'] ?? null,
            'linea_negocio' => $this->cleanStr($row['linea_negocio'] ?? null),
            'created_by' => $this->defaultUserId,
        ];
    }

    private function buildDetalleData(array $row): ?array
    {
        $valorStr = $this->cleanStr($row['valor_sin_iva'] ?? '');
        if (! $valorStr) {
            return null;
        }

        $vrUnitario = $this->parseMonetaryValue($valorStr);
        if ($vrUnitario <= 0) {
            return null;
        }

        $producto = $this->matchProduct($row);
        $productoId = $producto?->id ?? $this->fallbackProduct?->id ?? 1;
        $ivaPorcentaje = $producto ? (float) $producto->iva : 0.0;
        $ivaAmount = $vrUnitario * ($ivaPorcentaje / 100);

        $tipoServicio = $this->cleanStr($row['tipo_de_servicio'] ?? '');
        $cantidadStr = $this->cleanStr($row['cantidad'] ?? '');

        return [
            'producto_id' => $productoId,
            'concepto' => $producto?->nombre ?? $tipoServicio ?? 'Servicio',
            'descripcion' => $producto?->descripcion ?? $tipoServicio ?? '',
            'medida' => $producto?->medida ?? 'Und',
            'cantidad' => $cantidadStr ? max(1, (int) $cantidadStr) : 1,
            'vr_unitario' => $vrUnitario,
            'iva' => $ivaAmount,
            'vr_total' => $vrUnitario + $ivaAmount,
            'created_by' => $this->defaultUserId,
        ];
    }

    // --- Product matching ---

    /**
     * Match product using the `productos` column (full description) from CSV,
     * with accent-insensitive comparison and bidirectional substring matching.
     */
    private function matchProduct(array $row): ?Producto
    {
        // Use 'productos' column (index 3, full product description) not 'tipo_de_servicio'
        $productosCol = $this->cleanStr($row['productos'] ?? $row['tipo_de_servicio'] ?? '');
        if (! $productosCol) {
            return $this->fallbackProduct;
        }

        $normSearch = $this->normalizeForMatch($productosCol);

        // Exact match on normalized key
        if (isset($this->productMap[$normSearch])) {
            return $this->productMap[$normSearch];
        }

        // Bidirectional substring match with accent normalization
        foreach ($this->productMap as $name => $product) {
            $normName = $this->normalizeForMatch($name);
            if (str_contains($normName, $normSearch) || str_contains($normSearch, $normName)) {
                return $product;
            }
        }

        return $this->fallbackProduct;
    }

    /**
     * Normalize string for product matching:
     * - strip accents (á → a, ó → o, etc.)
     * - lowercase
     * - collapse whitespace
     * - remove punctuation
     */
    private function normalizeForMatch(string $value): string
    {
        $v = strtolower(trim($value));
        // Remove accents using a character map
        $accents = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ã' => 'a', 'õ' => 'o', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ];
        $v = strtr($v, $accents);
        // Collapse spaces / remove punctuation
        $v = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v);

        return trim($v);
    }

    // --- Parsing helpers ---

    private function parseMonetaryValue(?string $value): float
    {
        if (! $value) {
            return 0.0;
        }
        $value = trim($value);

        // Colombian format: "." = thousands separator, "," = decimal
        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace('.', '', $value);
        }

        return (float) $value;
    }

    private function parseValidezOferta(?string $value): ?int
    {
        if (! $value) {
            return null;
        }
        preg_match('/\d+/', $value, $m);
        if (! $m) {
            return null;
        }
        $num = (int) $m[0];
        $lower = strtolower($value);

        if (str_contains($lower, 'año') || str_contains($lower, 'anual')) {
            return $num * 365;
        }
        if (str_contains($lower, 'mes')) {
            return $num * 30;
        }

        return $num;
    }

    private function parseFecha(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        if (is_numeric($value)) {
            // Excel serial date (Windows 1900 format)
            $unixDate = ($value - 25569) * 86400;

            return gmdate('Y-m-d', (int) $unixDate);
        }
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2}):(\d{2})/', $value, $m)) {
            $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $h = str_pad($m[4], 2, '0', STR_PAD_LEFT);

            return "{$m[3]}-{$mo}-{$d} {$h}:{$m[5]}:{$m[6]}";
        }
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $value, $m)) {
            $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);

            return "{$m[3]}-{$mo}-{$d}";
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
            $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $d = str_pad($m[3], 2, '0', STR_PAD_LEFT);

            return "{$m[1]}-{$mo}-{$d}";
        }

        return null;
    }

    private function parseEmpresaField(string $value): array
    {
        $name = $value;
        $nit = null;

        if (preg_match('/NIT:\s*([\d\.\-]+)/i', $value, $m)) {
            $nit = trim($m[1]);
            $name = trim(preg_replace('/\s*NIT:\s*[\d\.\-]+/i', '', $value));
            $name = trim(str_replace("\n", ' ', $name));
        }

        return ['name' => $name ?: $value, 'nit' => $nit];
    }

    /**
     * Aggressive entity name normalization: removes ALL variations of legal suffixes
     * (SAS, S.A.S., S. A. S., s a s, LTDA, L T D A, SA, E.U., S. en C., Inc, Corp, etc.),
     * punctuation, stop words — returns only the identifying core of the name.
     */
    private function normalizeEntityName(string $name): string
    {
        $name = strtolower(trim($name));

        // Normalize spaces around punctuation
        $name = preg_replace('/\s*\.\s*/', '.', $name);
        $name = preg_replace('/\s*-\s*/', '-', $name);

        // Remove all known legal suffixes (order: specific → generic)
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

        // Remove standalone suffix words
        $removeWords = [
            'sas', 'ltda', 'ltd', 'sa', 's.a', 's.a.s', 'e.u', 'eu',
            'inc', 'corp', 'foundation', 'fundacion', 'corporacion',
            'sociedad', 'anonima', 'cooperativa', 'asociacion',
        ];
        $words = explode(' ', $name);
        $words = array_filter($words, fn ($w) => ! in_array(trim($w), $removeWords));
        $name = implode(' ', $words);

        // Remove special chars and collapse spaces
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        // Remove Spanish stop words
        $stopWords = ['y', 'de', 'del', 'la', 'los', 'las', 'el', 'en', 'para', 'con', 'sin', 'por', 'e', 'o', 'a', 'su', 'un', 'una'];
        $words = explode(' ', trim($name));
        $words = array_filter($words, fn ($w) => ! in_array(trim($w), $stopWords));
        $name = implode(' ', $words);

        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function cleanStr(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
