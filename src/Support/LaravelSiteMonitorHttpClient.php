<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class LaravelSiteMonitorHttpClient implements SiteMonitorHttpClient
{
    public function check(SiteMonitorTarget $target): SiteMonitorCheckResultData
    {
        $startedAt = microtime(true);

        try {
            $response = Http::timeout(max(1, (int) ceil($target->timeout_ms / 1000)))
                ->connectTimeout(max(1, (int) ceil($target->timeout_ms / 1000)))
                ->withOptions(['allow_redirects' => ['track_redirects' => true]])
                ->get($target->url);
        } catch (ConnectionException $exception) {
            return $this->failure('connection_error', $exception->getMessage(), $startedAt);
        } catch (Throwable $exception) {
            return $this->failure('request_error', $exception->getMessage(), $startedAt);
        }

        $statusCode = $response->status();
        $state = $statusCode >= $target->expected_status_minimum && $statusCode <= $target->expected_status_maximum
            ? SiteMonitorState::Passing
            : SiteMonitorState::Failing;

        $errorType = $state === SiteMonitorState::Failing ? 'unexpected_status_code' : null;
        $errorMessage = $state === SiteMonitorState::Failing
            ? sprintf('Expected HTTP %d-%d, received %d.', $target->expected_status_minimum, $target->expected_status_maximum, $statusCode)
            : null;

        $redirectUrl = $response->handlerStats()['redirect_url'] ?? null;

        return new SiteMonitorCheckResultData(
            state: $state,
            statusCode: $statusCode,
            responseMs: $this->responseMs($startedAt),
            expiresAt: null,
            errorType: $errorType,
            errorMessage: $errorMessage,
            redirectChain: is_string($redirectUrl) && $redirectUrl !== '' ? [$this->redactUrl($redirectUrl)] : [],
        );
    }

    private function failure(string $type, string $message, float $startedAt): SiteMonitorCheckResultData
    {
        return new SiteMonitorCheckResultData(
            state: SiteMonitorState::Failing,
            statusCode: null,
            responseMs: $this->responseMs($startedAt),
            expiresAt: null,
            errorType: $type,
            errorMessage: $this->redactUrl($message),
        );
    }

    private function responseMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function redactUrl(string $value): string
    {
        return preg_replace('/([?&][^=]+)=([^&\s]+)/', '$1=[redacted]', $value) ?? $value;
    }
}
