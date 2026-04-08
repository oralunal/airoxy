<?php

namespace App\Services;

use App\Models\AccessToken;
use App\Models\ApiKey;
use App\Models\RequestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProxyService
{
    public function __construct(
        private AccessTokenRotator $accessTokenRotator,
        private CostCalculator $costCalculator,
    ) {}

    public function handle(Request $request, ApiKey $apiKey): Response
    {
        $rawBody = $request->getContent();
        $parsed = json_decode($rawBody, true) ?: [];
        $model = $parsed['model'] ?? 'unknown';
        $isStream = $parsed['stream'] ?? false;

        $requestedAt = now();
        $excludeTokenIds = [];
        $lastError = null;

        while (true) {
            $accessToken = $this->accessTokenRotator->selectNext($excludeTokenIds);

            if (! $accessToken) {
                if ($lastError) {
                    return $lastError;
                }

                return response()->json([
                    'type' => 'error',
                    'error' => ['type' => 'api_error', 'message' => 'No access tokens available'],
                ], 503);
            }

            $proxyHeaders = $this->buildProxyHeaders($request, $accessToken);
            $forwardBody = $this->prepareRequestBody($rawBody, $parsed, $accessToken);

            if ($isStream) {
                $result = $this->handleStreamingRequest($forwardBody, $proxyHeaders, $apiKey, $accessToken, $model, $requestedAt);
            } else {
                $result = $this->handleNonStreamingRequest($forwardBody, $proxyHeaders, $apiKey, $accessToken, $model, $requestedAt);
            }

            if ($result instanceof Response && in_array($result->getStatusCode(), [429, 529])) {
                $excludeTokenIds[] = $accessToken->id;
                $lastError = $result;

                continue;
            }

            return $result;
        }
    }

    private function handleNonStreamingRequest(
        string $rawBody,
        array $proxyHeaders,
        ApiKey $apiKey,
        AccessToken $accessToken,
        string $model,
        Carbon $requestedAt,
    ): Response {
        $response = Http::withHeaders($proxyHeaders)
            ->timeout(300)
            ->connectTimeout(10)
            ->withBody($rawBody)
            ->post(config('airoxy.anthropic_api_url'));

        $statusCode = $response->status();
        $responseData = $response->json() ?? [];

        $inputTokens = $responseData['usage']['input_tokens'] ?? null;
        $outputTokens = $responseData['usage']['output_tokens'] ?? null;
        $cacheCreationInputTokens = $responseData['usage']['cache_creation_input_tokens'] ?? null;
        $cacheReadInputTokens = $responseData['usage']['cache_read_input_tokens'] ?? null;

        $this->logRequest(
            apiKey: $apiKey,
            accessToken: $accessToken,
            model: $model,
            statusCode: $statusCode,
            isStream: false,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreationInputTokens: $cacheCreationInputTokens,
            cacheReadInputTokens: $cacheReadInputTokens,
            requestedAt: $requestedAt,
            errorMessage: $statusCode >= 400 ? ($responseData['error']['message'] ?? null) : null,
        );

        $responseHeaders = $this->forwardResponseHeaders($response);

        return response($response->body(), $statusCode)->withHeaders($responseHeaders);
    }

    private function handleStreamingRequest(
        string $rawBody,
        array $proxyHeaders,
        ApiKey $apiKey,
        AccessToken $accessToken,
        string $model,
        Carbon $requestedAt,
    ): Response {
        $streamHandler = app()->make(StreamHandler::class);
        $proxyService = $this;

        return new StreamedResponse(function () use ($rawBody, $proxyHeaders, $proxyService, $streamHandler, $apiKey, $accessToken, $model, $requestedAt) {
            // Kill ALL output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ob_implicit_flush(true);

            $errorMessage = null;
            $statusCode = 200;

            // Use native curl — CURLOPT_WRITEFUNCTION fires immediately per chunk
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => config('airoxy.anthropic_api_url'),
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $rawBody,
                CURLOPT_HTTPHEADER => array_map(
                    fn ($k, $v) => "{$k}: {$v}",
                    array_keys($proxyHeaders),
                    array_values($proxyHeaders),
                ),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$statusCode) {
                    if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) {
                        $statusCode = (int) $m[1];
                    }

                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($streamHandler, &$statusCode, &$errorMessage) {
                    if ($statusCode >= 400) {
                        $decoded = json_decode($data, true);
                        $errorMessage = $decoded['error']['message'] ?? $data;

                        return strlen($data);
                    }

                    // Write directly to output — no buffering
                    echo $data;
                    flush();

                    $streamHandler->parseSSEChunk($data);

                    if (connection_aborted()) {
                        $errorMessage = 'Client disconnected';

                        return 0; // Abort curl transfer
                    }

                    return strlen($data);
                },
            ]);

            curl_exec($ch);

            if (curl_errno($ch) && ! $errorMessage) {
                $errorMessage = curl_error($ch);
            }

            curl_close($ch);

            $proxyService->logRequest(
                apiKey: $apiKey,
                accessToken: $accessToken,
                model: $model,
                statusCode: $statusCode,
                isStream: true,
                inputTokens: $streamHandler->getInputTokens(),
                outputTokens: $streamHandler->getOutputTokens(),
                cacheCreationInputTokens: $streamHandler->getCacheCreationInputTokens(),
                cacheReadInputTokens: $streamHandler->getCacheReadInputTokens(),
                requestedAt: $requestedAt,
                errorMessage: $errorMessage,
            );
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * For OAuth tokens, inject the required Claude Code system prompt if not present.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function prepareRequestBody(string $rawBody, array $parsed, AccessToken $accessToken): string
    {
        if (! $accessToken->isOauth()) {
            return $rawBody;
        }

        $systemPrompt = "You are Claude Code, Anthropic's official CLI for Claude.";

        if (! isset($parsed['system'])) {
            $parsed['system'] = [['type' => 'text', 'text' => $systemPrompt]];

            return json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Check if system prompt already contains the required text
        $systemContent = is_string($parsed['system']) ? $parsed['system'] : json_encode($parsed['system']);
        if (str_contains($systemContent, 'Claude Code')) {
            return $rawBody;
        }

        // Prepend to existing system
        if (is_string($parsed['system'])) {
            $parsed['system'] = [
                ['type' => 'text', 'text' => $systemPrompt],
                ['type' => 'text', 'text' => $parsed['system']],
            ];
        } elseif (is_array($parsed['system'])) {
            array_unshift($parsed['system'], ['type' => 'text', 'text' => $systemPrompt]);
        }

        return json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, string>
     */
    private function buildProxyHeaders(Request $request, AccessToken $accessToken): array
    {
        $headers['content-type'] = 'application/json';

        if ($accessToken->isOauth()) {
            $headers['anthropic-version'] = $request->header('anthropic-version', config('airoxy.anthropic_version'));
            $headers['authorization'] = 'Bearer '.$accessToken->token;

            $requiredBeta = 'claude-code-20250219,oauth-2025-04-20';
            $clientBeta = $request->header('anthropic-beta');
            if ($clientBeta) {
                $flags = array_unique(array_merge(
                    explode(',', $requiredBeta),
                    explode(',', $clientBeta),
                ));
                $headers['anthropic-beta'] = implode(',', $flags);
            } else {
                $headers['anthropic-beta'] = $requiredBeta;
            }

            $headers['user-agent'] = 'claude-cli/2.1.91 (external, cli)';
            $headers['x-app'] = 'cli';
        } else {
            $headers['x-api-key'] = $accessToken->token;

            if ($version = $request->header('anthropic-version')) {
                $headers['anthropic-version'] = $version;
            }

            if ($beta = $request->header('anthropic-beta')) {
                $headers['anthropic-beta'] = $beta;
            }
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function forwardResponseHeaders(\Illuminate\Http\Client\Response $response): array
    {
        $headersToForward = [
            'content-type',
            'anthropic-ratelimit-requests-limit',
            'anthropic-ratelimit-requests-remaining',
            'anthropic-ratelimit-requests-reset',
            'anthropic-ratelimit-tokens-limit',
            'anthropic-ratelimit-tokens-remaining',
            'anthropic-ratelimit-tokens-reset',
            'retry-after',
            'request-id',
        ];

        $forwarded = [];
        foreach ($headersToForward as $header) {
            $value = $response->header($header);
            if ($value) {
                $forwarded[$header] = $value;
            }
        }

        return $forwarded;
    }

    public function logRequest(
        ApiKey $apiKey,
        AccessToken $accessToken,
        string $model,
        int $statusCode,
        bool $isStream,
        ?int $inputTokens,
        ?int $outputTokens,
        ?int $cacheCreationInputTokens,
        ?int $cacheReadInputTokens,
        Carbon $requestedAt,
        ?string $errorMessage = null,
    ): void {
        $cost = $this->costCalculator->calculate(
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreationInputTokens: $cacheCreationInputTokens,
            cacheReadInputTokens: $cacheReadInputTokens,
        );

        $durationMs = (int) round($requestedAt->diffInMilliseconds(now()));

        RequestLog::create([
            'access_token_id' => $accessToken->id,
            'api_key_id' => $apiKey->id,
            'model' => $model,
            'endpoint' => '/v1/messages',
            'is_stream' => $isStream,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_creation_input_tokens' => $cacheCreationInputTokens,
            'cache_read_input_tokens' => $cacheReadInputTokens,
            'estimated_cost_usd' => $cost,
            'status_code' => $statusCode,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'requested_at' => $requestedAt,
            'created_at' => now(),
        ]);
    }
}
