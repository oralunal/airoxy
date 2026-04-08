<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use Illuminate\Console\Command;

class TokenListCommand extends Command
{
    protected $signature = 'airoxy:token:list';

    protected $description = 'List all access tokens';

    public function handle(): int
    {
        $tokens = AccessToken::orderBy('id')->get();

        if ($tokens->isEmpty()) {
            $this->warn('No access tokens found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Type', 'Token', 'Active', 'Expires', 'Fails', 'Last Used'],
            $tokens->map(fn (AccessToken $token) => [
                $token->id,
                $token->name ?? '-',
                $token->type->value,
                $token->masked_token,
                $token->is_active ? 'Yes' : 'No',
                $token->token_expires_at?->diffForHumans() ?? '-',
                $token->refresh_fail_count,
                $token->last_used_at?->diffForHumans() ?? 'Never',
            ]),
        );

        return self::SUCCESS;
    }
}
