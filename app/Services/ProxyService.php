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

            $proxyHeaders = $this->buildProxyHeaders($request, $accessToken->token);

            if ($isStream) {
                $result = $this->handleStreamingRequest($rawBody, $proxyHeaders, $apiKey, $accessToken, $model, $requestedAt);
            } else {
                $result = $this->handleNonStreamingRequest($rawBody, $proxyHeaders, $apiKey, $accessToken, $model, $requestedAt);
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
            ->withBody($rawBody, 'application/json')
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
        $response = Http::withHeaders($proxyHeaders)
            ->connectTimeout(10)
            ->withOptions([
                'stream' => true,
                'read_timeout' => 300,
            ])
            ->withBody($rawBody, 'application/json')
            ->post(config('airoxy.anthropic_api_url'));

        $statusCode = $response->status();

        if ($statusCode >= 400) {
            $body = $response->body();
            $responseBody = json_decode($body, true) ?: [];

            $this->logRequest(
                apiKey: $apiKey,
                accessToken: $accessToken,
                model: $model,
                statusCode: $statusCode,
                isStream: true,
                inputTokens: null,
                outputTokens: null,
                cacheCreationInputTokens: null,
                cacheReadInputTokens: null,
                requestedAt: $requestedAt,
                errorMessage: $responseBody['error']['message'] ?? null,
            );

            $responseHeaders = $this->forwardResponseHeaders($response);

            return response($body, $statusCode)->withHeaders($responseHeaders);
        }

        $responseHeaders = array_merge($this->forwardResponseHeaders($response), [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
        $proxyService = $this;
        $streamBody = $response->getBody();

        return new StreamedResponse(function () use ($streamBody, $proxyService, $apiKey, $accessToken, $model, $requestedAt) {
            $streamHandler = app()->make(StreamHandler::class);

            $errorMessage = null;

            try {
                while (! $streamBody->eof()) {
                    if (connection_aborted()) {
                        $errorMessage = 'Client disconnected';
                        break;
                    }

                    $chunk = $streamBody->read(8192);
                    if ($chunk === '') {
                        break;
                    }

                    echo $chunk;

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();

                    $streamHandler->parseSSEChunk($chunk);
                }
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
            }

            $proxyService->logRequest(
                apiKey: $apiKey,
                accessToken: $accessToken,
                model: $model,
                statusCode: 200,
                isStream: true,
                inputTokens: $streamHandler->getInputTokens(),
                outputTokens: $streamHandler->getOutputTokens(),
                cacheCreationInputTokens: $streamHandler->getCacheCreationInputTokens(),
                cacheReadInputTokens: $streamHandler->getCacheReadInputTokens(),
                requestedAt: $requestedAt,
                errorMessage: $errorMessage,
            );
        }, 200, $responseHeaders);
    }

    /**
     * @return array<string, string>
     */
    private function buildProxyHeaders(Request $request, string $tokenValue): array
    {
        $headers['content-type'] = 'application/json';
        $headers['anthropic-version'] = $request->header('anthropic-version', config('airoxy.anthropic_version'));
        $headers['authorization'] = 'Bearer '.$tokenValue;
        $headers['anthropic-beta'] = 'claude-code-20250219,oauth-2025-04-20';
        $headers['user-agent'] = 'claude-cli/2.1.62';
        $headers['x-app'] = 'cli';

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
