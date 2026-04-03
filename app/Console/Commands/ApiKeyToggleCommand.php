<?php

namespace App\Console\Commands;

use App\Models\AnthropicApiKey;
use Illuminate\Console\Command;

class ApiKeyToggleCommand extends Command
{
    protected $signature = 'airoxy:api-key:toggle {id}';

    protected $description = 'Toggle an API key active/inactive';

    public function handle(): int
    {
        $key = AnthropicApiKey::find($this->argument('id'));

        if (! $key) {
            $this->error('API key not found.');

            return self::FAILURE;
        }

        $key->update(['is_active' => ! $key->is_active]);
        $status = $key->is_active ? 'activated' : 'deactivated';
        $this->info("API key '{$key->name}' (ID: {$key->id}) {$status}.");

        return self::SUCCESS;
    }
}
