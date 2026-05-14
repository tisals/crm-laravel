<?php

namespace App\Application\UseCases\Auth;

use Illuminate\Http\Request;

class LogoutUseCase
{
    public function execute(Request $request): void
    {
        $request->user()?->currentAccessToken()?->delete();
    }
}
