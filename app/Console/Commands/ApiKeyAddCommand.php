<?php

namespace App\Console\Commands;

use App\Models\AnthropicApiKey;
use Illuminate\Console\Command;

class ApiKeyAddCommand extends Command
{
    protected $signature = 'airoxy:api-key:add {api_key} {--name=}';

    protected $description = 'Add a new Anthropic API key';

    public function handle(): int
    {
        $maxOrder = AnthropicApiKey::max('usage_order') ?? 0;

        $key = AnthropicApiKey::create([
            'name' => $this->option('name'),
            'api_key' => $this->argument('api_key'),
            'usage_order' => $maxOrder + 1,
        ]);

        $this->info("API key added successfully (ID: {$key->id}).");

        return self::SUCCESS;
    }
}
