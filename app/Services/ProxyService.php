<?php

namespace App\Services;

use App\Models\AccessToken;
use App\Models\AnthropicApiKey;
use App\Models\RequestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProxyService
{
    public function __construct(
        private ApiKeyRotator $apiKeyRotator,
        private CostCalculator $costCalculator,
    ) {}

    public function handle(Request $request, AccessToken $accessToken): Response
    {
        $rawBody = $request->getContent();
        $parsed = json_decode($rawBody, true) ?: [];
        $model = $parsed['model'] ?? 'unknown';
        $isStream = $parsed['stream'] ?? false;

        $requestedAt = now();
        $excludeKeyIds = [];
        $lastError = null;

        while (true) {
            $apiKey = $this->apiKeyRotator->selectNext($excludeKeyIds);

            if (! $apiKey) {
                if ($lastError) {
                    return $lastError;
                }

                return response()->json([
                    'type' => 'error',
                    'error' => ['type' => 'api_error', 'message' => 'No API keys available'],
                ], 503);
            }

            $proxyHeaders = $this->buildProxyHeaders($request, $apiKey->api_key);

            if ($isStream) {
                $result = $this->handleStreamingRequest($rawBody, $proxyHeaders, $accessToken, $apiKey, $model, $requestedAt);
            } else {
                $result = $this->handleNonStreamingRequest($rawBody, $proxyHeaders, $accessToken, $apiKey, $model, $requestedAt);
            }

            if ($result instanceof Response && in_array($result->getStatusCode(), [429, 529])) {
                $excludeKeyIds[] = $apiKey->id;
                $lastError = $result;

                continue;
            }

            return $result;
        }
    }

    private function handleNonStreamingRequest(
        string $rawBody,
        array $proxyHeaders,
        AccessToken $accessToken,
        AnthropicApiKey $apiKey,
        string $model,
        Carbon $requestedAt,
    ): Response {
        $response = Http::withHeaders($proxyHeaders)
            ->timeout(300)
            ->withBody($rawBody, 'application/json')
            ->post(config('airoxy.anthropic_api_url'));

        $statusCode = $response->status();
        $responseBody = $response->json() ?? [];

        $inputTokens = $responseBody['usage']['input_tokens'] ?? null;
        $outputTokens = $responseBody['usage']['output_tokens'] ?? null;
        $cacheCreationInputTokens = $responseBody['usage']['cache_creation_input_tokens'] ?? null;
        $cacheReadInputTokens = $responseBody['usage']['cache_read_input_tokens'] ?? null;

        $this->logRequest(
            accessToken: $accessToken,
            apiKey: $apiKey,
            model: $model,
            statusCode: $statusCode,
            isStream: false,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreationInputTokens: $cacheCreationInputTokens,
            cacheReadInputTokens: $cacheReadInputTokens,
            requestedAt: $requestedAt,
            errorMessage: $statusCode >= 400 ? ($responseBody['error']['message'] ?? null) : null,
        );

        $responseHeaders = $this->forwardResponseHeaders($response);

        return response($response->body(), $statusCode)->withHeaders($responseHeaders);
    }

    private function handleStreamingRequest(
        string $rawBody,
        array $proxyHeaders,
        AccessToken $accessToken,
        AnthropicApiKey $apiKey,
        string $model,
        Carbon $requestedAt,
    ): Response {
        $response = Http::withHeaders($proxyHeaders)
            ->timeout(300)
            ->withOptions(['stream' => true])
            ->withBody($rawBody, 'application/json')
            ->post(config('airoxy.anthropic_api_url'));

        $statusCode = $response->status();

        if ($statusCode >= 400) {
            $body = $response->body();
            $responseBody = json_decode($body, true) ?: [];

            $this->logRequest(
                accessToken: $accessToken,
                apiKey: $apiKey,
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

        $responseHeaders = $this->forwardResponseHeaders($response);
        $proxyService = $this;
        $streamBody = $response->getBody();

        return new StreamedResponse(function () use ($streamBody, $proxyService, $accessToken, $apiKey, $model, $requestedAt) {
            $streamHandler = app()->make(StreamHandler::class);

            while (! $streamBody->eof()) {
                $chunk = $streamBody->read(8192);
                if ($chunk === '') {
                    break;
                }

                $streamHandler->parseSSEChunk($chunk);
                echo $chunk;

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            $proxyService->logRequest(
                accessToken: $accessToken,
                apiKey: $apiKey,
                model: $model,
                statusCode: 200,
                isStream: true,
                inputTokens: $streamHandler->getInputTokens(),
                outputTokens: $streamHandler->getOutputTokens(),
                cacheCreationInputTokens: $streamHandler->getCacheCreationInputTokens(),
                cacheReadInputTokens: $streamHandler->getCacheReadInputTokens(),
                requestedAt: $requestedAt,
            );
        }, 200, $responseHeaders);
    }

    /**
     * @return array<string, string>
     */
    private function buildProxyHeaders(Request $request, string $apiKeyValue): array
    {
        $headers = [
            'x-api-key' => $apiKeyValue,
            'anthropic-version' => $request->header('anthropic-version', config('airoxy.anthropic_version')),
        ];

        $beta = $request->header('anthropic-beta');
        if ($beta) {
            $headers['anthropic-beta'] = $beta;
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
        AccessToken $accessToken,
        AnthropicApiKey $apiKey,
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
        ]);
    }
}
