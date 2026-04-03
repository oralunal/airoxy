<?php

namespace App\Services;

class CostCalculator
{
    public function calculate(
        string $model,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $cacheCreationInputTokens = null,
        ?int $cacheReadInputTokens = null,
    ): float {
        $pricing = config('airoxy.pricing');
        $modelPricing = $pricing[$model] ?? $pricing['default'];

        $inputPrice = $modelPricing['input'];
        $outputPrice = $modelPricing['output'];
        $cacheWriteMultiplier = $pricing['cache_write_multiplier'];
        $cacheReadMultiplier = $pricing['cache_read_multiplier'];

        $inputCost = ($inputTokens ?? 0) / 1_000_000 * $inputPrice;
        $outputCost = ($outputTokens ?? 0) / 1_000_000 * $outputPrice;
        $cacheWriteCost = ($cacheCreationInputTokens ?? 0) / 1_000_000 * $inputPrice * $cacheWriteMultiplier;
        $cacheReadCost = ($cacheReadInputTokens ?? 0) / 1_000_000 * $inputPrice * $cacheReadMultiplier;

        return round($inputCost + $outputCost + $cacheWriteCost + $cacheReadCost, 10);
    }
}
