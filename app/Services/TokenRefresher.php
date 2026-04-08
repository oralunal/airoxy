<?php

namespace App\Services;

use App\Models\AccessToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TokenRefresher
{
    public function refresh(AccessToken $token): bool
    {
        try {
            $response = Http::post(config('airoxy.oauth.endpoint'), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $token->refresh_token,
                'client_id' => config('airoxy.oauth.client_id'),
                'scope' => config('airoxy.oauth.scope'),
            ]);

            if ($response->failed()) {
                return $this->handleFailure($token, 'HTTP '.$response->status());
            }

            $data = $response->json();

            $token->update([
                'token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at' => now()->addSeconds($data['expires_in']),
                'refresh_fail_count' => 0,
            ]);

            return true;
        } catch (\Throwable $e) {
            return $this->handleFailure($token, $e->getMessage());
        }
    }

    private function handleFailure(AccessToken $token, string $reason): bool
    {
        $failCount = $token->refresh_fail_count + 1;
        $updates = ['refresh_fail_count' => $failCount];

        if ($failCount >= 3) {
            $updates['is_active'] = false;
            Log::warning("Access token '{$token->name}' (ID: {$token->id}) deactivated after {$failCount} consecutive refresh failures.");
        }

        $token->update($updates);
        Log::error("Token refresh failed for '{$token->name}' (ID: {$token->id}): {$reason}");

        return false;
    }

    /**
     * @return array{refreshed: int, failed: int}
     */
    public function refreshAll(): array
    {
        $tokens = AccessToken::where('is_active', true)
            ->where('type', 'oauth')
            ->whereNotNull('token_expires_at')
            ->get();
        $refreshed = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            if ($this->refresh($token)) {
                $refreshed++;
            } else {
                $failed++;
            }
        }

        return compact('refreshed', 'failed');
    }
}
