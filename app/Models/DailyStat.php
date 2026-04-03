<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyStat extends Model
{
    protected $fillable = [
        'date',
        'access_token_id',
        'model',
        'total_requests',
        'successful_requests',
        'failed_requests',
        'total_input_tokens',
        'total_output_tokens',
        'total_cache_creation_tokens',
        'total_cache_read_tokens',
        'total_estimated_cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_estimated_cost_usd' => 'decimal:8',
        ];
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(AccessToken::class);
    }
}
