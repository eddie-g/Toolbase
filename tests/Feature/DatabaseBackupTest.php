<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DatabaseBackups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * db:backup and the restore drill, against the real MySQL test database with
 * the real mysqldump and mysql clients.
 */
class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * No wrapping transaction: mysqldump is another connection and only sees
     * what is committed. The rows this test writes are removed in tearDown.
     */
    protected $connectionsToTransact = [];

    private ?Collection $users = null;

    protected function tearDown(): void
    {
        $this->users?->each->forceDelete();

        parent::tearDown();
    }

    public function test_a_backup_is_written_with_a_manifest_pruned_by_age_and_restores(): void
    {
        Storage::fake('local');
        config(['backup.disk' => 'local', 'backup.keep_days' => 14, 'backup.keep_minimum' => 1, 'backup.verify_tables' => ['users', 'documents']]);
        $this->users = User::factory()->count(3)->create();
        $userCount = User::count();
        $backups = app(DatabaseBackups::class);

        // An old backup, and an upload that never finished (no manifest).
        Storage::disk('local')->put('backups/database/db-old.sql.gz', 'old');
        Storage::disk('local')->put('backups/database/db-old.json', json_encode(['file' => 'db-old.sql.gz', 'created_at' => now()->subDays(30)->toIso8601String()]));
        Storage::disk('local')->put('backups/database/db-partial.sql.gz', 'partial');

        $this->artisan('db:backup')->assertExitCode(0);

        $all = $backups->all();
        $this->assertCount(1, $all, 'the old one is gone, the partial one never counted');
        $manifest = $all[0]['manifest'];
        $this->assertSame($userCount, $manifest['row_counts']['users']);
        $this->assertContains('documents', $manifest['tables']);
        $this->assertSame(hash('sha256', Storage::disk('local')->get('backups/database/'.$manifest['file'])), $manifest['sha256']);
        $this->assertFalse(Storage::disk('local')->exists('backups/database/db-old.sql.gz'));
        $this->assertSame([], glob(sys_get_temp_dir().'/netkit-backups/*'), 'no dump and no password file is left behind');
        $this->assertNull($backups->staleness());

        try {
            $result = $backups->drill();
        } catch (\Illuminate\Database\QueryException $e) {
            $this->markTestSkipped('The test database user may not create the drill database: '.$e->getMessage());
        }
        $this->assertSame([], $result['problems']);
        $this->assertSame(['backup' => $userCount, 'restored' => $userCount], $result['row_counts']['users']);
        $this->assertSame(count($manifest['tables']), $result['tables']);
        $this->assertEmpty(\DB::select('select schema_name from information_schema.schemata where schema_name = ?', [\DB::getDatabaseName().'_restore_drill']), 'the scratch database is dropped');

        // A backup that lost a table does not pass.
        $manifest['tables'][] = 'a_table_that_was_not_dumped';
        Storage::disk('local')->put($all[0]['manifest_path'], json_encode($manifest));
        $this->assertStringContainsString('a_table_that_was_not_dumped', implode(' ', $backups->drill()['problems']));

        config(['backup.drill_database' => \DB::getDatabaseName()]);
        $this->expectExceptionMessage('must end in "_restore_drill"');
        $backups->drill();
    }
}
