<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentProcessing;
use App\Services\DocumentRemoval;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Accounts and documents for the k6 scenarios in tests/Load: loadtest+<n>@netkit.test,
 * verified, one password for all, each owning one copy of a small real PDF.
 * Never in production: these are accounts with a known password.
 */
class SeedLoadTest extends Command
{
    protected $signature = 'load:seed
        {--users=50 : How many accounts}
        {--password=load-test-password-2026 : Their password}
        {--remove : Remove the accounts and their documents instead}';

    protected $description = 'Create (or remove) the accounts and documents the load tests in tests/Load sign in with';

    public function handle(DocumentRemoval $removal, DocumentProcessing $processing): int
    {
        if (app()->environment('production')) {
            $this->error('Not in production: these are accounts with a known password.');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $users = User::query()->where('email', 'like', 'loadtest+%@netkit.test')->get();
            foreach ($users as $user) {
                Document::withTrashed()->where('user_id', $user->id)->get()->each(fn (Document $document) => $removal->purge($document));
                $user->forceDelete();
            }
            $this->info("Removed {$users->count()} load-test accounts and their documents.");

            return self::SUCCESS;
        }

        // Under resources/ so that it is in the production image (tests/ is not).
        $fixture = resource_path('load-tests/invoice.pdf');
        $count = max(1, (int) $this->option('users'));
        // One hash for everyone: hashing per account would take minutes at production cost.
        $hash = Hash::make((string) $this->option('password'));
        $created = 0;

        for ($n = 1; $n <= $count; $n++) {
            $user = User::query()->firstOrNew(['email' => "loadtest+{$n}@netkit.test"]);
            $user->forceFill(['name' => "Load Test {$n}", 'password' => $hash, 'email_verified_at' => now()])->save();

            if (! Document::query()->where('user_id', $user->id)->exists()) {
                $path = 'documents/'.Str::uuid().'_load-test.pdf';
                Storage::put($path, (string) file_get_contents($fixture));
                $document = Document::query()->create([
                    'user_id' => $user->id,
                    'original_name' => 'Load test invoice.pdf',
                    'path' => $path,
                    'mime_type' => 'application/pdf',
                    'size_bytes' => filesize($fixture),
                    'mode' => 'editor',
                ]);
                // Extracted like an upload, so the editor opens what a visitor's document opens.
                $processing->queue($document, $user->email);
                $created++;
            }
        }

        $this->info("{$count} accounts ready (loadtest+1..{$count}@netkit.test), {$created} documents created.");

        return self::SUCCESS;
    }
}
