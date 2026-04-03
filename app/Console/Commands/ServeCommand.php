<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ServeCommand extends Command
{
    protected $signature = 'airoxy:serve';

    protected $description = 'Start the Airoxy proxy server';

    public function handle(): int
    {
        $host = config('airoxy.host');
        $port = config('airoxy.port');

        $this->info("Starting Airoxy on {$host}:{$port}...");

        return $this->call('octane:start', [
            '--server' => 'frankenphp',
            '--host' => $host,
            '--port' => $port,
        ]);
    }
}
