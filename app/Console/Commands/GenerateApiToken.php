<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Modules\Shared\Models\Usuario;

class GenerateApiToken extends Command
{
    protected $signature = 'crm:generate-token {--email=crm@tecnoinnsoft.dev}';

    protected $description = 'Generate Sanctum API token for FastAPI integration';

    public function handle(): int
    {
        $email = $this->option('email');

        $user = Usuario::firstOrCreate(
            ['email' => $email],
            [
                'nombre' => 'CRM Service',
                'password_hash' => Hash::make(bin2hex(random_bytes(16))),
                'rol_id' => 1,
                'estado' => 'Activo',
            ]
        );

        $token = $user->createToken('fastapi-access')->plainTextToken;

        $this->info("Token generated for: {$email}");
        $this->line('');
        $this->line("<fg=green>{$token}</>");
        $this->line('');
        $this->comment('Add this to FastAPI .env as CRM_API_TOKEN');

        return self::SUCCESS;
    }
}
