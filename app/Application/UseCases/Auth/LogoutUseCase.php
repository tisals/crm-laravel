<?php

namespace App\Application\UseCases\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LogoutUseCase
{
    public function execute(Request $request): void
    {
        $user = $request->user();
        $user?->currentAccessToken()?->delete();

        // Invalidate the user lookup cache for this email so a fresh login
        // doesn't read a stale user record (e.g. role changed mid-session).
        if ($user) {
            $cacheKey = LoginUseCase::USER_LOOKUP_PREFIX.hash('sha256', strtolower($user->email));
            Cache::forget($cacheKey);
        }
    }
}
