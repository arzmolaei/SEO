<?php

namespace App\Domain\Pages;

use InvalidArgumentException;
use ValueError;

final class NormalizePageUrl
{
    public function normalize(string $url): string
    {
        if ($url === '' || strlen($url) > 8192 || preg_match('/[\p{C}\p{Z}\s]/u', $url) !== 0 || preg_match('/%(?![a-fA-F0-9]{2})/', $url)) {
            throw new InvalidArgumentException('Invalid page URL.');
        }
        try {
            $parts = parse_url($url);
        } catch (ValueError) {
            throw new InvalidArgumentException('Invalid page URL.');
        }
        if (! is_array($parts) || ! isset($parts['scheme'],$parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('HTTP URL without credentials required.');
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('HTTP or HTTPS required.');
        }
        $host = strtolower($parts['host']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            if (filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('Invalid host.');
            }
        } else {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false || filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new InvalidArgumentException('Invalid host.');
            }
            $host = $ascii;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1)) {
            throw new InvalidArgumentException('Invalid port.');
        }
        $portText = $port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':'.$port;

        return $scheme.'://'.$host.$portText.($parts['path'] ?? '/').(array_key_exists('query', $parts) ? '?'.$parts['query'] : '');
    }
}
