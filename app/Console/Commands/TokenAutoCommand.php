<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TokenAutoCommand extends Command
{
    protected $signature = 'airoxy:token:auto {--dry-run} {--path=}';

    protected $description = 'Auto-import tokens from Claude Code credentials files';

    public function handle(): int
    {
        $paths = $this->getCredentialPaths();
        $found = 0;
        $added = 0;
        $updated = 0;

        foreach ($paths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $content = json_decode(file_get_contents($path), true);
            $oauth = $content['claudeAiOauth'] ?? null;

            if (! $oauth || ! isset($oauth['accessToken'], $oauth['refreshToken'])) {
                continue;
            }

            $found++;
            $username = $this->extractUsername($path);

            $existing = AccessToken::where('refresh_token', $oauth['refreshToken'])->first();

            if ($existing) {
                if (! $this->option('dry-run')) {
                    $existing->update([
                        'token' => $oauth['accessToken'],
                        'token_expires_at' => isset($oauth['expiresAt'])
                            ? Carbon::createFromTimestampMs($oauth['expiresAt'])
                            : null,
                    ]);
                    $updated++;
                } else {
                    $this->line("  [UPDATE] {$username}: {$path}");
                }

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  [NEW] {$username}: {$path}");

                continue;
            }

            AccessToken::create([
                'name' => $username,
                'token' => $oauth['accessToken'],
                'refresh_token' => $oauth['refreshToken'],
                'token_expires_at' => isset($oauth['expiresAt'])
                    ? Carbon::createFromTimestampMs($oauth['expiresAt'])
                    : null,
            ]);

            $added++;
        }

        $this->info('Scanned paths: '.count($paths));
        $this->info("Found credentials: {$found}");
        $this->info("New: {$added}");
        $this->info("Updated: {$updated}");

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes made.');
        }

        return self::SUCCESS;
    }

    private function getCredentialPaths(): array
    {
        if ($customPath = $this->option('path')) {
            return [$customPath];
        }

        $paths = ['/root/.claude/.credentials.json'];

        if (is_dir('/home')) {
            foreach (glob('/home/*') as $homeDir) {
                $paths[] = $homeDir.'/.claude/.credentials.json';
            }
        }

        return $paths;
    }

    private function extractUsername(string $path): string
    {
        if (str_starts_with($path, '/root/')) {
            return 'root';
        }

        if (preg_match('#/home/([^/]+)/#', $path, $matches)) {
            return $matches[1];
        }

        return basename(dirname(dirname($path)));
    }
}
