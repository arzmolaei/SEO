<?php

namespace Tests\Feature\System;

use App\Support\Installer\DatabaseVerifier;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class InstallerDatabaseTest extends TestCase
{
    public function test_successful_verification_returns_non_secret_fingerprint_and_facts(): void
    {
        $connection = $this->connection();
        $observed = null;
        $verifier = new DatabaseVerifier(
            probe: static function (array $candidate) use (&$observed): void {
                $observed = $candidate;
            },
            extensionLoaded: static fn (string $extension): bool => $extension === 'pdo_mysql',
        );

        $result = $verifier->verify($connection);

        $this->assertTrue($result->verified);
        $this->assertNull($result->code);
        $this->assertNotNull($result->fingerprint);
        $this->assertSame([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'seo_os',
        ], $result->facts);
        $this->assertSame($connection, $observed);
        $this->assertSame(
            $result->fingerprint,
            $verifier->verify([...$connection, 'username' => 'other-user', 'password' => 'other-secret'])->fingerprint,
        );

        $serialized = json_encode([
            'message' => $result->message,
            'fingerprint' => $result->fingerprint,
            'facts' => $result->facts,
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('seo_user', $serialized);
        $this->assertStringNotContainsString('super-secret-password', $serialized);
    }

    public function test_mariadb_uses_mysql_pdo_extension_and_keeps_driver_identity(): void
    {
        $extensions = [];
        $verifier = new DatabaseVerifier(
            probe: static fn (array $candidate): null => null,
            extensionLoaded: static function (string $extension) use (&$extensions): bool {
                $extensions[] = $extension;

                return true;
            },
        );

        $result = $verifier->verify([
            ...$this->connection(),
            'driver' => 'mariadb',
        ]);

        $this->assertTrue($result->verified);
        $this->assertSame(['pdo_mysql'], $extensions);
        $this->assertSame('mariadb', $result->facts['driver']);
    }

    public function test_missing_pdo_driver_is_classified_without_attempting_connection(): void
    {
        $probeCalled = false;
        $verifier = new DatabaseVerifier(
            probe: static function (array $candidate) use (&$probeCalled): void {
                $probeCalled = true;
            },
            extensionLoaded: static fn (string $extension): bool => false,
        );

        $result = $verifier->verify($this->connection());

        $this->assertFalse($result->verified);
        $this->assertSame('missing_driver', $result->code);
        $this->assertFalse($probeCalled);
        $this->assertNull($result->fingerprint);
    }

    public function test_common_mysql_failures_are_mapped_to_stable_sanitized_codes(): void
    {
        $cases = [
            'authentication_failed' => ['28000', 1045, 'Access denied for user seo_user using password super-secret-password'],
            'database_missing' => ['42000', 1049, "Unknown database 'seo_os'"],
            'insufficient_privileges' => ['42000', 1044, "Access denied for user 'seo_user' to database 'seo_os'"],
            'unreachable' => ['HY000', 2002, 'Connection refused to 127.0.0.1'],
        ];

        foreach ($cases as $expectedCode => $errorInfo) {
            $exception = new PDOException('raw secret super-secret-password');
            $exception->errorInfo = $errorInfo;
            $verifier = new DatabaseVerifier(
                probe: static function (array $candidate) use ($exception): void {
                    throw $exception;
                },
                extensionLoaded: static fn (string $extension): bool => true,
            );

            $result = $verifier->verify($this->connection());

            $this->assertFalse($result->verified, $expectedCode);
            $this->assertSame($expectedCode, $result->code, $expectedCode);
            $this->assertNull($result->fingerprint, $expectedCode);
            $this->assertStringNotContainsString('super-secret-password', $result->message, $expectedCode);
            $this->assertStringNotContainsString('seo_user', $result->message, $expectedCode);
        }
    }

    public function test_unknown_probe_failure_never_returns_raw_exception_text(): void
    {
        $verifier = new DatabaseVerifier(
            probe: static function (array $candidate): void {
                throw new RuntimeException('unexpected super-secret-password seo_user');
            },
            extensionLoaded: static fn (string $extension): bool => true,
        );

        $result = $verifier->verify($this->connection());

        $this->assertFalse($result->verified);
        $this->assertSame('unknown', $result->code);
        $this->assertSame('Database verification failed unexpectedly.', $result->message);
        $this->assertStringNotContainsString('super-secret-password', $result->message);
        $this->assertStringNotContainsString('seo_user', $result->message);
    }

    public function test_unsupported_driver_is_rejected_before_probe(): void
    {
        $probeCalled = false;
        $verifier = new DatabaseVerifier(
            probe: static function (array $candidate) use (&$probeCalled): void {
                $probeCalled = true;
            },
            extensionLoaded: static fn (string $extension): bool => true,
        );

        $result = $verifier->verify([
            ...$this->connection(),
            'driver' => 'pgsql',
        ]);

        $this->assertFalse($result->verified);
        $this->assertSame('unsupported_driver', $result->code);
        $this->assertFalse($probeCalled);
    }

    /** @return array{driver: string, host: string, port: int, database: string, username: string, password: string} */
    private function connection(): array
    {
        return [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'seo_os',
            'username' => 'seo_user',
            'password' => 'super-secret-password',
        ];
    }
}
