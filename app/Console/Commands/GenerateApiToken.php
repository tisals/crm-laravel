<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class GenerateApiToken extends Command
{
    protected $signature = 'crm:generate-token {--email=crm@tecnoinnsoft.dev}';
    protected $description = 'Generate Sanctum API token for FastAPI integration';

    public function handle(): int
    {
        $email = $this->option('email');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'CRM Service',
                'password' => Hash::make(bin2hex(random_bytes(16))),
            ]
        );

        $token = $user->createToken('fastapi-access')->plainTextToken;

        $this->info("Token generated for: {$email}");
        $this->line("");
        $this->line("<fg=green>{$token}</>");
        $this->line("");
        $this->comment("Add this to FastAPI .env as CRM_API_TOKEN");

        return self::SUCCESS;
    }
}
