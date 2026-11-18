<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Tests\Fixtures;

use RuntimeException;

/**
 * A loopback TLS server with a freshly generated self-signed certificate, run in a child
 * process so the SSL check performs a real handshake against it.
 */
final class SelfSignedTlsServer
{
    private bool $accepted = false;

    /**
     * @param  resource  $process
     * @param  resource  $output
     */
    private function __construct(
        private $process,
        public readonly int $port,
        public readonly string $certificatePath,
        private $output,
    ) {}

    public static function start(int $connections, int $validDays = 90, bool $sanOnly = false, bool $stallHandshake = false): self
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        throw_if($key === false, RuntimeException::class, 'Could not generate a TLS test key.');

        $configPath = tempnam(sys_get_temp_dir(), 'sm-openssl-');
        throw_if($configPath === false, RuntimeException::class, 'Could not create a TLS test configuration.');

        try {
            file_put_contents($configPath, "[req]\ndistinguished_name = dn\n[dn]\n[monitor_san]\nsubjectAltName = DNS:monitor.invalid\nbasicConstraints = CA:TRUE\n");
            $options = $sanOnly ? ['config' => $configPath, 'digest_alg' => 'sha256', 'x509_extensions' => 'monitor_san'] : ['digest_alg' => 'sha256'];
            $request = openssl_csr_new($sanOnly ? ['organizationName' => 'Site Monitor test'] : ['commonName' => 'monitor.invalid'], $key, $options);
            throw_if($request === false || $request === true, RuntimeException::class, 'Could not generate a TLS test request.');

            $certificate = openssl_csr_sign($request, null, $key, $validDays, $options);
            throw_if($certificate === false, RuntimeException::class, 'Could not sign the TLS test certificate.');
        } finally {
            unlink($configPath);
        }

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        $path = tempnam(sys_get_temp_dir(), 'sm-tls-');
        throw_if($path === false, RuntimeException::class, 'Could not create a TLS test certificate file.');
        file_put_contents($path, $certificatePem . $keyPem);

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/serve-self-signed-tls.php', $path, (string) $connections, $stallHandshake ? 'stall' : 'tls'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        throw_if($process === false, RuntimeException::class, 'Could not start the TLS fixture server.');

        $port = (int) trim((string) fgets($pipes[1]));
        throw_if($port === 0, RuntimeException::class, 'The TLS fixture server did not start.');

        stream_set_blocking($pipes[1], false);

        return new self($process, $port, $path, $pipes[1]);
    }

    public function hasAcceptedConnection(): bool
    {
        $this->accepted = $this->accepted || str_contains((string) stream_get_contents($this->output), 'accepted');

        return $this->accepted;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        fclose($this->output);
        proc_close($this->process);
        @unlink($this->certificatePath);
    }
}
