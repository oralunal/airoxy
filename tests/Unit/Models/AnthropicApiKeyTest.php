<?php

use App\Models\ApiKey;

it('generates a key with ak- prefix', function () {
    $key = ApiKey::generateKey();

    expect($key)->toStartWith('ak-')
        ->and(strlen($key))->toBe(43);
});

it('masks the key for display showing first 8 and last 4 chars', function () {
    $key = new ApiKey(['key' => 'ak-abcdefghijklmnopqrstuvwxyz1234567890']);
    $masked = $key->masked_key;

    expect($masked)
        ->toStartWith('ak-abcde')
        ->toEndWith('7890')
        ->toContain('***');
});
