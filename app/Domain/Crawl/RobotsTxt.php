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
        /** @var list<list<string>> $agents */
        $agents = [];
        /** @var list<list<array{allow: bool, pattern: string}>> $groupRules */
        $groupRules = [];
        /** @var list<?int> $groupDelays */
        $groupDelays = [];
        $current = -1;
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
                    $agents[] = [];
                    $groupRules[] = [];
                    $groupDelays[] = null;
                    $current = count($agents) - 1;
                }
                $agents[$current][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }
            $lastWasAgent = false;
            if ($current < 0) {
                continue;
            }
            if ($field === 'allow' || $field === 'disallow') {
                if ($value !== '') {
                    $groupRules[$current][] = ['allow' => $field === 'allow', 'pattern' => $value];
                }
            } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                $groupDelays[$current] = (int) ceil((float) $value);
            }
        }

        $chosen = array_keys(array_filter($agents, static fn (array $names): bool => in_array($token, $names, true)));
        if ($chosen === []) {
            $chosen = array_keys(array_filter($agents, static fn (array $names): bool => in_array('*', $names, true)));
        }
        if ($chosen === []) {
            return self::allowAll();
        }

        $rules = [];
        $delays = [];
        foreach ($chosen as $index) {
            $rules = [...$rules, ...$groupRules[$index]];
            if ($groupDelays[$index] !== null) {
                $delays[] = $groupDelays[$index];
            }
        }

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
