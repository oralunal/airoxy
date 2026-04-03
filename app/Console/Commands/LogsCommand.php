<?php

namespace App\Console\Commands;

use App\Models\RequestLog;
use Illuminate\Console\Command;

class LogsCommand extends Command
{
    protected $signature = 'airoxy:logs {--limit=50} {--token=} {--today} {--date=}';

    protected $description = 'Show request logs';

    public function handle(): int
    {
        $query = RequestLog::with(['accessToken', 'apiKey'])
            ->orderByDesc('requested_at');

        if ($this->option('token')) {
            $query->where('access_token_id', $this->option('token'));
        }

        if ($this->option('today')) {
            $query->whereDate('requested_at', today());
        } elseif ($date = $this->option('date')) {
            $query->whereDate('requested_at', $date);
        }

        $logs = $query->limit((int) $this->option('limit'))->get();

        if ($logs->isEmpty()) {
            $this->warn('No logs found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Token', 'Model', 'Stream', 'In/Out', 'Cache(W/R)', 'Cost', 'Status', 'Duration', 'Date'],
            $logs->map(fn (RequestLog $log) => [
                $log->id,
                $log->accessToken?->name ?? '-',
                $log->model,
                $log->is_stream ? 'Yes' : 'No',
                number_format($log->input_tokens ?? 0).'/'.number_format($log->output_tokens ?? 0),
                number_format($log->cache_creation_input_tokens ?? 0).'/'.number_format($log->cache_read_input_tokens ?? 0),
                '$'.number_format($log->estimated_cost_usd ?? 0, 6),
                $log->status_code,
                $log->duration_ms.'ms',
                $log->requested_at?->format('Y-m-d H:i:s'),
            ]),
        );

        return self::SUCCESS;
    }
}
