<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class ApiKeyAddCommand extends Command
{
    protected $signature = 'airoxy:api-key:add {--name=}';

    protected $description = 'Add a new API key for client authentication';

    public function handle(): int
    {
        $generatedKey = ApiKey::generateKey();

        $key = ApiKey::create([
            'name' => $this->option('name'),
            'key' => $generatedKey,
        ]);

        $this->info("API key added successfully (ID: {$key->id}).");
        $this->newLine();
        $this->warn('Save this key now — it will not be shown again:');
        $this->line($generatedKey);

        return self::SUCCESS;
    }
}
