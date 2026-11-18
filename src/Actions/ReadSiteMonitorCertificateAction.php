<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Actions;

use Capell\SiteMonitor\Support\SiteMonitorDeadline;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use OpenSSLCertificate;
use RuntimeException;
use Throwable;

class ReadSiteMonitorCertificateAction
{
    use AsFake;
    use AsObject;

    public function handle(string $host, string $address, int $port, SiteMonitorDeadline $deadline, bool $verify, string &$failure = ''): ?CarbonImmutable
    {
        $deadline->throwIfExpired();

        $context = stream_context_create([
            'ssl' => [
                ...((stream_context_get_options(stream_context_get_default())['ssl'] ?? [])),
                'capture_peer_cert' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'allow_self_signed' => false,
            ],
        ]);

        // The OpenSSL reason is raised as a separate warning that the final connect warning overwrites.
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $client = stream_socket_client(
                sprintf('tcp://%s:%d', str_contains($address, ':') ? '[' . $address . ']' : $address, $port),
                $errorCode,
                $errorMessage,
                $deadline->remainingSeconds(),
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                $context,
            );

            if (! is_resource($client)) {
                $failure = $errorMessage . ' ' . implode(' ', $warnings);

                return null;
            }

            if (! stream_set_blocking($client, false)) {
                throw new RuntimeException('Could not make the site monitor socket non-blocking.');
            }

            $this->waitForSocket($client, $deadline, connecting: true);

            do {
                $deadline->throwIfExpired();
                $crypto = stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $deadline->throwIfExpired();

                if ($crypto === 0) {
                    $this->waitForSocket($client, $deadline);
                }
            } while ($crypto === 0);

            if ($crypto !== true) {
                $failure = $errorMessage . ' ' . implode(' ', $warnings);

                return null;
            }

            $parameters = stream_context_get_params($client);
        } catch (Throwable $throwable) {
            if ($throwable instanceof ConnectionException) {
                throw $throwable;
            }

            $failure = $throwable->getMessage();

            return null;
        } finally {
            if (isset($client) && is_resource($client)) {
                fclose($client);
            }

            restore_error_handler();
        }

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

    /**
     * @param  resource  $client
     */
    private function waitForSocket($client, SiteMonitorDeadline $deadline, bool $connecting = false): void
    {
        do {
            $remaining = $deadline->remainingSeconds();
            // PHP does not expose TLS WANT_READ/WANT_WRITE. Wait for reads in short slices
            // so a handshake needing a write is also retried, without spinning on writable TCP.
            $wait = $connecting ? $remaining : min($remaining, 0.05);
            $read = $connecting ? null : [$client];
            $write = $connecting ? [$client] : null;
            $except = [$client];
            $ready = stream_select($read, $write, $except, (int) $wait, (int) (($wait - (int) $wait) * 1000000));
            $deadline->throwIfExpired();

            if ($ready === false) {
                throw new RuntimeException('Could not wait for the site monitor socket.');
            }
        } while ($connecting && $ready === 0);
    }
}
