<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use App\Services\TokenRefresher;
use Illuminate\Console\Command;

class TokenRefreshCommand extends Command
{
    protected $signature = 'airoxy:token:refresh {--id=}';

    protected $description = 'Refresh access tokens via OAuth2';

    public function handle(TokenRefresher $refresher): int
    {
        if ($id = $this->option('id')) {
            $token = AccessToken::find($id);

            if (! $token) {
                $this->error('Token not found.');

                return self::FAILURE;
            }

            if ($token->isApiKey()) {
                $this->warn("Token ID {$id} is not an OAuth token — skipping refresh.");

                return self::SUCCESS;
            }

            $result = $refresher->refresh($token);
            $this->info($result ? 'Token refreshed successfully.' : 'Token refresh failed.');

            return $result ? self::SUCCESS : self::FAILURE;
        }

        $result = $refresher->refreshAll();
        $this->info("Refreshed: {$result['refreshed']}, Failed: {$result['failed']}");

        return self::SUCCESS;
    }
}
