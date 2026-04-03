<?php

namespace App\Console\Commands;

use App\Models\RequestLog;
use Illuminate\Console\Command;

class PurgeLogsCommand extends Command
{
    protected $signature = 'airoxy:purge-logs';

    protected $description = 'Purge request logs older than retention period';

    public function handle(): int
    {
        $days = config('airoxy.log_retention_days', 3);
        $cutoff = now()->subDays($days);

        $deleted = RequestLog::where('requested_at', '<', $cutoff)->delete();

        $this->info("Purged {$deleted} log(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
