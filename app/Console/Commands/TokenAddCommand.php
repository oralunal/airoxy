<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use Illuminate\Console\Command;

class TokenAddCommand extends Command
{
    protected $signature = 'airoxy:token:add {token} {refresh_token?} {--name=} {--expires-in=28800}';

    protected $description = 'Add a new access token';

    public function handle(): int
    {
        $tokenValue = $this->argument('token');

        if (! str_starts_with($tokenValue, 'sk-ant-')) {
            $this->error('Invalid token format. Token must start with sk-ant-');

            return self::FAILURE;
        }

        $isOauth = str_starts_with($tokenValue, 'sk-ant-oat');
        $refreshToken = $this->argument('refresh_token');

        if ($isOauth && ! $refreshToken) {
            $this->error('OAuth tokens require a refresh_token argument.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name'),
            'token' => $tokenValue,
        ];

        if ($isOauth) {
            $data['refresh_token'] = $refreshToken;
            $data['token_expires_at'] = now()->addSeconds((int) $this->option('expires-in'));
        }

        $token = AccessToken::create($data);

        $this->info("Access token added successfully (ID: {$token->id}, Type: {$token->type->value}).");

        return self::SUCCESS;
    }
}
