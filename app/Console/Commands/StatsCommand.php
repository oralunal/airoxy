<?php

namespace App\Console\Commands;

use App\Models\AccessToken;
use App\Models\ApiKey;
use App\Models\DailyStat;
use App\Models\RequestLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StatsCommand extends Command
{
    protected $signature = 'airoxy:stats {--today} {--month} {--token=}';

    protected $description = 'Show usage statistics';

    public function handle(): int
    {
        // Recent stats from request_logs (only non-aggregated days)
        $aggregatedDates = DailyStat::pluck('date')->map(fn ($d) => $d->format('Y-m-d'))->toArray();
        $recentQuery = RequestLog::query();
        if ($aggregatedDates) {
            $recentQuery->whereNotIn(DB::raw('DATE(requested_at)'), $aggregatedDates);
        }
        $historicalQuery = DailyStat::whereNull('access_token_id')->whereNull('model');

        if ($this->option('token')) {
            $recentQuery->where('access_token_id', $this->option('token'));
            $historicalQuery = DailyStat::where('access_token_id', $this->option('token'))->whereNull('model');
        }

        if ($this->option('today')) {
            $recentQuery->whereDate('requested_at', today());
            $historicalQuery->whereDate('date', today());
        } elseif ($this->option('month')) {
            $recentQuery->whereMonth('requested_at', now()->month)->whereYear('requested_at', now()->year);
            $historicalQuery->whereMonth('date', now()->month)->whereYear('date', now()->year);
        }

        $recentStats = $recentQuery->selectRaw('
            COUNT(*) as total_requests,
            SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) as successful_requests,
            SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as failed_requests,
            COALESCE(SUM(input_tokens), 0) as total_input_tokens,
            COALESCE(SUM(output_tokens), 0) as total_output_tokens,
            COALESCE(SUM(cache_creation_input_tokens), 0) as total_cache_creation_tokens,
            COALESCE(SUM(cache_read_input_tokens), 0) as total_cache_read_tokens,
            COALESCE(SUM(estimated_cost_usd), 0) as total_cost
        ')->first();

        $historicalStats = $historicalQuery->selectRaw('
            COALESCE(SUM(total_requests), 0) as total_requests,
            COALESCE(SUM(successful_requests), 0) as successful_requests,
            COALESCE(SUM(failed_requests), 0) as failed_requests,
            COALESCE(SUM(total_input_tokens), 0) as total_input_tokens,
            COALESCE(SUM(total_output_tokens), 0) as total_output_tokens,
            COALESCE(SUM(total_cache_creation_tokens), 0) as total_cache_creation_tokens,
            COALESCE(SUM(total_cache_read_tokens), 0) as total_cache_read_tokens,
            COALESCE(SUM(total_estimated_cost_usd), 0) as total_cost
        ')->first();

        $totalRequests = $recentStats->total_requests + $historicalStats->total_requests;
        $successRequests = $recentStats->successful_requests + $historicalStats->successful_requests;
        $failedRequests = $recentStats->failed_requests + $historicalStats->failed_requests;
        $totalInput = $recentStats->total_input_tokens + $historicalStats->total_input_tokens;
        $totalOutput = $recentStats->total_output_tokens + $historicalStats->total_output_tokens;
        $totalCacheWrite = $recentStats->total_cache_creation_tokens + $historicalStats->total_cache_creation_tokens;
        $totalCacheRead = $recentStats->total_cache_read_tokens + $historicalStats->total_cache_read_tokens;
        $totalCost = $recentStats->total_cost + $historicalStats->total_cost;

        $this->line('');
        $this->line(str_repeat('=', 50));
        $this->line('  AIROXY STATISTICS');
        $this->line(str_repeat('=', 50));
        $this->line('  Total Requests           : '.number_format($totalRequests));
        $this->line('  Successful Requests      : '.number_format($successRequests));
        $this->line('  Failed Requests          : '.number_format($failedRequests));
        $this->line('  Total Input Tokens       : '.number_format($totalInput));
        $this->line('  Total Output Tokens      : '.number_format($totalOutput));
        $this->line('  Cache Write Tokens       : '.number_format($totalCacheWrite));
        $this->line('  Cache Read Tokens        : '.number_format($totalCacheRead));
        $this->line('  Estimated Total Cost     : $'.number_format($totalCost, 2));
        $this->line(str_repeat('-', 50));
        $this->line('  Active API Keys          : '.ApiKey::where('is_active', true)->count());
        $this->line('  Active Tokens            : '.AccessToken::where('is_active', true)->count());
        $this->line(str_repeat('=', 50));
        $this->line('');

        return self::SUCCESS;
    }
}
