<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'token', 'refresh_token', 'token_expires_at', 'is_active', 'last_used_at', 'refresh_fail_count'])]
class AccessToken extends Model
{
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(RequestLog::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(DailyStat::class);
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    public function isValid(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }

    /**
     * Get a masked version of the token for display (first 8 + last 4 chars visible).
     */
    public function getMaskedTokenAttribute(): string
    {
        $token = $this->token;

        if (strlen($token) <= 12) {
            return str_repeat('*', strlen($token));
        }

        return substr($token, 0, 8).str_repeat('*', strlen($token) - 12).substr($token, -4);
    }
}
