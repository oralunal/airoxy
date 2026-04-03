<?php

namespace App\Console\Commands;

use App\Models\AnthropicApiKey;
use Illuminate\Console\Command;

class ApiKeyListCommand extends Command
{
    protected $signature = 'airoxy:api-key:list';

    protected $description = 'List all Anthropic API keys';

    public function handle(): int
    {
        $keys = AnthropicApiKey::orderBy('usage_order')->get();

        if ($keys->isEmpty()) {
            $this->warn('No API keys found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Key', 'Active', 'Order', 'Last Used'],
            $keys->map(fn (AnthropicApiKey $key) => [
                $key->id,
                $key->name ?? '-',
                $key->masked_key,
                $key->is_active ? 'Yes' : 'No',
                $key->usage_order,
                $key->last_used_at?->diffForHumans() ?? 'Never',
            ]),
        );

        return self::SUCCESS;
    }
}
