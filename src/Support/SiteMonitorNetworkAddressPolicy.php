<?php

declare(strict_types=1);

namespace Capell\SiteMonitor\Support;

/**
 * The single public-address rule for every site monitor outbound request.
 *
 * PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE misses shared
 * address space (100.64.0.0/10), benchmarking, NAT64 and other transition
 * prefixes that translate to internal IPv4 hosts, so the ranges are listed
 * explicitly. Any IPv6 prefix that embeds or tunnels IPv4 is refused outright.
 */
final class SiteMonitorNetworkAddressPolicy
{
    /** @var list<string> */
    private const array NON_PUBLIC_RANGES = [
        // IPv4 (RFC 6890 special-purpose registry and multicast/reserved space).
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.31.196.0/24',
        '192.52.193.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '192.175.48.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        // IPv6: unspecified, loopback, IPv4-mapped, IPv4-compatible and SIIT.
        '::/8',
        // NAT64 well-known and local-use prefixes.
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        // Discard-only.
        '100::/64',
        // IETF protocol assignments, including Teredo and ORCHID.
        '2001::/23',
        // Documentation.
        '2001:db8::/32',
        '3fff::/20',
        // 6to4 relays embed an arbitrary IPv4 address.
        '2002::/16',
        // Unique local, deprecated site-local, link-local and multicast.
        'fc00::/7',
        'fec0::/10',
        'fe80::/10',
        'ff00::/8',
    ];

    public static function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        foreach (self::NON_PUBLIC_RANGES as $range) {
            if (self::inRange($packed, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $packedAddress, string $range): bool
    {
        [$network, $prefixLength] = explode('/', $range, 2);
        $packedNetwork = inet_pton($network);

        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedAddress)) {
            return false;
        }

        $bits = (int) $prefixLength;
        $wholeBytes = intdiv($bits, 8);

        if (strncmp($packedAddress, $packedNetwork, $wholeBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits % 8;

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedAddress[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}
