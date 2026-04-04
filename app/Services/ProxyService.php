<?php

namespace App\Services;

use App\Models\AccessToken;
use App\Models\ApiKey;
use App\Models\RequestLog;
use GuzzleHttp\Client;
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
            $forwardBody = $this->prepareRequestBody($rawBody, $parsed, $accessToken->token);

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
        // Use Guzzle directly to avoid Laravel HTTP client buffering the stream
        $client = new Client;
        $guzzleResponse = $client->post(config('airoxy.anthropic_api_url'), [
            'headers' => $proxyHeaders,
            'body' => $rawBody,
            'stream' => true,
            'http_errors' => false,
            'connect_timeout' => 10,
            'read_timeout' => 300,
        ]);

        $statusCode = $guzzleResponse->getStatusCode();

        if ($statusCode >= 400) {
            $body = $guzzleResponse->getBody()->getContents();
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

            return response($body, $statusCode, [
                'Content-Type' => 'application/json',
            ]);
        }

        $responseHeaders = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
        $proxyService = $this;
        $streamBody = $guzzleResponse->getBody();

        return new StreamedResponse(function () use ($streamBody, $proxyService, $apiKey, $accessToken, $model, $requestedAt) {
            // Kill ALL output buffers — critical for real-time SSE
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Disable implicit flush buffering
            @ini_set('output_buffering', '0');
            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');

            $streamHandler = app()->make(StreamHandler::class);

            $errorMessage = null;

            try {
                while (! $streamBody->eof()) {
                    if (connection_aborted()) {
                        $errorMessage = 'Client disconnected';
                        break;
                    }

                    $chunk = $streamBody->read(1);
                    if ($chunk === '') {
                        break;
                    }

                    // Read remaining available data without blocking
                    $remaining = $streamBody->read(65536);
                    if ($remaining !== '') {
                        $chunk .= $remaining;
                    }

                    echo $chunk;
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
     * For OAuth tokens, inject the required Claude Code system prompt if not present.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function prepareRequestBody(string $rawBody, array $parsed, string $tokenValue): string
    {
        if (! str_starts_with($tokenValue, 'sk-ant-oat')) {
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
    private function buildProxyHeaders(Request $request, string $tokenValue): array
    {
        $headers['content-type'] = 'application/json';
        $headers['anthropic-version'] = $request->header('anthropic-version', config('airoxy.anthropic_version'));
        $headers['authorization'] = 'Bearer '.$tokenValue;
        $requiredBeta = 'claude-code-20250219,oauth-2025-04-20';
        $clientBeta = $request->header('anthropic-beta');
        if ($clientBeta) {
            // Merge client's beta flags with required OAuth flags, avoid duplicates
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
