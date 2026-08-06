<?php

namespace App\Application\UseCases\Me;

use App\Models\UserIdentitySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes the `user_identity_snapshot` table for every user (or a single
 * user when `$userId` is supplied).
 *
 * For each user we build the identity bundle (user + apps + permisos)
 * and upsert it into the snapshot table with `is_stale=0` so subsequent
 * `/me/identity` reads serve from the snapshot.
 *
 * Called by:
 *   - `crm:refresh-user-identity-snapshot` artisan command (--user=ID
 *     for on-demand refresh of one user).
 *   - Nightly scheduler at 03:30 America/Bogota (see routes/console.php).
 *
 * Design contract: this use case MUST be idempotent — it can be called
 * any number of times for the same user without producing duplicates or
 * errors. The snapshot table's PK on user_id gives us natural idempotency.
 */
class RefreshUserIdentitySnapshotUseCase
{
    public function __construct(
        private GetMyIdentityUseCase $getMyIdentityUseCase,
    ) {}

    /**
     * @return array{refreshed: int, user_ids: array<int>, duration_ms: int}
     */
    public function execute(?int $userId = null): array
    {
        $start = microtime(true);
        $now = Carbon::now();
        $refreshed = 0;
        $userIds = [];

        $query = DB::table('usuarios')->whereNull('deleted_at');
        if ($userId !== null) {
            $query->where('id', $userId);
        }

        $ids = $query->pluck('id')->all();

        foreach ($ids as $uid) {
            $payload = $this->getMyIdentityUseCase->execute((int) $uid);
            if ($payload === null) {
                continue;
            }

            DB::table('user_identity_snapshot')->updateOrInsert(
                ['user_id' => $uid],
                [
                    'payload' => json_encode($payload),
                    'scope_label' => $payload['scope_label'] ?? 'v1',
                    'computed_at' => $now,
                    'is_stale' => false,
                ]
            );
            $refreshed++;
            $userIds[] = (int) $uid;
        }

        $duration = (int) ((microtime(true) - $start) * 1000);

        Log::info('identity.snapshot.refreshed', [
            'refreshed' => $refreshed,
            'duration_ms' => $duration,
        ]);

        return [
            'refreshed' => $refreshed,
            'user_ids' => $userIds,
            'duration_ms' => $duration,
        ];
    }

    /**
     * Invalidate the snapshot for one or more users. After this returns,
     * the next `GetMyIdentityUseCase::execute()` call for the affected
     * users will recompute from the live tables.
     *
     * Accepts either a single user ID or an array of IDs.
     */
    public function invalidate(int|array $userIds): int
    {
        $ids = is_int($userIds) ? [$userIds] : array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            return 0;
        }

        $affected = UserIdentitySnapshot::query()
            ->whereIn('user_id', $ids)
            ->update(['is_stale' => true]);

        // If a user has no snapshot row yet (first-ever mutation, e.g.
        // assigning an app to a fresh entity), we still want the next
        // /me/identity read to recompute. The use case handles missing
        // rows, so this is fine — no insert needed here.

        return $affected;
    }
}
