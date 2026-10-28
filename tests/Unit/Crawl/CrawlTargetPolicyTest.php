<?php

namespace Tests\Unit\Crawl;

use App\Domain\Crawl\CrawlRefusal;
use App\Domain\Crawl\CrawlTargetPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CrawlTargetPolicyTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function nonPublicAddresses(): array
    {
        return array_combine($list = [
            '127.0.0.1', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254',
            '100.64.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255', '198.18.0.1', '192.0.2.10',
            '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '2001:db8::1',
            '::ffff:127.0.0.1', '::ffff:169.254.169.254', '64:ff9b::a00:1', '2002:7f00:1::1', '::127.0.0.1',
        ], array_map(static fn (string $address): array => [$address], $list));
    }

    #[DataProvider('nonPublicAddresses')]
    public function test_non_public_addresses_are_never_connectable(string $address): void
    {
        $this->assertFalse(CrawlTargetPolicy::isPublicAddress($address));
    }

    public function test_public_unicast_addresses_are_connectable(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '172.32.0.1', '2606:4700:4700::1111', '::ffff:93.184.216.34'] as $address) {
            $this->assertTrue(CrawlTargetPolicy::isPublicAddress($address), $address);
        }
    }

    public function test_only_allowlisted_hosts_are_in_scope_and_ports_are_part_of_the_authority(): void
    {
        $policy = new CrawlTargetPolicy(['example.com', 'shop.example.com:8443']);

        $this->assertTrue($policy->isInScope('https://example.com/a'));
        $this->assertTrue($policy->isInScope('https://shop.example.com:8443/'));
        $this->assertFalse($policy->isInScope('https://shop.example.com/'));
        $this->assertFalse($policy->isInScope('https://example.com.evil.test/'));
        $this->assertFalse($policy->isInScope('https://www.example.com/'));
    }

    public function test_connection_is_pinned_to_resolved_public_addresses(): void
    {
        $policy = new CrawlTargetPolicy(['example.com'], resolver: static fn (): array => ['93.184.216.34']);

        $this->assertSame(['93.184.216.34'], $policy->connectableAddresses('https://example.com/page'));
    }

    /** @return array<string, array{string, list<string>, string}> */
    public static function refusals(): array
    {
        return [
            'out of scope host' => ['https://other.test/', ['93.184.216.34'], 'out_of_scope'],
            'credentials' => ['https://user:pass@example.com/', ['93.184.216.34'], 'invalid_url'],
            'non-http scheme' => ['file:///etc/passwd', [], 'invalid_url'],
            'unresolvable' => ['https://example.com/', [], 'dns_failed'],
            'rebinding to loopback' => ['https://example.com/', ['127.0.0.1'], 'private_network'],
            'one private answer refuses all' => ['https://example.com/', ['93.184.216.34', '10.0.0.5'], 'private_network'],
            'metadata service' => ['https://example.com/', ['169.254.169.254'], 'private_network'],
            'garbage resolver answer' => ['https://example.com/', ['not-an-ip'], 'dns_failed'],
        ];
    }

    /** @param  list<string>  $answers */
    #[DataProvider('refusals')]
    public function test_unsafe_targets_are_refused_with_a_stable_code(string $url, array $answers, string $code): void
    {
        $policy = new CrawlTargetPolicy(['example.com'], resolver: static fn (): array => $answers);

        try {
            $policy->connectableAddresses($url);
            $this->fail('The target must be refused.');
        } catch (CrawlRefusal $refusal) {
            $this->assertSame($code, $refusal->reason);
        }
    }

    public function test_literal_private_ip_hosts_are_refused_without_resolving(): void
    {
        $policy = new CrawlTargetPolicy(['127.0.0.1:8080', '[::1]'], resolver: function (): array {
            $this->fail('Literal addresses must not be resolved.');
        });

        foreach (['http://127.0.0.1:8080/', 'http://[::1]/'] as $url) {
            try {
                $policy->connectableAddresses($url);
                $this->fail("{$url} must be refused.");
            } catch (CrawlRefusal $refusal) {
                $this->assertSame('private_network', $refusal->reason);
            }
        }
    }

    public function test_operator_may_allow_private_networks_for_intranet_sites(): void
    {
        $policy = new CrawlTargetPolicy(['intranet.local'], allowPrivateNetworks: true, resolver: static fn (): array => ['10.0.0.8']);

        $this->assertSame(['10.0.0.8'], $policy->connectableAddresses('http://intranet.local/'));
    }
}
