<?php

namespace App\Domain\Crawl;

use App\Domain\Pages\NormalizePageUrl;
use Closure;
use InvalidArgumentException;

/**
 * Decides what the crawler may connect to. Only allowlisted hosts are
 * fetched, and only when every address they resolve to is a public unicast
 * address (unless the operator explicitly allows private networks). The
 * caller must connect to the returned addresses, never re-resolve the name.
 */
final class CrawlTargetPolicy
{
    /** Non-public IPv4 ranges (IANA special-purpose registry, RFC 6890 et al.). */
    private const BLOCKED_IPV4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    /** Non-public IPv6 ranges; embedded IPv4 forms are classified separately. */
    private const BLOCKED_IPV6 = [
        '::/128', '::1/128', '100::/64', '2001::/23', '2001:db8::/32', 'fc00::/7',
        'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** @var list<string> */
    private array $allowedAuthorities;

    /**
     * @param  list<string>  $allowedAuthorities  normalized `host[:port]` values
     * @param  (Closure(string): list<string>)|null  $resolver  host to IP addresses
     */
    public function __construct(
        array $allowedAuthorities,
        private readonly bool $allowPrivateNetworks = false,
        private readonly ?Closure $resolver = null,
    ) {
        $this->allowedAuthorities = array_values(array_unique(array_map('strtolower', $allowedAuthorities)));
    }

    /** Host authority (`host[:port]`) of a URL normalized by NormalizePageUrl. */
    public static function authority(string $normalizedUrl): string
    {
        $parts = parse_url($normalizedUrl);
        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new InvalidArgumentException('Invalid normalized URL.');
        }

        return strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function isInScope(string $normalizedUrl): bool
    {
        try {
            return in_array(self::authority($normalizedUrl), $this->allowedAuthorities, true);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return list<string> the addresses the connection must be pinned to
     *
     * @throws CrawlRefusal
     */
    public function connectableAddresses(string $url): array
    {
        try {
            $normalized = (new NormalizePageUrl)->normalize($url);
        } catch (InvalidArgumentException) {
            throw new CrawlRefusal('invalid_url');
        }
        if (! $this->isInScope($normalized)) {
            throw new CrawlRefusal('out_of_scope');
        }

        $host = (string) parse_url($normalized, PHP_URL_HOST);
        $literal = trim($host, '[]');
        $addresses = filter_var($literal, FILTER_VALIDATE_IP) !== false
            ? [$literal]
            : ($this->resolver ?? self::systemResolver(...))($host);
        if ($addresses === []) {
            throw new CrawlRefusal('dns_failed');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new CrawlRefusal('dns_failed');
            }
            // Fail closed: one non-public answer refuses the whole host, so a
            // round-robin or rebinding answer cannot reach an internal service.
            if (! $this->allowPrivateNetworks && ! self::isPublicAddress($address)) {
                throw new CrawlRefusal('private_network');
            }
        }

        return $addresses;
    }

    public static function isPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return ! self::inAnyRange($packed, self::BLOCKED_IPV4);
        }

        $embedded = self::embeddedIpv4($packed);
        if ($embedded !== null) {
            return ! self::inAnyRange($embedded, self::BLOCKED_IPV4);
        }

        return ! self::inAnyRange($packed, self::BLOCKED_IPV6);
    }

    /** IPv4 carried by IPv4-mapped/compatible, NAT64 or 6to4 IPv6 forms. */
    private static function embeddedIpv4(string $packed): ?string
    {
        $prefix12 = substr($packed, 0, 12);
        if ($prefix12 === str_repeat("\0", 10)."\xff\xff" || $prefix12 === str_repeat("\0", 12)) {
            return substr($packed, 12, 4);
        }
        if (substr($packed, 0, 12) === "\x00\x64\xff\x9b".str_repeat("\0", 8)) {
            return substr($packed, 12, 4);
        }
        if (substr($packed, 0, 2) === "\x20\x02") {
            return substr($packed, 2, 4);
        }

        return null;
    }

    /** @param  list<string>  $ranges */
    private static function inAnyRange(string $packed, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range);
            $networkPacked = inet_pton($network);
            if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($networkPacked, 0, $bytes)) {
                continue;
            }
            $remainder = $bits % 8;
            if ($remainder === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $remainder)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($networkPacked[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function systemResolver(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
