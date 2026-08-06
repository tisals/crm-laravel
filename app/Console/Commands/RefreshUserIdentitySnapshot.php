<?php

namespace App\Console\Commands;

use App\Application\UseCases\Me\RefreshUserIdentitySnapshotUseCase;
use Illuminate\Console\Command;

class RefreshUserIdentitySnapshot extends Command
{
    protected $signature = 'crm:refresh-user-identity-snapshot
        {--user= : Refresh a single user (defaults to all users)}';

    protected $description = 'Refresh the user_identity_snapshot CQRS-Lite read model';

    public function handle(RefreshUserIdentitySnapshotUseCase $useCase): int
    {
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $this->info($userId
            ? "Refreshing identity snapshot for user {$userId}..."
            : 'Refreshing identity snapshot for all users...');

        $result = $useCase->execute($userId);

        $this->info("✓ Refreshed {$result['refreshed']} user(s) in {$result['duration_ms']}ms");

        if ($result['refreshed'] > 0 && $result['refreshed'] <= 20) {
            $this->line('User IDs: '.implode(', ', $result['user_ids']));
        }

        return self::SUCCESS;
    }
}
