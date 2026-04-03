<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use Illuminate\Console\Command;

class TokenAddCommand extends Command
{
    protected $signature = 'airoxy:token:add {token} {refresh_token} {--name=} {--expires-in=28800}';

    protected $description = 'Add a new access token';

    public function handle(): int
    {
        $token = AccessToken::create([
            'name' => $this->option('name'),
            'token' => $this->argument('token'),
            'refresh_token' => $this->argument('refresh_token'),
            'token_expires_at' => now()->addSeconds((int) $this->option('expires-in')),
        ]);

        $this->info("Access token added successfully (ID: {$token->id}).");

        return self::SUCCESS;
    }
}
