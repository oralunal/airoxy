<?php

use App\Services\CostCalculator;

it('calculates cost for known model', function () {
    $calculator = new CostCalculator;
    $cost = $calculator->calculate(model: 'claude-sonnet-4-6', inputTokens: 1000, outputTokens: 500);
    expect($cost)->toBe(0.0105);
});

it('calculates cost with cache tokens', function () {
    $calculator = new CostCalculator;
    $cost = $calculator->calculate(model: 'claude-sonnet-4-6', inputTokens: 1000, outputTokens: 500, cacheCreationInputTokens: 2000, cacheReadInputTokens: 5000);
    expect($cost)->toBe(0.0195);
});

it('uses default pricing for unknown model', function () {
    $calculator = new CostCalculator;
    $cost = $calculator->calculate(model: 'claude-unknown-model', inputTokens: 1_000_000, outputTokens: 0);
    expect($cost)->toBe(3.0);
});

it('returns zero for null tokens', function () {
    $calculator = new CostCalculator;
    $cost = $calculator->calculate('claude-sonnet-4-6');
    expect($cost)->toBe(0.0);
});
