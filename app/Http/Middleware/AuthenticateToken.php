<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $keyValue = $request->header('x-api-key');

        if (! $keyValue) {
            return $this->unauthorized();
        }

        $apiKey = ApiKey::where('key', $keyValue)
            ->where('is_active', true)
            ->first();

        if (! $apiKey) {
            return $this->unauthorized();
        }

        $request->attributes->set('api_key', $apiKey);

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
