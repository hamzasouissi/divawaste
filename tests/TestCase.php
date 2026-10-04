<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * migrate:fresh costs minutes of DDL on MariaDB, so the schema is rebuilt only when the migration files
     * changed, the schema is incomplete, or a previous run left committed rows behind. TEST_DB_FRESH=1 forces it.
     */
    protected function refreshTestDatabase()
    {
        if (! RefreshDatabaseState::$migrated) {
            if (! $this->canReuseSchema()) {
                $this->migrateDatabases();
                $this->app[Kernel::class]->setArtisan(null);
                $this->writeSchemaFingerprint();
            }

            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }

    private function canReuseSchema(): bool
    {
        if (env('TEST_DB_FRESH')) {
            return false;
        }

        $path = $this->schemaFingerprintPath();
        if (! is_file($path) || trim((string) file_get_contents($path)) !== $this->schemaFingerprint()) {
            return false;
        }

        try {
            return DB::table('migrations')->count() === count($this->migrationFiles())
                && ! DB::table('users')->exists()
                && ! DB::table('companies')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function writeSchemaFingerprint(): void
    {
        $path = $this->schemaFingerprintPath();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $this->schemaFingerprint());
    }

    private function schemaFingerprint(): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, (string) config('database.connections.'.config('database.default').'.database'));
        foreach ([...$this->migrationFiles(), app_path('Modules/Common/Database/BlueprintMacros.php')] as $file) {
            hash_update($hash, basename($file).':'.hash_file('sha256', $file));
        }

        return hash_final($hash);
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files);

        return $files;
    }

    private function schemaFingerprintPath(): string
    {
        return storage_path('framework/testing/schema.fingerprint');
    }
}
