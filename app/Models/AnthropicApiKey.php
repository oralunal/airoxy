<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'api_key', 'is_active', 'last_used_at', 'usage_order'])]
class AnthropicApiKey extends Model
{
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(RequestLog::class, 'api_key_id');
    }

    /**
     * Get a masked version of the API key for display (last 8 chars visible).
     */
    public function getMaskedKeyAttribute(): string
    {
        $key = $this->api_key;

        if (strlen($key) <= 8) {
            return str_repeat('*', strlen($key));
        }

        return str_repeat('*', strlen($key) - 8).substr($key, -8);
    }
}
