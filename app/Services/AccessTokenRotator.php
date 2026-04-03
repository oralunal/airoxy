<?php

namespace App\Services;

use App\Models\AccessToken;
use Illuminate\Support\Facades\DB;

class AccessTokenRotator
{
    /**
     * @param  array<int>  $excludeIds
     */
    public function selectNext(array $excludeIds = []): ?AccessToken
    {
        return DB::transaction(function () use ($excludeIds) {
            $query = AccessToken::where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('token_expires_at')
                        ->orWhere('token_expires_at', '>', now());
                })
                ->orderByRaw('last_used_at IS NOT NULL, last_used_at ASC')
                ->orderBy('usage_order');

            if ($excludeIds) {
                $query->whereNotIn('id', $excludeIds);
            }

            $token = $query->first();

            if ($token) {
                $token->update(['last_used_at' => now()]);
            }

            return $token;
        });
    }
}
