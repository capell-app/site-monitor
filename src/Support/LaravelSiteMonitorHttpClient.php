<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

use Capell\Core\Exceptions\OutboundRequestRefusedException;
use Capell\Core\Support\Http\OutboundHttp;
use Capell\Core\Support\Http\OutboundHttpPolicy;
use Capell\SiteMonitor\Actions\GuardSiteMonitorOutboundUrlAction;
use Capell\SiteMonitor\Actions\ResolveSiteMonitorSocketAddressAction;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Exceptions\UnsafeSiteMonitorTargetException;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Override;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class LaravelSiteMonitorHttpClient implements SiteMonitorHttpClient
{
    /**
     * A status check only needs the response line, but a monitored page may be
     * large; refuse anything beyond this so one target cannot exhaust memory.
     */
    private const int MAX_RESPONSE_BYTES = 25 * 1024 * 1024;

    #[Override]
    public function check(SiteMonitorTarget $target): SiteMonitorCheckResultData
    {
        if ($target->expected_status_minimum > $target->expected_status_maximum) {
            return new SiteMonitorCheckResultData(
                state: SiteMonitorState::Unknown,
                statusCode: null,
                responseMs: null,
                expiresAt: null,
                errorType: 'invalid_status_range',
                errorMessage: __('capell-site-monitor::package.validation.repair_status_range'),
            );
        }

        $deadline = new SiteMonitorDeadline($target->timeout_ms);
        $startedAt = microtime(true);
        $url = $target->url;
        $redirectChain = [];

        try {
            GuardSiteMonitorOutboundUrlAction::run($url);

            $response = $this->requestFollowingSafeRedirects($url, $redirectChain, $deadline);
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
    private function requestFollowingSafeRedirects(string $url, array &$redirectChain, SiteMonitorDeadline $deadline): Response
    {
        $maxRedirects = $this->integerConfig('capell-site-monitor.max_redirects', 5);

        for ($redirectCount = 0; $redirectCount <= $maxRedirects; $redirectCount++) {
            $response = $this->guardedRequest($url, $deadline);

            $deadline->throwIfExpired();

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

        return sprintf('%s://%s%s/%s', $currentScheme, $authority, $basePath, $location);
    }

    /**
     * Sends one hop through Core's guarded client: it resolves the host through
     * Core, refuses unless every address is public, pins the connection to the
     * checked address and follows no redirects, so each hop is re-guarded here.
     */
    private function guardedRequest(string $url, SiteMonitorDeadline $deadline): Response
    {
        $remaining = $deadline->remainingSeconds();
        $policySeconds = max(1, (int) ceil($remaining));
        $port = parse_url($url, PHP_URL_PORT);
        $port = is_int($port) ? $port : (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443);

        if (GuardSiteMonitorOutboundUrlAction::privateTargetsAreAllowed()) {
            return $this->privateTargetRequest($url, $port, $remaining);
        }

        try {
            return OutboundHttp::guarded(new OutboundHttpPolicy(
                schemes: ['https', 'http'],
                ports: [$port],
                maxBytes: self::MAX_RESPONSE_BYTES,
                connectTimeoutSeconds: $policySeconds,
                timeoutSeconds: $policySeconds,
            ))
                // The shared deadline is fractional; the policy only takes whole seconds.
                ->timeout($remaining)
                ->connectTimeout($remaining)
                ->get($url);
        } catch (OutboundRequestRefusedException $exception) {
            throw SiteMonitorOutboundRefusal::toUnsafeTarget($exception);
        }
    }

    /**
     * Operators who enable allow_private_targets (never in production) monitor
     * internal sites on purpose, which Core's guarded client always refuses. This
     * hop is pinned to the site monitor resolver's address instead, follows no
     * redirects and keeps the same download limit.
     */
    private function privateTargetRequest(string $url, int $port, float $remaining): Response
    {
        $host = GuardSiteMonitorOutboundUrlAction::run($url);
        $address = ResolveSiteMonitorSocketAddressAction::run($host);
        $pinnedAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;

        try {
            $response = Http::timeout($remaining)
                ->connectTimeout($remaining)
                ->withoutRedirecting()
                ->withOptions([
                    'curl' => [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $pinnedAddress)]],
                    'on_headers' => static function (ResponseInterface $headers): void {
                        $length = $headers->getHeaderLine('Content-Length');

                        if (ctype_digit($length) && (int) $length > self::MAX_RESPONSE_BYTES) {
                            throw new UnsafeSiteMonitorTargetException('response_too_large', 'Target response exceeded the download limit.');
                        }
                    },
                ])
                ->get($url);
        } catch (Throwable $exception) {
            // Guzzle wraps an exception thrown from on_headers; surface the size refusal.
            for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
                throw_if($current instanceof UnsafeSiteMonitorTargetException, $current);
            }

            throw $exception;
        }

        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new UnsafeSiteMonitorTargetException('response_too_large', 'Target response exceeded the download limit.');
        }

        return $response;
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
