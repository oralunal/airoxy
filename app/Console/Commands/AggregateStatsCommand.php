<?php

namespace App\Console\Commands;

use App\Models\DailyStat;
use App\Models\RequestLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateStatsCommand extends Command
{
    protected $signature = 'airoxy:aggregate-stats';

    protected $description = 'Aggregate request logs into daily stats';

    public function handle(): int
    {
        $days = RequestLog::select(DB::raw('DATE(requested_at) as log_date'))
            ->where('requested_at', '<', today())
            ->groupBy('log_date')
            ->pluck('log_date');

        $aggregated = 0;

        foreach ($days as $date) {
            if (DailyStat::where('date', $date)->exists()) {
                continue;
            }

            // Per token+model stats
            $stats = RequestLog::select([
                DB::raw('DATE(requested_at) as date'),
                'access_token_id',
                'model',
                DB::raw('COUNT(*) as total_requests'),
                DB::raw('SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests'),
                DB::raw('SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests'),
                DB::raw('COALESCE(SUM(input_tokens), 0) as total_input_tokens'),
                DB::raw('COALESCE(SUM(output_tokens), 0) as total_output_tokens'),
                DB::raw('COALESCE(SUM(cache_creation_input_tokens), 0) as total_cache_creation_tokens'),
                DB::raw('COALESCE(SUM(cache_read_input_tokens), 0) as total_cache_read_tokens'),
                DB::raw('COALESCE(SUM(estimated_cost_usd), 0) as total_estimated_cost_usd'),
            ])
                ->whereDate('requested_at', $date)
                ->groupBy('date', 'access_token_id', 'model')
                ->get();

            foreach ($stats as $stat) {
                DailyStat::create($stat->toArray());
            }

            // Rollup row (all tokens, all models) - use parameter binding for date
            $rollup = RequestLog::whereDate('requested_at', $date)
                ->selectRaw('COUNT(*) as total_requests')
                ->selectRaw('SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests')
                ->selectRaw('SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests')
                ->selectRaw('COALESCE(SUM(input_tokens), 0) as total_input_tokens')
                ->selectRaw('COALESCE(SUM(output_tokens), 0) as total_output_tokens')
                ->selectRaw('COALESCE(SUM(cache_creation_input_tokens), 0) as total_cache_creation_tokens')
                ->selectRaw('COALESCE(SUM(cache_read_input_tokens), 0) as total_cache_read_tokens')
                ->selectRaw('COALESCE(SUM(estimated_cost_usd), 0) as total_estimated_cost_usd')
                ->first();

            DailyStat::create(array_merge($rollup->toArray(), [
                'date' => $date,
                'access_token_id' => null,
                'model' => null,
            ]));

            $aggregated++;
        }

        $this->info("Aggregated {$aggregated} day(s).");

        return self::SUCCESS;
    }
}
