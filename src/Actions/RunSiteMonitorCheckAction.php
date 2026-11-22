<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Contracts\SiteMonitorDomainExpiryClient;
use Capell\SiteMonitor\Contracts\SiteMonitorHttpClient;
use Capell\SiteMonitor\Data\SiteMonitorCheckResultData;
use Capell\SiteMonitor\Enums\SiteMonitorCheckType;
use Capell\SiteMonitor\Enums\SiteMonitorState;
use Capell\SiteMonitor\Exceptions\UnsafeSiteMonitorTargetException;
use Capell\SiteMonitor\Models\SiteMonitorTarget;
use Capell\SiteMonitor\Support\SiteMonitorDeadline;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RunSiteMonitorCheckAction
{
    use AsFake;
    use AsObject;

    public function handle(SiteMonitorTarget $target): SiteMonitorCheckResultData
    {
        if ($target->check_type === SiteMonitorCheckType::HttpStatus && $target->expected_status_minimum > $target->expected_status_maximum) {
            return new SiteMonitorCheckResultData(
                state: SiteMonitorState::Unknown,
                statusCode: null,
                responseMs: null,
                expiresAt: null,
                errorType: 'invalid_status_range',
                errorMessage: __('capell-site-monitor::package.validation.repair_status_range'),
            );
        }

        return match ($target->check_type) {
            SiteMonitorCheckType::HttpStatus => $this->checkHttpStatus($target),
            SiteMonitorCheckType::SslCertificate => $this->checkSslCertificate($target),
            SiteMonitorCheckType::DomainExpiry => $this->checkDomainExpiry($target),
        };
    }

    private function checkHttpStatus(SiteMonitorTarget $target): SiteMonitorCheckResultData
    {
        try {
            GuardSiteMonitorOutboundUrlAction::run($target->url, new SiteMonitorDeadline($target->timeout_ms));
        } catch (UnsafeSiteMonitorTargetException $unsafeSiteMonitorTargetException) {
            return $this->expiryFailure($unsafeSiteMonitorTargetException->errorType, $unsafeSiteMonitorTargetException->getMessage());
        } catch (ConnectionException) {
            return $this->timeoutFailure();
        }

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

        if ($target->max_response_ms !== null && $result->responseMs !== null && $result->responseMs > $target->max_response_ms) {
            return new SiteMonitorCheckResultData(
                state: SiteMonitorState::Failing,
                statusCode: $result->statusCode,
                responseMs: $result->responseMs,
                expiresAt: null,
                errorType: 'response_too_slow',
                errorMessage: sprintf('Response took %dms, over the %dms limit.', $result->responseMs, $target->max_response_ms),
                redirectChain: $result->redirectChain,
                metadata: $result->metadata,
            );
        }

        if (is_string($target->expected_keyword) && $target->expected_keyword !== '' && $result->body !== null && ! str_contains($result->body, $target->expected_keyword)) {
            return new SiteMonitorCheckResultData(
                state: SiteMonitorState::Failing,
                statusCode: $result->statusCode,
                responseMs: $result->responseMs,
                expiresAt: null,
                errorType: 'keyword_missing',
                errorMessage: 'The expected keyword was not found in the response.',
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
        $deadline = new SiteMonitorDeadline($target->timeout_ms);

        try {
            $host = GuardSiteMonitorOutboundUrlAction::run($target->url, $deadline);
            $address = ResolveSiteMonitorSocketAddressAction::run($host, $deadline);
        } catch (UnsafeSiteMonitorTargetException $unsafeSiteMonitorTargetException) {
            return $this->expiryFailure($unsafeSiteMonitorTargetException->errorType, $unsafeSiteMonitorTargetException->getMessage());
        } catch (ConnectionException) {
            return $this->timeoutFailure();
        }

        $port = parse_url(trim($target->url), PHP_URL_PORT);
        $port = is_int($port) && $port > 0 && $port <= 65535 ? $port : 443;

        try {
            $certificate = $this->inspectSslCertificate($host, $address, $port, $deadline);
            $deadline->throwIfExpired();
        } catch (ConnectionException) {
            return $this->timeoutFailure();
        }

        if ($certificate === null) {
            return $this->expiryFailure('ssl_certificate_unavailable', 'SSL certificate expiry could not be resolved.');
        }

        $result = $this->expiryResult($certificate['expiresAt'], $this->integerConfig('capell-site-monitor.ssl_expiry_warning_days', 30));

        if (! $certificate['trusted'] && $result->state !== SiteMonitorState::Failing) {
            return new SiteMonitorCheckResultData(
                state: SiteMonitorState::Failing,
                statusCode: null,
                responseMs: null,
                expiresAt: $certificate['expiresAt'],
                errorType: 'ssl_certificate_untrusted',
                errorMessage: sprintf('The certificate for "%s" is not trusted for this host: its chain or host name did not verify.', $host),
            );
        }

        return $result;
    }

    private function checkDomainExpiry(SiteMonitorTarget $target): SiteMonitorCheckResultData
    {
        try {
            $host = GuardSiteMonitorOutboundUrlAction::run($target->url, new SiteMonitorDeadline($target->timeout_ms));
        } catch (UnsafeSiteMonitorTargetException $unsafeSiteMonitorTargetException) {
            return $this->expiryFailure($unsafeSiteMonitorTargetException->errorType, $unsafeSiteMonitorTargetException->getMessage());
        } catch (ConnectionException) {
            return $this->timeoutFailure();
        }

        if (! is_string(ResolveRdapEndpointAction::run($host))) {
            return $this->expiryFailure(
                'domain_expiry_tld_unsupported',
                sprintf('Domain expiry RDAP is not configured for "%s".', $host),
            );
        }

        $expiresAt = resolve(SiteMonitorDomainExpiryClient::class)->expiresAt($host);

        if (! $expiresAt instanceof CarbonImmutable) {
            return $this->expiryFailure('domain_expiry_unavailable', 'Domain expiry could not be resolved.');
        }

        return $this->expiryResult($expiresAt, $this->integerConfig('capell-site-monitor.domain_expiry_warning_days', 45));
    }

    private function expiryResult(CarbonImmutable $expiresAt, int $warningDays): SiteMonitorCheckResultData
    {
        $now = CarbonImmutable::now('UTC');

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

    private function timeoutFailure(): SiteMonitorCheckResultData
    {
        return $this->expiryFailure('timeout', 'The site monitor network deadline was exceeded.');
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

    /**
     * Connects with full verification first. When that fails the certificate is read once
     * more without verification, so an untrusted, mismatched or expired certificate is
     * reported with its expiry rather than as an unreachable host.
     *
     * @return array{expiresAt: CarbonImmutable, trusted: bool}|null
     */
    private function inspectSslCertificate(string $host, string $address, int $port, SiteMonitorDeadline $deadline): ?array
    {
        $failure = '';
        $expiresAt = ReadSiteMonitorCertificateAction::make()->handle($host, $address, $port, $deadline, true, $failure);

        if ($expiresAt instanceof CarbonImmutable) {
            return ['expiresAt' => $expiresAt, 'trusted' => true];
        }

        // Only a verification failure means the certificate is untrusted. A timeout or reset on
        // the first connect says nothing about the certificate, so it is not retried as such.
        if (! str_contains(strtolower($failure), 'verif') && ! preg_match('/(?:Peer certificate (?:CN=.* did not match expected CN=|SANs? .*did not match|subjectAltName did not match)|Unable to locate peer certificate CN)/i', $failure)) {
            return null;
        }

        $deadline->throwIfExpired();
        $expiresAt = ReadSiteMonitorCertificateAction::make()->handle($host, $address, $port, $deadline, false, $failure);

        return $expiresAt instanceof CarbonImmutable ? ['expiresAt' => $expiresAt, 'trusted' => false] : null;
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
