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
use InvalidArgumentException;
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
            $endpoint = $this->endpoint($url);
            $response = Http::timeout(max(1, (int) ceil($target->timeout_ms / 1000)))
                ->connectTimeout(max(1, (int) ceil($target->timeout_ms / 1000)))
                ->withOptions(['allow_redirects' => false])
                ->withOptions($this->requestOptions($endpoint))
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
        $redacted = preg_replace('/(https?:\/\/)[^\s\/@]+:[^\s\/@]+@/i', '$1[redacted]@', $value) ?? $value;
        $redacted = preg_replace('/([?&][^=]+)=([^&\s]+)/', '$1=[redacted]', $redacted) ?? $redacted;

        return preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=:-]+/i', '$1 [redacted]', $redacted) ?? $redacted;
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

    /**
     * @return array{host: string, port: int, address: string}
     */
    private function endpoint(string $url): array
    {
        $parts = parse_url($url);

        throw_if(! is_array($parts), InvalidArgumentException::class, 'Target URL could not be parsed.');

        $scheme = is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : null;
        $host = GuardSiteMonitorOutboundUrlAction::run($url);

        return [
            'host' => $host,
            'port' => $this->port($parts, $scheme),
            'address' => $this->resolvedAddress($host),
        ];
    }

    /**
     * @param  array{host: string, port: int, address: string}  $endpoint
     * @return array<string, mixed>
     */
    private function requestOptions(array $endpoint): array
    {
        throw_unless(defined('CURLOPT_RESOLVE'), InvalidArgumentException::class, 'Site monitor HTTP checks require cURL host pinning support.');

        return [
            'curl' => [
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $endpoint['host'], $endpoint['port'], $endpoint['address'])],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function port(array $parts, ?string $scheme): int
    {
        $port = $parts['port'] ?? null;

        if (is_int($port) && $port > 0 && $port <= 65535) {
            return $port;
        }

        return $scheme === 'http' ? 80 : 443;
    }

    private function resolvedAddress(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->guardResolvedAddress($host);

            return $host;
        }

        $addresses = gethostbynamel($host);

        if (is_array($addresses) && $addresses !== []) {
            $address = $addresses[0];

            $this->guardResolvedAddress($address);

            return $address;
        }

        throw new UnsafeSiteMonitorTargetException('target_host_unresolvable', 'Target host could not be resolved before the outbound check.');
    }

    private function guardResolvedAddress(string $address): void
    {
        if (! app()->isProduction() && (bool) config('capell-site-monitor.allow_private_targets', false)) {
            return;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new UnsafeSiteMonitorTargetException('unsafe_target_address', 'Target host resolves to a private or reserved network address.');
        }
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
