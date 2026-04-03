<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'access_token_id',
        'api_key_id',
        'model',
        'endpoint',
        'is_stream',
        'input_tokens',
        'output_tokens',
        'cache_creation_input_tokens',
        'cache_read_input_tokens',
        'estimated_cost_usd',
        'status_code',
        'error_message',
        'duration_ms',
        'requested_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_stream' => 'boolean',
            'estimated_cost_usd' => 'decimal:8',
            'requested_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(AccessToken::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(AnthropicApiKey::class, 'api_key_id');
    }
}
