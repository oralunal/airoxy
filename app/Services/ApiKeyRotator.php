<?php

namespace App\Services;

use App\Models\AnthropicApiKey;
use Illuminate\Support\Facades\DB;

class ApiKeyRotator
{
    /**
     * @param  array<int>  $excludeIds
     */
    public function selectNext(array $excludeIds = []): ?AnthropicApiKey
    {
        return DB::transaction(function () use ($excludeIds) {
            $query = AnthropicApiKey::where('is_active', true)
                ->orderByRaw('last_used_at IS NOT NULL, last_used_at ASC')
                ->orderBy('usage_order');

            if ($excludeIds) {
                $query->whereNotIn('id', $excludeIds);
            }

            $key = $query->first();

            if ($key) {
                $key->update(['last_used_at' => now()]);
            }

            return $key;
        });
    }
}
