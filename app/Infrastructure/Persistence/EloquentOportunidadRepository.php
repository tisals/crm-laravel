<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Entities\Oportunidad as OportunidadEntity;
use App\Domain\Repositories\OportunidadRepositoryInterface;
use App\Models\Oportunidad as EloquentOportunidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EloquentOportunidadRepository extends BaseRepository implements OportunidadRepositoryInterface
{
    protected function getModelClass(): string
    {
        return EloquentOportunidad::class;
    }

    protected function mapModelToEntity(Model $model): mixed
    {
        return OportunidadEntity::fromArray($model->toArray());
    }

    public function paginate(int $perPage = 15, ?string $search = null, array $filters = [], ?string $sortBy = null, ?string $sortOrder = 'desc'): LengthAwarePaginator
    {
        $query = $this->newQuery();

        // Default: filter to latest active versions.
        // If the caller passes is_latest=false, return ALL versions (for history views).
        $onlyLatest = ! array_key_exists('is_latest', $filters) || $filters['is_latest'] !== false;
        if ($onlyLatest) {
            $query->where('is_latest', true)->where('estado', 'Activa');
        }

        if ($search) {
            $query = $this->applySearch($query, $search);
        }

        if (! empty($filters)) {
            $query = $this->applyFilters($query, $filters);
        }

        $sortBy = $sortBy ?? 'created_at';
        $sortOrder = in_array($sortOrder, ['asc', 'desc']) ? $sortOrder : 'desc';

        return match ($sortBy) {
            'entidad' => $query->orderByRaw(
                "(SELECT nombre FROM entidad WHERE entidad.id = oportunidad.entidad_id) {$sortOrder}"
            )->paginate($perPage),
            'valor' => $query->orderByRaw(
                "(SELECT COALESCE(SUM(vr_total), 0) FROM detalle_oportunidad WHERE detalle_oportunidad.oportunidad_id = oportunidad.id) {$sortOrder}"
            )->paginate($perPage),
            default => $query->orderBy($sortBy, $sortOrder)->paginate($perPage),
        };
    }

    protected function applySearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('codigo', 'like', "%{$search}%")
                ->orWhereHas('entidad', function ($sq) use ($search) {
                    $sq->where('nombre', 'like', "%{$search}%");
                });
        });
    }

    /**
     * Compute the next sequential opportunity code.
     *
     * Format: `GC-{SS}-{YYYY}-{NNN}` where SS is 2-digit semester zero-padded,
     * YYYY is the calendar year, and NNN is the 3-digit consecutive counter
     * that ACCUMULATES across both semesters within the same year.
     *
     * Spec scenarios pinned:
     *   - "Counter persists across the semester-1 to semester-2 boundary"
     *   - "Counter resets on calendar year boundary"
     *   - "Soft-deleted codes are counted (no slot reuse)"
     *   - "Atomic counter increment under concurrent inserts" (requires the
     *     caller to wrap this call inside a DB::transaction; lockForUpdate()
     *     below only serializes when a transaction is open).
     */
    public function getNextCodigo(int $year, int $semester): string
    {
        if ($year < 1970 || $year > 9999) {
            throw new \InvalidArgumentException("Year must be 1970..9999, got {$year}");
        }
        if ($semester !== 1 && $semester !== 2) {
            throw new \InvalidArgumentException("Semester must be 1 or 2, got {$semester}");
        }

        // Year-only scope so codes from both semesters feed the same MAX.
        // Trailing `%` is REQUIRED because codigos end in `-NNN`, not `-`.
        $yearPrefix = "GC-%-{$year}-%";

        // Substring the last 3 chars and cast to unsigned integer so the
        // MAX is a numeric comparison, not a lexicographic one (which would
        // rank "999" below "1000" if the format ever expanded).
        $query = DB::table('oportunidad')
            ->where('codigo', 'like', $yearPrefix)
            ->selectRaw('MAX(CAST(SUBSTRING(codigo, -3) AS UNSIGNED)) as max_n');

        // lockForUpdate() requires a transaction — caller responsibility.
        // (StoreOportunidadUseCase::execute() wraps this call in DB::transaction.)
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $maxN = $query->value('max_n');

        $next = (int) ($maxN ?? 0) + 1;

        // Explicit overflow guard — fail loud, NOT a silent sprintf wrap to 1000.
        // Migration path documented in design.md (Decision 1): switch to a counters
        // table or expand the format to 4+ digits.
        if ($next > 999) {
            throw new \OverflowException(
                "Opportunity code counter exceeded 999 for year {$year}. ".
                'Migrate to a counters table or expand the format.'
            );
        }

        // Return the canonical code: 2-digit semester (zero-padded) + year + counter.
        return sprintf('GC-%02d-%d-%03d', $semester, $year, $next);
    }

    protected function applyFilters($query, array $filters): Builder
    {
        // Auto-filter by entidad_usuario for Comercial role
        $user = Auth::user();
        if ($user && $user->rol?->nombre === 'Comercial') {
            $query->whereIn('entidad_id', function ($q) use ($user) {
                $q->select('entidad_id')
                    ->from('entidad_usuario')
                    ->where('usuario_id', $user->id);
            });
        }

        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // is_latest is handled separately at the top of paginate() via the
            // $onlyLatest flag. Skip it here so the default case doesn't add
            // a contradictory WHERE is_latest = false when the caller wants all versions.
            if ($field === 'is_latest') {
                continue;
            }

            match ($field) {
                'fecha_desde' => $query->whereDate('fecha', '>=', $value),
                'fecha_hasta' => $query->whereDate('fecha', '<=', $value),
                'codigo' => $query->where('codigo', 'like', "%{$value}%"),
                'producto_id' => $query->whereHas('detalles', fn ($q) => $q->where('producto_id', $value)),
                'estado' => $query->whereHas('pipelineEtapa', fn ($q) => $q->where('nombre', $value)),
                default => $query->where($field, $value),
            };
        }

        return $query;
    }
}
