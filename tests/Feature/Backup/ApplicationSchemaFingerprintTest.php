<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\ApplicationTableCatalog;
use App\Services\Backup\BackupException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApplicationSchemaFingerprintTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_migrated_schema_has_no_uncatalogued_application_tables(): void
    {
        $catalog = new ApplicationTableCatalog;

        $catalog->assertNoDrift(DB::connection());

        $this->addToAssertionCount(1);
    }

    public function test_unknown_application_table_fails_closed_instead_of_disappearing_from_backup(): void
    {
        Schema::create('future_durable_records', function (Blueprint $table): void {
            $table->id();
            $table->string('value');
        });

        try {
            (new ApplicationTableCatalog)->assertNoDrift(DB::connection());
            $this->fail('Unknown application table was silently ignored by the backup catalog.');
        } catch (BackupException $exception) {
            $this->assertSame('backup_catalog_drift', $exception->codeName());
        }
    }

    public function test_schema_fingerprint_is_deterministic_and_changes_when_durable_schema_changes(): void
    {
        $fingerprint = new ApplicationSchemaFingerprint(new ApplicationTableCatalog);

        $before = $fingerprint->forConnection(DB::connection());
        $again = $fingerprint->forConnection(DB::connection());

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $before);
        $this->assertSame($before, $again);

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('future_backup_field')->nullable();
        });

        $after = $fingerprint->forConnection(DB::connection());

        $this->assertNotSame($before, $after);
    }
}
