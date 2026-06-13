<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;
use OpenSSLCertificate;
use Throwable;

final class RunSiteMonitorCheckAction
{
    use AsAction;

    public function handle(SiteMonitorTarget $target): SiteMonitorCheckResultData
    {
        return match ($target->check_type) {
            SiteMonitorCheckType::HttpStatus => $this->checkHttpStatus($target),
            SiteMonitorCheckType::SslCertificate => $this->checkSslCertificate($target),
            SiteMonitorCheckType::DomainExpiry => $this->checkDomainExpiry($target),
        };
    }

    private function checkHttpStatus(SiteMonitorTarget $target): SiteMonitorCheckResultData
    {
        $result = resolve(SiteMonitorHttpClient::class)->check($target);

        if ($result->statusCode === null) {
            return $result;
        }

        if ($result->statusCode < $target->expected_status_minimum || $result->statusCode > $target->expected_status_maximum) {
            return new SiteMonitorCheckResultData(
                state: SiteMonitorState::Failing,
                statusCode: $result->statusCode,
                responseMs: $result->responseMs,
                expiresAt: null,
                errorType: 'unexpected_status_code',
                errorMessage: sprintf('Expected HTTP %d-%d, received %d.', $target->expected_status_minimum, $target->expected_status_maximum, $result->statusCode),
                redirectChain: $result->redirectChain,
                metadata: $result->metadata,
            );
        }

        if ($result->responseMs !== null && $result->responseMs > $this->integerConfig('capell-site-monitor.warning_response_ms', 1500)) {
            return new SiteMonitorCheckResultData(
                state: SiteMonitorState::Warning,
                statusCode: $result->statusCode,
                responseMs: $result->responseMs,
                expiresAt: null,
                errorType: 'slow_response',
                errorMessage: sprintf('Response took %dms.', $result->responseMs),
                redirectChain: $result->redirectChain,
                metadata: $result->metadata,
            );
        }

        return new SiteMonitorCheckResultData(
            state: SiteMonitorState::Passing,
            statusCode: $result->statusCode,
            responseMs: $result->responseMs,
            expiresAt: null,
            errorType: null,
            errorMessage: null,
            redirectChain: $result->redirectChain,
            metadata: $result->metadata,
        );
    }

    private function checkSslCertificate(SiteMonitorTarget $target): SiteMonitorCheckResultData
    {
        $host = $this->hostFor($target->url);

        if ($host === null) {
            return $this->expiryFailure('invalid_url', 'Target URL does not contain a host.');
        }

        $expiresAt = $this->sslCertificateExpiresAt($host, $target->timeout_ms);

        if (! $expiresAt instanceof CarbonImmutable) {
            return $this->expiryFailure('ssl_certificate_unavailable', 'SSL certificate expiry could not be resolved.');
        }

        return $this->expiryResult($expiresAt, $this->integerConfig('capell-site-monitor.ssl_expiry_warning_days', 30));
    }

    private function checkDomainExpiry(SiteMonitorTarget $target): SiteMonitorCheckResultData
    {
        $host = $this->hostFor($target->url);

        if ($host === null) {
            return $this->expiryFailure('invalid_url', 'Target URL does not contain a host.');
        }

        $expiresAt = resolve(SiteMonitorDomainExpiryClient::class)->expiresAt($host);

        if (! $expiresAt instanceof CarbonImmutable) {
            return $this->expiryFailure('domain_expiry_unavailable', 'Domain expiry could not be resolved.');
        }

        return $this->expiryResult($expiresAt, $this->integerConfig('capell-site-monitor.domain_expiry_warning_days', 45));
    }

    private function expiryResult(CarbonImmutable $expiresAt, int $warningDays): SiteMonitorCheckResultData
    {
        $now = CarbonImmutable::now();

        if ($expiresAt->lessThanOrEqualTo($now)) {
            return new SiteMonitorCheckResultData(
                state: SiteMonitorState::Failing,
                statusCode: null,
                responseMs: null,
                expiresAt: $expiresAt,
                errorType: 'expired',
                errorMessage: 'Expiry date is in the past.',
            );
        }

        if ($expiresAt->lessThanOrEqualTo($now->addDays($warningDays))) {
            return new SiteMonitorCheckResultData(
                state: SiteMonitorState::Warning,
                statusCode: null,
                responseMs: null,
                expiresAt: $expiresAt,
                errorType: 'expires_soon',
                errorMessage: sprintf('Expires within %d days.', $warningDays),
            );
        }

        return new SiteMonitorCheckResultData(
            state: SiteMonitorState::Passing,
            statusCode: null,
            responseMs: null,
            expiresAt: $expiresAt,
            errorType: null,
            errorMessage: null,
        );
    }

    private function expiryFailure(string $type, string $message): SiteMonitorCheckResultData
    {
        return new SiteMonitorCheckResultData(
            state: SiteMonitorState::Failing,
            statusCode: null,
            responseMs: null,
            expiresAt: null,
            errorType: $type,
            errorMessage: $message,
        );
    }

    private function hostFor(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function sslCertificateExpiresAt(string $host, int $timeoutMs): ?CarbonImmutable
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        try {
            $client = @stream_socket_client(
                sprintf('ssl://%s:443', $host),
                $errorCode,
                $errorMessage,
                max(1, (int) ceil($timeoutMs / 1000)),
                STREAM_CLIENT_CONNECT,
                $context,
            );
        } catch (Throwable) {
            return null;
        }

        if (! is_resource($client)) {
            return null;
        }

        $parameters = stream_context_get_params($client);
        fclose($client);

        $options = $parameters['options'];
        $sslOptions = $options['ssl'] ?? null;

        if (! is_array($sslOptions)) {
            return null;
        }

        $certificate = $sslOptions['peer_certificate'] ?? null;

        if (! $certificate instanceof OpenSSLCertificate && ! is_string($certificate)) {
            return null;
        }

        $parsed = openssl_x509_parse($certificate);

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t']) || ! is_numeric($parsed['validTo_time_t'])) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t']);
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
