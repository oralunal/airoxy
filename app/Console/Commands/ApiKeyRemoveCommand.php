<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class ApiKeyRemoveCommand extends Command
{
    protected $signature = 'airoxy:api-key:remove {id}';

    protected $description = 'Remove an API key';

    public function handle(): int
    {
        $key = ApiKey::find($this->argument('id'));

        if (! $key) {
            $this->error('API key not found.');

            return self::FAILURE;
        }

        $key->delete();
        $this->info("API key '{$key->name}' (ID: {$key->id}) removed.");

        return self::SUCCESS;
    }
}
