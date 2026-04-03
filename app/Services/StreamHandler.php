<?php

namespace App\Services;

class StreamHandler
{
    private ?int $inputTokens = null;

    private ?int $outputTokens = null;

    private ?int $cacheCreationInputTokens = null;

    private ?int $cacheReadInputTokens = null;

    private string $buffer = '';

    public function parseSSEChunk(string $chunk): void
    {
        $this->buffer .= $chunk;

        while (($pos = strpos($this->buffer, "\n\n")) !== false) {
            $event = substr($this->buffer, 0, $pos);
            $this->buffer = substr($this->buffer, $pos + 2);
            $this->parseEvent($event);
        }
    }

    private function parseEvent(string $event): void
    {
        $dataLine = null;
        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $dataLine = substr($line, 6);
            }
        }

        if ($dataLine === null) {
            return;
        }

        $data = json_decode($dataLine, true);
        if (! is_array($data)) {
            return;
        }

        match ($data['type'] ?? null) {
            'message_start' => $this->parseMessageStart($data),
            'message_delta' => $this->parseMessageDelta($data),
            default => null,
        };
    }

    private function parseMessageStart(array $data): void
    {
        $usage = $data['message']['usage'] ?? [];
        $this->inputTokens = $usage['input_tokens'] ?? null;
        $this->cacheCreationInputTokens = $usage['cache_creation_input_tokens'] ?? null;
        $this->cacheReadInputTokens = $usage['cache_read_input_tokens'] ?? null;
    }

    private function parseMessageDelta(array $data): void
    {
        $this->outputTokens = $data['usage']['output_tokens'] ?? null;
    }

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function getCacheCreationInputTokens(): ?int
    {
        return $this->cacheCreationInputTokens;
    }

    public function getCacheReadInputTokens(): ?int
    {
        return $this->cacheReadInputTokens;
    }

    public function reset(): void
    {
        $this->inputTokens = null;
        $this->outputTokens = null;
        $this->cacheCreationInputTokens = null;
        $this->cacheReadInputTokens = null;
        $this->buffer = '';
    }
}
