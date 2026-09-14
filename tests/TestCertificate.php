<?php

declare(strict_types=1);

namespace Tests;

/**
 * Generates a throwaway self-signed certificate/key pair for tests that need
 * a real TLS handshake. Written to `sys_get_temp_dir()` and removed via
 * {@see self::cleanup()}.
 *
 * @package Tests
 */
final class TestCertificate
{
    public readonly string $certFile;

    public readonly string $keyFile;

    public function __construct()
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            throw new \RuntimeException('Could not generate a test private key: ' . (openssl_error_string() ?: 'unknown error'));
        }

        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $privateKey);

        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new \RuntimeException('Could not generate a test CSR: ' . (openssl_error_string() ?: 'unknown error'));
        }

        $certificate = openssl_csr_sign($csr, null, $privateKey, 1);

        if ($certificate === false) {
            throw new \RuntimeException('Could not sign the test certificate: ' . (openssl_error_string() ?: 'unknown error'));
        }

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($privateKey, $keyPem);

        $certFile = tempnam(sys_get_temp_dir(), 'ezphp-tls-cert-');
        $keyFile = tempnam(sys_get_temp_dir(), 'ezphp-tls-key-');

        if ($certFile === false || $keyFile === false) {
            throw new \RuntimeException('Could not allocate temp files for the test certificate.');
        }

        file_put_contents($certFile, (string) $certificatePem);
        file_put_contents($keyFile, (string) $keyPem);

        $this->certFile = $certFile;
        $this->keyFile = $keyFile;
    }

    public function cleanup(): void
    {
        @unlink($this->certFile);
        @unlink($this->keyFile);
    }
}
