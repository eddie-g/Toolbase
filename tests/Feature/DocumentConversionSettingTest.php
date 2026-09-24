<?php

namespace Tests\Feature;

use App\Models\DocumentConversionSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentConversionSettingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('document_conversion_settings', function (Blueprint $table) {
            $table->id();
            $table->string('word_provider', 32)->default('local');
            $table->string('excel_provider', 32)->default('local');
            $table->boolean('fallback_to_local')->default(true);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('document_conversion_settings');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_default_conversion_providers_are_adobe_without_a_local_fallback(): void
    {
        // Since 2026_08_10_010000_use_adobe_only_for_document_conversions.
        $settings = DocumentConversionSetting::current();

        $this->assertSame(DocumentConversionSetting::PROVIDER_ADOBE, $settings->word_provider);
        $this->assertSame(DocumentConversionSetting::PROVIDER_ADOBE, $settings->excel_provider);
        $this->assertFalse($settings->fallback_to_local);
    }

    public function test_admin_provider_choices_are_persisted(): void
    {
        DocumentConversionSetting::current()->update([
            'word_provider' => DocumentConversionSetting::PROVIDER_ADOBE,
            'excel_provider' => DocumentConversionSetting::PROVIDER_ADOBE,
            'fallback_to_local' => false,
        ]);

        $settings = DocumentConversionSetting::current()->fresh();

        $this->assertSame(DocumentConversionSetting::PROVIDER_ADOBE, $settings->word_provider);
        $this->assertSame(DocumentConversionSetting::PROVIDER_ADOBE, $settings->excel_provider);
        $this->assertFalse($settings->fallback_to_local);
        $this->assertSame(1, DocumentConversionSetting::query()->count());
    }
}
