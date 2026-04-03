<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class ApiKeyListCommand extends Command
{
    protected $signature = 'airoxy:api-key:list';

    protected $description = 'List all API keys';

    public function handle(): int
    {
        $keys = ApiKey::orderBy('id')->get();

        if ($keys->isEmpty()) {
            $this->warn('No API keys found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Key', 'Active'],
            $keys->map(fn (ApiKey $key) => [
                $key->id,
                $key->name ?? '-',
                $key->masked_key,
                $key->is_active ? 'Yes' : 'No',
            ]),
        );

        return self::SUCCESS;
    }
}
