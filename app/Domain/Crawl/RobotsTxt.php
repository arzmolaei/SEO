<?php

namespace App\Domain\Crawl;

/**
 * robots.txt rules for one host (RFC 9309): the most specific matching
 * user-agent group applies, the longest matching path rule wins and Allow
 * wins ties. `*` and `$` are supported. Unparseable input allows everything.
 */
final class RobotsTxt
{
    public const MAX_BYTES = 524_288;

    /** @param  list<array{allow: bool, pattern: string}>  $rules */
    private function __construct(
        private readonly array $rules,
        private readonly ?int $crawlDelaySeconds,
    ) {}

    public static function allowAll(): self
    {
        return new self([], null);
    }

    public static function parse(string $body, string $userAgent): self
    {
        $body = substr($body, 0, self::MAX_BYTES);
        $token = strtolower((string) preg_replace('#/.*\z#', '', $userAgent));
        $groups = [];
        $current = null;
        $lastWasAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*\z/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if (! $lastWasAgent) {
                    $groups[] = ['agents' => [], 'rules' => [], 'delay' => null];
                    $current = array_key_last($groups);
                }
                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }
            $lastWasAgent = false;
            if ($current === null) {
                continue;
            }
            if ($field === 'allow' || $field === 'disallow') {
                if ($value !== '') {
                    $groups[$current]['rules'][] = ['allow' => $field === 'allow', 'pattern' => $value];
                }
            } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                $groups[$current]['delay'] = (int) ceil((float) $value);
            }
        }

        $specific = array_values(array_filter($groups, static fn (array $group): bool => in_array($token, $group['agents'], true)));
        $chosen = $specific !== [] ? $specific : array_values(array_filter($groups, static fn (array $group): bool => in_array('*', $group['agents'], true)));
        if ($chosen === []) {
            return self::allowAll();
        }

        $rules = array_merge(...array_map(static fn (array $group): array => $group['rules'], $chosen));
        $delays = array_filter(array_map(static fn (array $group): ?int => $group['delay'], $chosen), static fn (?int $delay): bool => $delay !== null);

        return new self($rules, $delays === [] ? null : max(0, min(10, max($delays))));
    }

    /** @param  string  $pathAndQuery  e.g. `/a/b?x=1` */
    public function allows(string $pathAndQuery): bool
    {
        if ($pathAndQuery === '/robots.txt') {
            return true;
        }
        $best = null;
        foreach ($this->rules as $rule) {
            if (! self::matches($rule['pattern'], $pathAndQuery)) {
                continue;
            }
            $length = strlen($rule['pattern']);
            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null || $best['allow'];
    }

    public function crawlDelaySeconds(): ?int
    {
        return $this->crawlDelaySeconds;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $core = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex = '#\A'.implode('.*', array_map(static fn (string $part): string => preg_quote(self::decode($part), '#'), explode('*', $core))).($anchored ? '\z' : '').'#s';

        return preg_match($regex, self::decode($path)) === 1;
    }

    /** Compare percent-encoded and literal forms equally (except encoded `/`). */
    private static function decode(string $value): string
    {
        return (string) preg_replace_callback('/%([0-9a-fA-F]{2})/', static fn (array $m): string => strtolower($m[1]) === '2f' ? '%2F' : chr((int) hexdec($m[1])), $value);
    }
}
