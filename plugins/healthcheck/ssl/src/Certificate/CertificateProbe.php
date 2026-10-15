<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Ssl
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Ssl\Certificate;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Reads the TLS certificate the site's own host presents. The only class in this plugin that uses the network.
 *
 * @since  __DEPLOY_VERSION__
 */
final class CertificateProbe
{
    /**
     * Derive the host, port and scheme to check from the site root URL.
     *
     * @param   string  $rootUrl  The site root URL, as from Uri::root()
     *
     * @return  array|null  ['https' => bool, 'host' => string, 'port' => int], or null without a host
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function target(string $rootUrl): ?array
    {
        $parts = parse_url($rootUrl);

        if (!\is_array($parts) || empty($parts['host'])) {
            return null;
        }

        return [
            'https' => strtolower($parts['scheme'] ?? '') === 'https',
            'host'  => strtolower(trim($parts['host'], '[]')),
            'port'  => isset($parts['port']) ? (int) $parts['port'] : 443,
        ];
    }

    /**
     * Read validity dates and issuer from a certificate.
     *
     * @param   \OpenSSLCertificate|string  $certificate  Certificate object or PEM string
     *
     * @return  array|null  ['validFrom' => int, 'validTo' => int, 'issuer' => string], or null if unreadable
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function parse(\OpenSSLCertificate|string $certificate): ?array
    {
        set_error_handler(static fn (): bool => true);

        try {
            $info = openssl_x509_parse($certificate);
        } finally {
            restore_error_handler();
        }

        if (!\is_array($info) || !isset($info['validFrom_time_t'], $info['validTo_time_t'])) {
            return null;
        }

        $issuer = $info['issuer']['O'] ?? $info['issuer']['CN'] ?? '';

        return [
            'validFrom' => (int) $info['validFrom_time_t'],
            'validTo'   => (int) $info['validTo_time_t'],
            'issuer'    => \is_array($issuer) ? implode(', ', $issuer) : (string) $issuer,
        ];
    }

    /**
     * Turn a PHP stream warning into a one-line reason.
     *
     * @param   string  $message  Warning text
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function cleanReason(string $message): string
    {
        $message = preg_replace('/^\s*\w+\(\):\s*/', '', $message);

        return trim(preg_replace('/\s+/', ' ', $message));
    }

    /**
     * A probe result for a certificate that could not be read.
     *
     * @param   string   $reason     Why
     * @param   integer  $checkedAt  Unix time of the attempt
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function failure(string $reason, int $checkedAt): array
    {
        return ['ok' => false, 'trusted' => false, 'reason' => $reason, 'validFrom' => null, 'validTo' => null, 'issuer' => '', 'checkedAt' => $checkedAt];
    }

    /**
     * Connect to the host, complete a TLS handshake and read its certificate.
     * Verification failures are retried once without verification so the expiry date can still be reported.
     *
     * @param   string   $host     Host name or IP address
     * @param   integer  $port     TCP port
     * @param   integer  $timeout  Seconds for the connect and for the handshake
     *
     * @return  array  ['ok', 'trusted', 'reason', 'validFrom', 'validTo', 'issuer', 'checkedAt']
     *
     * @since   __DEPLOY_VERSION__
     */
    public function probe(string $host, int $port, int $timeout): array
    {
        $checkedAt = time();

        if (!\extension_loaded('openssl')) {
            return self::failure('The PHP OpenSSL extension is not available.', $checkedAt);
        }

        $verified = $this->handshake($host, $port, $timeout, true);

        if ($verified['certificate'] !== null) {
            return $this->result($verified['certificate'], true, '', $checkedAt);
        }

        // Only a failed verification is worth a second attempt; a refused or timed-out connection would just fail again.
        if (!$verified['connected'] || !preg_match('/certificate|did not match/i', $verified['error'])) {
            return self::failure(self::cleanReason($verified['error']), $checkedAt);
        }

        $unverified = $this->handshake($host, $port, $timeout, false);

        if ($unverified['certificate'] === null) {
            return self::failure(self::cleanReason($verified['error']), $checkedAt);
        }

        return $this->result($unverified['certificate'], false, self::cleanReason($verified['error']), $checkedAt);
    }

    /**
     * Build a probe result from a captured certificate.
     *
     * @param   \OpenSSLCertificate  $certificate  The peer certificate
     * @param   boolean              $trusted      Whether verification passed
     * @param   string               $reason       Why it is not trusted
     * @param   integer              $checkedAt    Unix time of the attempt
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function result(\OpenSSLCertificate $certificate, bool $trusted, string $reason, int $checkedAt): array
    {
        $parsed = self::parse($certificate);

        if ($parsed === null) {
            return self::failure('The certificate could not be read.', $checkedAt);
        }

        return ['ok' => true, 'trusted' => $trusted, 'reason' => $reason] + $parsed + ['checkedAt' => $checkedAt];
    }

    /**
     * One TCP connect plus TLS handshake. Warnings are captured, never shown. No HTTP request is sent.
     *
     * @param   string   $host     Host name or IP address
     * @param   integer  $port     TCP port
     * @param   integer  $timeout  Seconds
     * @param   boolean  $verify   Whether to verify the peer and its name
     *
     * @return  array  ['connected' => bool, 'certificate' => ?\OpenSSLCertificate, 'error' => string]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function handshake(string $host, int $port, int $timeout, bool $verify): array
    {
        $error = '';

        set_error_handler(static function (int $errno, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer'       => $verify,
                    'verify_peer_name'  => $verify,
                    'allow_self_signed' => !$verify,
                    'peer_name'         => $host,
                    'SNI_enabled'       => true,
                ],
            ]);

            $address = 'tcp://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
            $stream  = stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

            if ($stream === false) {
                return ['connected' => false, 'certificate' => null, 'error' => $errstr !== '' ? $errstr : $error];
            }

            stream_set_timeout($stream, $timeout);

            $certificate = null;

            if (stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) === true) {
                $certificate = stream_context_get_options($stream)['ssl']['peer_certificate'] ?? null;
            }

            fclose($stream);

            return ['connected' => true, 'certificate' => $certificate, 'error' => $error];
        } finally {
            restore_error_handler();
        }
    }
}
