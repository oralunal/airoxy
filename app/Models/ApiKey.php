<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'key', 'is_active'])]
class ApiKey extends Model
{
    protected $table = 'api_keys';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(RequestLog::class);
    }

    /**
     * Generate a new unique API key.
     */
    public static function generateKey(): string
    {
        return 'ak-'.Str::random(40);
    }

    /**
     * Get a masked version of the key for display.
     */
    public function getMaskedKeyAttribute(): string
    {
        $key = $this->key;

        if (strlen($key) <= 12) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 8).str_repeat('*', strlen($key) - 12).substr($key, -4);
    }
}
