<?php

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentConversionAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamp('deleted_at')->nullable(); // Document uses SoftDeletes (trash)
            $table->timestamps();
        });
    }

    public function test_guest_cannot_convert_a_pdf_to_word(): void
    {
        $document = $this->document();

        $this->postJson(route('documents.convertToWord', $document), [
            'layout' => 'exact',
        ])->assertUnauthorized();
    }

    public function test_guest_cannot_convert_a_pdf_to_excel(): void
    {
        $document = $this->document();

        $this->postJson(route('documents.convertToExcel', $document), [
            'mode' => 'all',
        ])->assertUnauthorized();
    }

    private function document(): Document
    {
        return Document::query()->create([
            'original_name' => 'guest.pdf',
            'path' => 'documents/guest.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);
    }
}
