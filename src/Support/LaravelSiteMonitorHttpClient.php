<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\SiteMonitor\Actions\GuardSiteMonitorOutboundUrlAction;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Exceptions\UnsafeSiteMonitorTargetException;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class LaravelSiteMonitorHttpClient implements SiteMonitorHttpClient
{
    public function check(SiteMonitorTarget $target): SiteMonitorCheckResultData
    {
        $startedAt = microtime(true);
        $url = $target->url;
        $redirectChain = [];

        try {
            GuardSiteMonitorOutboundUrlAction::run($url);

            $response = $this->requestFollowingSafeRedirects($url, $target, $redirectChain);
        } catch (UnsafeSiteMonitorTargetException $exception) {
            return $this->failure($exception->errorType, $exception->getMessage(), $startedAt, $redirectChain);
        } catch (ConnectionException $exception) {
            return $this->failure('connection_error', $exception->getMessage(), $startedAt, $redirectChain);
        } catch (Throwable $exception) {
            return $this->failure('request_error', $exception->getMessage(), $startedAt, $redirectChain);
        }

        $statusCode = $response->status();
        $state = $statusCode >= $target->expected_status_minimum && $statusCode <= $target->expected_status_maximum
            ? SiteMonitorState::Passing
            : SiteMonitorState::Failing;

        $errorType = $state === SiteMonitorState::Failing ? 'unexpected_status_code' : null;
        $errorMessage = $state === SiteMonitorState::Failing
            ? sprintf('Expected HTTP %d-%d, received %d.', $target->expected_status_minimum, $target->expected_status_maximum, $statusCode)
            : null;

        return new SiteMonitorCheckResultData(
            state: $state,
            statusCode: $statusCode,
            responseMs: $this->responseMs($startedAt),
            expiresAt: null,
            errorType: $errorType,
            errorMessage: $errorMessage,
            redirectChain: $redirectChain,
        );
    }

    /**
     * @param  list<string>  $redirectChain
     */
    private function requestFollowingSafeRedirects(string $url, SiteMonitorTarget $target, array &$redirectChain): Response
    {
        $maxRedirects = $this->integerConfig('capell-site-monitor.max_redirects', 5);

        for ($redirectCount = 0; $redirectCount <= $maxRedirects; $redirectCount++) {
            $response = Http::timeout(max(1, (int) ceil($target->timeout_ms / 1000)))
                ->connectTimeout(max(1, (int) ceil($target->timeout_ms / 1000)))
                ->withOptions(['allow_redirects' => false])
                ->get($url);

            if (! $this->isRedirectStatus($response->status())) {
                return $response;
            }

            $location = $response->header('Location');

            if (! is_string($location) || trim($location) === '') {
                return $response;
            }

            if ($redirectCount === $maxRedirects) {
                throw new UnsafeSiteMonitorTargetException('too_many_redirects', 'Target exceeded the maximum safe redirect count.');
            }

            $url = $this->resolveRedirectUrl($url, $location);
            GuardSiteMonitorOutboundUrlAction::run($url);
            $redirectChain[] = $this->redactUrl($url);
        }

        throw new UnsafeSiteMonitorTargetException('too_many_redirects', 'Target exceeded the maximum safe redirect count.');
    }

    /**
     * @param  list<string>  $redirectChain
     */
    private function failure(string $type, string $message, float $startedAt, array $redirectChain = []): SiteMonitorCheckResultData
    {
        return new SiteMonitorCheckResultData(
            state: SiteMonitorState::Failing,
            statusCode: null,
            responseMs: $this->responseMs($startedAt),
            expiresAt: null,
            errorType: $type,
            errorMessage: $this->redactUrl($message),
            redirectChain: $redirectChain,
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

    private function isRedirectStatus(int $statusCode): bool
    {
        return $statusCode >= 300 && $statusCode <= 399;
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        $location = trim($location);

        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $currentScheme = (string) parse_url($currentUrl, PHP_URL_SCHEME);
        $currentHost = (string) parse_url($currentUrl, PHP_URL_HOST);
        $currentPort = parse_url($currentUrl, PHP_URL_PORT);
        $authority = $currentHost . (is_int($currentPort) ? ':' . $currentPort : '');

        if (str_starts_with($location, '//')) {
            return $currentScheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return sprintf('%s://%s%s', $currentScheme, $authority, $location);
        }

        $currentPath = (string) (parse_url($currentUrl, PHP_URL_PATH) ?: '/');
        $basePath = rtrim(str_contains($currentPath, '/') ? dirname($currentPath) : '/', '/');

        return sprintf('%s://%s%s/%s', $currentScheme, $authority, $basePath === '' ? '' : $basePath, $location);
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
