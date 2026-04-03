<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use Illuminate\Console\Command;

class TokenRemoveCommand extends Command
{
    protected $signature = 'airoxy:token:remove {id}';

    protected $description = 'Remove an access token';

    public function handle(): int
    {
        $token = AccessToken::find($this->argument('id'));

        if (! $token) {
            $this->error('Token not found.');

            return self::FAILURE;
        }

        $token->delete();
        $this->info("Token '{$token->name}' (ID: {$token->id}) removed.");

        return self::SUCCESS;
    }
}
