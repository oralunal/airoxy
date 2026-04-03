<?php

namespace App\Http\Middleware;

use App\Models\AccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenValue = $request->header('x-api-key');

        if (! $tokenValue) {
            return $this->unauthorized();
        }

        $token = AccessToken::where('token', $tokenValue)
            ->where('is_active', true)
            ->first();

        if (! $token || $token->isExpired()) {
            return $this->unauthorized();
        }

        $token->updateQuietly(['last_used_at' => now()]);

        $request->attributes->set('access_token', $token);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'type' => 'error',
            'error' => [
                'type' => 'authentication_error',
                'message' => 'Invalid API key',
            ],
        ], 401);
    }
}
