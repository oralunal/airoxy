<?php

use App\Services\StreamHandler;

it('parses input tokens from message_start event', function () {
    $handler = new StreamHandler;
    $chunk = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":25,\"cache_creation_input_tokens\":100,\"cache_read_input_tokens\":200}}}\n\n";
    $handler->parseSSEChunk($chunk);

    expect($handler->getInputTokens())->toBe(25)
        ->and($handler->getCacheCreationInputTokens())->toBe(100)
        ->and($handler->getCacheReadInputTokens())->toBe(200);
});

it('parses output tokens from message_delta event', function () {
    $handler = new StreamHandler;
    $chunk = "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":42}}\n\n";
    $handler->parseSSEChunk($chunk);
    expect($handler->getOutputTokens())->toBe(42);
});

it('handles chunks split across event boundaries', function () {
    $handler = new StreamHandler;
    $handler->parseSSEChunk("event: message_start\ndata: {\"type\":\"message_start\",\"mes");
    expect($handler->getInputTokens())->toBeNull();
    $handler->parseSSEChunk("sage\":{\"usage\":{\"input_tokens\":50}}}\n\n");
    expect($handler->getInputTokens())->toBe(50);
});

it('handles multiple events in a single chunk', function () {
    $handler = new StreamHandler;
    $chunk = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":10}}}\n\nevent: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{},\"usage\":{\"output_tokens\":20}}\n\n";
    $handler->parseSSEChunk($chunk);

    expect($handler->getInputTokens())->toBe(10)
        ->and($handler->getOutputTokens())->toBe(20);
});

it('ignores non-usage events', function () {
    $handler = new StreamHandler;
    $chunk = "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello\"}}\n\n";
    $handler->parseSSEChunk($chunk);

    expect($handler->getInputTokens())->toBeNull()
        ->and($handler->getOutputTokens())->toBeNull();
});
